<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Item;

use MageMe\EUWithdrawal\Api\Data\EligibilityResultInterface;
use MageMe\EUWithdrawal\Api\Data\RequestInterface;
use MageMe\EUWithdrawal\Model\Refund\BundleHeldQtyResolver;
use MageMe\EUWithdrawal\Model\Refund\BundleRefundedQtyResolver;
use MageMe\EUWithdrawal\Model\ResourceModel\Item\CollectionFactory as ItemCollectionFactory;
use Magento\Sales\Api\Data\OrderInterface;

class OrderPartialStateCalculator
{
    /**
     * Constructor.
     *
     * @param ItemCollectionFactory $itemCollectionFactory
     * @param ReturnableItemsResolver $returnableItems
     * @param ItemAmountResolver $itemAmounts
     * @param ProductNameDecoder $productName
     * @param BundleRefundedQtyResolver $refundedQty
     * @param BundleHeldQtyResolver $heldQty
     */
    public function __construct(
        private readonly ItemCollectionFactory $itemCollectionFactory,
        private readonly ReturnableItemsResolver $returnableItems,
        private readonly ItemAmountResolver $itemAmounts,
        private readonly ProductNameDecoder $productName,
        private readonly BundleRefundedQtyResolver $refundedQty,
        private readonly BundleHeldQtyResolver $heldQty,
    ) {
    }

    /**
     * @return array<int, RemainingItemState> keyed by order_item_id
     */
    public function calculate(
        OrderInterface $order,
        EligibilityResultInterface $eligibility,
        ?int $excludeRequestId,
    ): array {
        $orderId = (int) $order->getEntityId();
        // Two independent per-item aggregates (SUM grouped by order_item_id);
        // the item collection supplies the connection + table names.
        $collection = $this->itemCollectionFactory->create();
        $connection = $collection->getConnection();
        $reqTable = $collection->getTable('mm_eu_withdrawal_request');
        $itemTable = $collection->getTable('mm_eu_withdrawal_item');
        $memoItemTable = $collection->getTable('sales_creditmemo_item');

        $baseSelect = static function () use ($connection, $itemTable, $reqTable, $orderId, $excludeRequestId) {
            $s = $connection->select()
                ->from(['i' => $itemTable], ['order_item_id', new \Zend_Db_Expr('SUM(i.qty_withdraw)')])
                ->join(['r' => $reqTable], 'r.request_id = i.request_id', [])
                ->where('r.order_id = ?', $orderId)
                ->group('i.order_item_id');
            if ($excludeRequestId !== null) {
                $s->where('r.request_id != ?', $excludeRequestId);
            }
            return $s;
        };

        // Only `approved` is terminal from the customer's side — the refund
        // has been committed and the qty is no longer returnable. `pending`
        // is admin-visible but not yet decided; the customer can still cancel
        // it.
        $approvedSel = $baseSelect()->where('r.status = ?', RequestInterface::STATUS_APPROVED);
        $approvedByOid = $connection->fetchPairs($approvedSel);

        // Outstanding approved qty: withdrawn less what the request's linked credit
        // memo has already refunded, so a refunded unit is no longer counted as
        // spoken-for and the unit still in hand stays offered. `approvedByOid` keeps
        // the historical total, which drives the "already withdrawn" label.
        $approvedOutstandingSel = $connection->select()
            ->from(
                ['i' => $itemTable],
                ['order_item_id', new \Zend_Db_Expr('SUM(GREATEST(0, i.qty_withdraw - COALESCE(cmi.qty, 0)))')]
            )
            ->join(['r' => $reqTable], 'r.request_id = i.request_id', [])
            ->joinLeft(
                ['cmi' => $memoItemTable],
                'cmi.parent_id = r.refund_creditmemo_id AND cmi.order_item_id = i.order_item_id',
                []
            )
            ->where('r.order_id = ?', $orderId)
            ->where('r.status = ?', RequestInterface::STATUS_APPROVED)
            ->group('i.order_item_id');
        if ($excludeRequestId !== null) {
            $approvedOutstandingSel->where('r.request_id != ?', $excludeRequestId);
        }
        $approvedOutstandingByOid = $connection->fetchPairs($approvedOutstandingSel);

        $pendingSel = $baseSelect()->where('r.status = ?', RequestInterface::STATUS_PENDING);
        $pendingByOid = $connection->fetchPairs($pendingSel);

        $states = [];
        $decisions = $eligibility->getItemDecisions();
        // Approved holds for dynamic bundle parents, attributed per request to its
        // own linked memo — the SQL outstanding aggregate mis-reads their inflated
        // parent memo row.
        $dynamicApprovedHeld = $this->heldQty->heldForDynamicParents(
            $order,
            [RequestInterface::STATUS_APPROVED],
            $excludeRequestId,
        );

        foreach ($this->returnableItems->resolve($order) as $oi) {
            $oid = (int) $oi->getItemId();
            $ordered = (int) ((float) $oi->getQtyOrdered());
            // Purchased-for-return excludes units cancelled before invoice or
            // already refunded via a native Magento credit-memo — those are no
            // longer in the consumer's hands and must not be offered again.
            $refunded = (int) $this->refundedQty->refundedQty($order, $oi);
            $purchased = max(0, $ordered
                - (int) ((float) ($oi->getQtyCanceled() ?? 0.0))
                - $refunded);
            $approved = (int) ($approvedByOid[$oid] ?? 0);
            $approvedOutstanding = $this->itemAmounts->isChildCalculatedBundle($oi)
                ? (int) ($dynamicApprovedHeld[$oid] ?? 0)
                : (int) ($approvedOutstandingByOid[$oid] ?? 0);
            $pending = (int) ($pendingByOid[$oid] ?? 0);
            $remaining = max(0, $purchased - $approvedOutstanding - $pending);

            // Ex-tax per-unit price; tax is rendered on its own sidebar row
            // (matches the order-view layout: Subtotal / Shipping / Tax / Total).
            // The divisor is the full ordered qty (row_total spans all units),
            // not the reduced returnable qty.
            $amounts = $this->itemAmounts->resolve($order, $oi);
            $unitDisplay = $ordered > 0
                ? round($amounts->net() / $ordered, 4, PHP_ROUND_HALF_EVEN)
                : 0.0;

            $basis = null;
            $eligible = true;
            if (isset($decisions[$oid]) && !$decisions[$oid]->isEligible()) {
                $eligible = false;
                // getReason() returns the stable machine code (first arg to withDeny());
                // getExclusionBasis() is the human-readable label — not for lookup.
                $basis = $decisions[$oid]->getReason();
            }

            if ($eligible && $remaining === 0) {
                if ($approved > 0) {
                    $eligible = false;
                    $basis = 'already_withdrawn';
                } elseif ($pending > 0) {
                    $eligible = false;
                    $basis = 'pending_only';
                }
            }

            $states[$oid] = new RemainingItemState(
                orderItemId: $oid,
                sku: (string) $oi->getSku(),
                name: $this->productName->decode((string) $oi->getName()),
                purchasedQty: $purchased,
                remainingQty: $remaining,
                pendingQty: $pending,
                alreadyWithdrawnQty: $approved,
                unitDisplayPrice: $unitDisplay,
                eligibility: $eligible
                    ? RemainingItemState::ELIGIBILITY_ELIGIBLE
                    : RemainingItemState::ELIGIBILITY_EXCLUDED,
                exclusionReason: ExclusionReason::fromBasis($basis),
                rowTaxAmount: round($amounts->taxTotal(), 4, PHP_ROUND_HALF_EVEN),
                rowNetAmount: round($amounts->net(), 4, PHP_ROUND_HALF_EVEN),
            );
        }

        return $states;
    }
}
