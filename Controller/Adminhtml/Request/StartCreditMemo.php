<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Controller\Adminhtml\Request;

use MageMe\EUWithdrawal\Api\Data\RequestInterface;
use MageMe\EUWithdrawal\Api\RequestRepositoryInterface;
use MageMe\EUWithdrawal\Model\Refund\CreditmemoPlanBuilder;
use MageMe\EUWithdrawal\Model\Refund\CreditmemoPrefill;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\Session as BackendSession;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\OrderRepositoryInterface;

/**
 * Pre-fills Magento's native "New Credit Memo" form with the items + qtys
 * from an approved withdrawal request, plus the order's outbound shipping
 * cost (Art. 13(2) CRD requires the merchant to refund the basic delivery
 * fee — admin can still tweak this on the credit memo screen). The merchant
 * lands on the standard credit memo page where they review, choose
 * online/offline refund, and submit. We don't auto-create or auto-refund;
 * the admin remains the decision point.
 *
 * The prefill data (covering invoice, expanded item qtys, shipping) is
 * computed by the shared CreditmemoPlanBuilder so the free admin flow and the
 * Pro auto path agree on invoice coverage and shipping treatment. Unlike the
 * auto path, the admin form is opened for every approved request — including
 * partial and multi-invoice cases, which are legitimate manual admin work.
 *
 * URL pattern: `mageme_eu_withdrawal/request/startCreditMemo/request_id/N`
 * Redirects to: `sales/order_creditmemo/new/order_id/X/invoice_id/Y/?creditmemo[...]`
 */
class StartCreditMemo extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'MageMe_EUWithdrawal::request_edit';

    public const SESSION_KEY = 'mageme_eu_withdrawal_pending_creditmemo_request_id';

    /**
     * Constructor.
     *
     * @param Context $context
     * @param RequestRepositoryInterface $requestRepository
     * @param OrderRepositoryInterface $orderRepository
     * @param BackendSession $backendSession
     * @param CreditmemoPlanBuilder $planBuilder
     */
    public function __construct(
        Context $context,
        private readonly RequestRepositoryInterface $requestRepository,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly BackendSession $backendSession,
        private readonly CreditmemoPlanBuilder $planBuilder,
    ) {
        parent::__construct($context);
    }

    /**
     * Execute.
     *
     * @return ResultInterface
     */
    public function execute(): ResultInterface
    {
        $redirect = $this->resultRedirectFactory->create();
        $id = (int) $this->getRequest()->getParam('request_id');

        try {
            $request = $this->requestRepository->get($id);
        } catch (NoSuchEntityException) {
            $this->messageManager->addErrorMessage((string) __('Request #%1 not found.', $id));
            return $redirect->setPath('*/request');
        }

        // A credit memo pays out real refund money, so it may only be started for
        // an approved request — never a pending, denied, cancelled or anonymised
        // one whose frozen figures must not be booked into a memo.
        if ($request->getStatus() !== RequestInterface::STATUS_APPROVED) {
            $this->messageManager->addErrorMessage(
                (string) __('A credit memo can only be started for an approved withdrawal request.'),
            );
            return $redirect->setPath('*/request/edit', ['request_id' => $id]);
        }

        // A manual paid mark records a refund already issued outside the request;
        // starting a credit memo now would refund the customer twice.
        if ($request->getReimbursementPaidAt() !== null) {
            $this->messageManager->addErrorMessage(
                (string) __(
                    'Request #%1 is already recorded as reimbursed (marked as refunded). Clear the refunded mark on the request first if you really need to issue a credit memo.',
                    $id,
                ),
            );
            return $redirect->setPath('*/request/edit', ['request_id' => $id]);
        }

        $orderId = (int) $request->getOrderId();
        try {
            $order = $this->orderRepository->get($orderId);
        } catch (NoSuchEntityException) {
            $this->messageManager->addErrorMessage((string) __('Underlying order #%1 not found.', $orderId));
            return $redirect->setPath('*/request/edit', ['request_id' => $id]);
        }

        $prefill = $this->planBuilder->resolvePrefill($request, $order);

        if ($prefill->invoiceId === null) {
            $this->messageManager->addErrorMessage(
                (string) __(
                    'Order #%1 has no invoice yet — Magento requires an invoice before a credit memo can be created. Open an invoice first (Sales → Orders → #%1 → Invoice button), then return here to refund.',
                    $order->getIncrementId(),
                )
            );
            return $redirect->setPath('*/request/edit', ['request_id' => $id]);
        }

        if ($prefill->items === []) {
            $this->messageManager->addNoticeMessage(
                (string) __('No items recorded on this withdrawal request — the credit memo will open with default invoice qtys; please adjust manually.')
            );
        }

        // A partial or split-invoice order is still refundable by hand — advise,
        // never block: the admin reviews the prefilled qtys before submitting.
        if (!$prefill->coverageClean) {
            $this->messageManager->addNoticeMessage(
                (string) __('No single invoice covers every withdrawn item on this order — the credit memo opened against one invoice; review the qtys before refunding.')
            );
        }

        $creditmemoData = $this->buildCreditmemoData($prefill);

        // Stash the request id so the link-back observer can connect the
        // saved credit memo back to this withdrawal request. Cleared on
        // either successful save or any other admin action that bypasses
        // the credit memo flow (the value is only read during creditmemo
        // save_after, so a stale value is harmless).
        $this->backendSession->setData(self::SESSION_KEY, $id);

        $this->messageManager->addSuccessMessage(
            (string) __(
                'Pre-filled credit memo for withdrawal #%1 — review the qtys + shipping refund below, then click Refund Online or Refund Offline.',
                $request->getIncrementId() ?? $id,
            )
        );

        return $redirect->setPath(
            'sales/order_creditmemo/new',
            [
                'order_id'   => $orderId,
                'invoice_id' => $prefill->invoiceId,
                // Magento's URL builder only serializes scalar route params into the
                // path and drops a nested array; the prefill rides in the query so
                // Creditmemo\Loader receives it.
                '_query'     => ['creditmemo' => $creditmemoData],
            ],
        );
    }

    /**
     * Build the `creditmemo` array Magento's `Order\Creditmemo\Loader` reads
     * from `$_request->getParam('creditmemo')` to pre-populate the New Credit
     * Memo form. Items carry back_to_stock=1 — this is the human-reviewed RMA
     * context where returned goods go back into inventory.
     *
     * @return array<string, mixed>
     */
    private function buildCreditmemoData(CreditmemoPrefill $prefill): array
    {
        $items = [];
        foreach ($prefill->items as $oid => $qty) {
            $items[(int) $oid] = [
                'qty' => $qty,
                'back_to_stock' => 1,
            ];
        }

        // Art. 13(2) — refund the basic outbound delivery on a full withdrawal.
        // When it is owed (shippingAmount === null) omit shipping_amount so
        // Magento's own credit memo refunds the full remaining carriage — its
        // Shipping/Discount/Tax collectors apply the shipping discount, tax and
        // any prior partial shipping refund. A supplied gross figure would instead
        // be re-prorated (and, on ex-tax stores, over-refunded or blocked).
        // Suppress delivery (0) when no shipping refund is owed. Admin can still
        // adjust on the credit memo screen.
        $data = [
            'items'               => $items,
            'adjustment_positive' => '0',
            'adjustment_negative' => '0',
        ];
        if ($prefill->shippingAmount !== null) {
            $data['shipping_amount'] = '0';
        }

        return $data;
    }
}
