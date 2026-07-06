<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Item;

use MageMe\EUWithdrawal\Api\Data\EligibilityResultInterface;
use MageMe\EUWithdrawal\Api\Data\RequestInterface;
use MageMe\EUWithdrawal\Model\ResourceModel\Item\CollectionFactory as ItemCollectionFactory;
use Magento\Sales\Api\Data\OrderInterface;

class OrderPartialStateCalculator
{
    /**
     * Constructor.
     *
     * @param ItemCollectionFactory $itemCollectionFactory
     * @param ReturnableItemsResolver $returnableItems
     */
    public function __construct(
        private readonly ItemCollectionFactory $itemCollectionFactory,
        private readonly ReturnableItemsResolver $returnableItems,
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

        $pendingSel = $baseSelect()->where('r.status = ?', RequestInterface::STATUS_PENDING);
        $pendingByOid = $connection->fetchPairs($pendingSel);

        $states = [];
        $decisions = $eligibility->getItemDecisions();

        foreach ($this->returnableItems->resolve($order) as $oi) {
            $oid = (int) $oi->getItemId();
            $ordered = (int) ((float) $oi->getQtyOrdered());
            // Purchased-for-return excludes units cancelled before invoice or
            // already refunded via a native Magento credit-memo — those are no
            // longer in the consumer's hands and must not be offered again.
            $purchased = max(0, $ordered
                - (int) ((float) ($oi->getQtyCanceled() ?? 0.0))
                - (int) ((float) ($oi->getQtyRefunded() ?? 0.0)));
            $approved = (int) ($approvedByOid[$oid] ?? 0);
            $pending = (int) ($pendingByOid[$oid] ?? 0);
            $remaining = max(0, $purchased - $approved - $pending);

            // Ex-tax per-unit price; tax is rendered on its own sidebar row
            // (matches the order-view layout: Subtotal / Shipping / Tax / Total).
            // The divisor is the full ordered qty (row_total spans all units),
            // not the reduced returnable qty.
            $rowTotal = (float) $oi->getRowTotal();
            $discount = (float) $oi->getDiscountAmount();
            $unitDisplay = $ordered > 0
                ? round(($rowTotal - $discount) / $ordered, 4, PHP_ROUND_HALF_EVEN)
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
                name: (string) $oi->getName(),
                purchasedQty: $purchased,
                remainingQty: $remaining,
                pendingQty: $pending,
                alreadyWithdrawnQty: $approved,
                unitDisplayPrice: $unitDisplay,
                eligibility: $eligible
                    ? RemainingItemState::ELIGIBILITY_ELIGIBLE
                    : RemainingItemState::ELIGIBILITY_EXCLUDED,
                exclusionReason: ExclusionReason::fromBasis($basis),
            );
        }

        return $states;
    }
}
