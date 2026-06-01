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
     */
    public function __construct(
        private readonly ItemCollectionFactory $itemCollectionFactory,
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
        // has been committed and the qty is no longer returnable. `submitted`
        // is admin-visible but not yet decided; the customer can still cancel
        // it, so it counts as pending.
        $submittedSel = $baseSelect()->where('r.status = ?', RequestInterface::STATUS_APPROVED);
        $submittedByOid = $connection->fetchPairs($submittedSel);

        $pendingSel = $baseSelect()->where('r.status = ?', RequestInterface::STATUS_PENDING);
        $pendingByOid = $connection->fetchPairs($pendingSel);

        $states = [];
        $decisions = $eligibility->getItemDecisions();

        foreach (($order->getItems() ?? []) as $oi) {
            // Configurable/bundle parents already carry the display name, price, and qty;
            // their simple-variant children duplicate the row with price=0 and a mangled
            // name ("Erika Running Short-28-Green"). Skip children so the UI shows one
            // line per purchased unit and downstream oid references stay parent-scoped.
            if ($oi->getParentItemId()) {
                continue;
            }
            $oid = (int) $oi->getItemId();
            $purchased = (int) ((float) $oi->getQtyOrdered());
            $submitted = (int) ($submittedByOid[$oid] ?? 0);
            $pending = (int) ($pendingByOid[$oid] ?? 0);
            $remaining = max(0, $purchased - $submitted - $pending);

            // Ex-tax per-unit price; tax is rendered on its own sidebar row
            // (matches the order-view layout: Subtotal / Shipping / Tax / Total).
            $rowTotal = (float) $oi->getRowTotal();
            $discount = (float) $oi->getDiscountAmount();
            $unitDisplay = $purchased > 0
                ? round(($rowTotal - $discount) / $purchased, 4, PHP_ROUND_HALF_EVEN)
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
                if ($submitted > 0) {
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
                alreadyWithdrawnQty: $submitted,
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
