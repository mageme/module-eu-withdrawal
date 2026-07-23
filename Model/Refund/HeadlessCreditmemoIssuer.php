<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Refund;

use MageMe\EUWithdrawal\Api\CreditmemoIssuerInterface;
use MageMe\EUWithdrawal\Api\Data\CreditmemoIssueResultInterface;
use MageMe\EUWithdrawal\Api\RequestRepositoryInterface;
use MageMe\EUWithdrawal\Model\Refund\Source\IssueOutcome;
use Magento\Framework\App\ResourceConnection;
use Magento\Sales\Api\CreditmemoManagementInterface;
use Magento\Sales\Api\Data\CreditmemoCreationArgumentsInterfaceFactory;
use Magento\Sales\Api\Data\CreditmemoItemCreationInterfaceFactory;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\InvoiceRepositoryInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\RefundInvoiceInterface;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\CreditmemoDocumentFactory;
use Psr\Log\LoggerInterface;

/**
 * Issues a withdrawal request's refund as a credit memo without any admin
 * session in play. Builds the plan, previews the memo against the frozen
 * entitlement as a pre-commit gate, then commits it - online through core's
 * RefundInvoice, offline through CreditmemoManagement - and links the result
 * back onto the request.
 */
class HeadlessCreditmemoIssuer implements CreditmemoIssuerInterface
{
    public function __construct(
        private readonly RequestRepositoryInterface $requestRepository,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly InvoiceRepositoryInterface $invoiceRepository,
        private readonly CreditmemoPlanBuilder $planBuilder,
        private readonly CreditmemoDocumentFactory $creditmemoDocumentFactory,
        private readonly RefundInvoiceInterface $refundInvoice,
        private readonly CreditmemoManagementInterface $creditmemoManagement,
        private readonly CreditmemoItemCreationInterfaceFactory $itemCreationFactory,
        private readonly CreditmemoCreationArgumentsInterfaceFactory $argsFactory,
        private readonly ResourceConnection $resourceConnection,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function issue(int $requestId, bool $online): CreditmemoIssueResultInterface
    {
        $request = $this->requestRepository->get($requestId);
        if ($request->getRefundCreditmemoId() !== null) {
            return new CreditmemoIssueResult(IssueOutcome::ALREADY_DONE, $request->getRefundCreditmemoId());
        }
        // A manual paid mark means the refund was already issued outside the request
        // (offline/bank/external). Never auto-issue a memo on top of it; leave it for
        // a human to confirm rather than paying the customer twice.
        if ($request->getReimbursementPaidAt() !== null) {
            return new CreditmemoIssueResult(IssueOutcome::ROUTED_TO_MANUAL, null, 'already reimbursed manually');
        }

        $order = $this->orderRepository->get($request->getOrderId());
        // Core's CreditmemoDocumentFactory rebinds the invoice onto this same
        // repository-cached order further down; its item collection must be
        // keyed by item id for the memo build to resolve items by id.
        $order->setData('items', $order->getItemsCollection()->getItems());
        $plan = $this->planBuilder->build($request, $order);
        if (!$plan->isAutoIssuable()) {
            return new CreditmemoIssueResult(IssueOutcome::ROUTED_TO_MANUAL, null, $plan->manualReason);
        }

        if ($this->existingMemoCoversWithdrawnItems($order, $plan->items)) {
            $this->logger->warning('EUWithdrawal auto-memo found existing memo on order; routing to manual', [
                'request_id' => $requestId,
            ]);
            return new CreditmemoIssueResult(IssueOutcome::ROUTED_TO_MANUAL, null, 'existing memo on order');
        }

        if (!$this->claimRequestRow($requestId)) {
            $fresh = $this->requestRepository->get($requestId);
            return new CreditmemoIssueResult(IssueOutcome::ALREADY_DONE, $fresh->getRefundCreditmemoId());
        }

        $items = [];
        foreach ($plan->items as $oid => $qty) {
            $ci = $this->itemCreationFactory->create();
            $ci->setOrderItemId((int) $oid);
            $ci->setQty((float) $qty);
            $items[] = $ci;
        }
        $args = $this->argsFactory->create();
        if ($plan->shippingAmount !== null) {
            $args->setShippingAmount($plan->shippingAmount);
        }

        try {
            $invoice = $this->invoiceRepository->get($plan->invoiceId);
            // Invoice item quantities come back from storage as strings; core's
            // own memo-quantity resolution mixes them with float order quantities
            // via min(), which does not normalize the result type.
            foreach ($invoice->getAllItems() as $invoiceItem) {
                $invoiceItem->setQty((float) $invoiceItem->getQty());
            }
            $preview = $this->creditmemoDocumentFactory->createFromInvoice($invoice, $items, null, false, $args);
        } catch (\Throwable $e) {
            $this->logger->warning('EUWithdrawal auto-memo preview build failed; routing to manual', [
                'request_id' => $requestId,
                'exception' => $e->getMessage(),
            ]);
            return new CreditmemoIssueResult(IssueOutcome::ROUTED_TO_MANUAL, null, 'memo build failed');
        }

        // $plan->expectedTotal (frozen total_refund) is order currency, not base;
        // compare like for like here rather than against getBaseGrandTotal().
        $previewTotal = (float) $preview->getGrandTotal();
        if (abs($previewTotal - $plan->expectedTotal) > 0.01) {
            $this->logger->warning('EUWithdrawal auto-memo gate mismatch; routing to manual', [
                'request_id' => $requestId, 'preview' => $previewTotal, 'expected' => $plan->expectedTotal,
            ]);
            return new CreditmemoIssueResult(IssueOutcome::ROUTED_TO_MANUAL, null, 'gate mismatch');
        }

        // canRefund() is the ONLINE-refund capability flag: it reports whether the
        // method instance can send a refund to a gateway, and every built-in
        // offline tender leaves it false. It therefore gates the online lane only.
        $payment = $order->getPayment();
        if ($payment === null) {
            return new CreditmemoIssueResult(IssueOutcome::ROUTED_TO_MANUAL, null, 'refund not available');
        }
        if ($online && !$payment->canRefund()) {
            return new CreditmemoIssueResult(IssueOutcome::ROUTED_TO_MANUAL, null, 'online not available');
        }

        $creditmemoId = $online
            ? (int) $this->refundInvoice->execute($plan->invoiceId, $items, true, false, false, null, $args)
            : $this->commitOffline($preview);

        $fresh = $this->requestRepository->get($requestId);
        if ($fresh->getRefundCreditmemoId() === null) {
            $fresh->setRefundCreditmemoId($creditmemoId);
            $this->requestRepository->save($fresh);
        }
        return new CreditmemoIssueResult(IssueOutcome::ISSUED, $creditmemoId);
    }

    /**
     * Commits the already-previewed memo offline, moving no money.
     *
     * Core's RefundInvoiceInterface is not usable here: its validator chain runs
     * Invoice\Validation\CanRefund, which rejects any non-Free tender reporting
     * canRefund() === false irrespective of the online flag. CreditmemoManagement
     * is the API behind the Admin "Refund Offline" button and carries no such
     * check, so it is the only route that issues an offline memo on an offline
     * tender. It commits the very document the amount gate above approved.
     */
    private function commitOffline(Creditmemo $preview): int
    {
        $this->creditmemoManagement->refund($preview, true);

        return (int) $preview->getId();
    }

    /**
     * Re-reads the paid markers under FOR UPDATE in a short transaction, released
     * before the preview/execute below so the lock never spans core's own OrderMutex.
     * The row is claimable only while BOTH stay unset — a linked credit memo or a
     * manual reimbursement-paid mark recorded between issue()'s initial read and this
     * claim means the refund already went out, so the claim must fail.
     */
    private function claimRequestRow(int $requestId): bool
    {
        // Must run in its own transaction; issue() must never be wrapped in an outer
        // DB transaction or this commit() would only nest, holding the row lock across execute().
        $connection = $this->resourceConnection->getConnection();
        $connection->beginTransaction();
        try {
            $select = $connection->select()
                ->from(
                    $this->resourceConnection->getTableName('mm_eu_withdrawal_request'),
                    ['refund_creditmemo_id', 'reimbursement_paid_at'],
                )
                ->where('request_id = ?', $requestId)
                ->forUpdate(true);
            $row = $connection->fetchRow($select);
            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }

        return $row !== false
            && ($row['refund_creditmemo_id'] === null || $row['refund_creditmemo_id'] === false)
            && ($row['reimbursement_paid_at'] === null || $row['reimbursement_paid_at'] === false);
    }

    /**
     * Queried directly against the DB rather than via $order->getCreditmemosCollection():
     * OrderRepository caches loaded orders by id, and the collection memoizes itself
     * on first access, so a same-process retry against the cached order would see a
     * stale (pre-refund) empty collection instead of the memo the retry exists to detect.
     *
     * @param array<int,float> $oidQtys order_item_id => qty, the withdrawn set
     */
    private function existingMemoCoversWithdrawnItems(OrderInterface $order, array $oidQtys): bool
    {
        if ($oidQtys === []) {
            return false;
        }

        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from(['cm' => $this->resourceConnection->getTableName('sales_creditmemo')], [])
            ->join(
                ['cmi' => $this->resourceConnection->getTableName('sales_creditmemo_item')],
                'cmi.parent_id = cm.entity_id',
                ['order_item_id'],
            )
            ->where('cm.order_id = ?', (int) $order->getEntityId())
            ->where('cm.state != ?', Creditmemo::STATE_CANCELED)
            ->where('cmi.order_item_id IN (?)', array_keys($oidQtys))
            ->where('cmi.qty > ?', 0)
            ->limit(1);

        return $connection->fetchOne($select) !== false;
    }
}
