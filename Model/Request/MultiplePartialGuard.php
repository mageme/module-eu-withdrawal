<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Request;

use MageMe\EUWithdrawal\Api\Data\RequestInterface;
use MageMe\EUWithdrawal\Exception\ItemCapacityExceededException;
use MageMe\EUWithdrawal\Model\Refund\BundleHeldQtyResolver;
use MageMe\EUWithdrawal\Model\Refund\BundleRefundedQtyResolver;
use Magento\Framework\App\ResourceConnection;
use Magento\Sales\Api\Data\OrderInterface;

/**
 * Concurrency-safe capacity guard for partial withdrawal submits.
 *
 * Caller is responsible for wrapping invocation in a DB transaction so the
 * FOR UPDATE lock and the subsequent item INSERTs (see RequestCreator, Task 8)
 * are atomic. The DB-level UNIQUE (request_id, order_item_id) is the last-line
 * defense if this lock is bypassed.
 */
class MultiplePartialGuard
{
    private const STATUS_PENDING = RequestInterface::STATUS_PENDING;
    private const STATUS_APPROVED = RequestInterface::STATUS_APPROVED;
    private const ACTIVE_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_APPROVED,
    ];

    /**
     * Constructor.
     *
     * @param ResourceConnection $resource
     * @param BundleRefundedQtyResolver $refundedQty
     * @param BundleHeldQtyResolver $heldQty
     */
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly BundleRefundedQtyResolver $refundedQty,
        private readonly BundleHeldQtyResolver $heldQty,
    ) {
    }

    /**
     * @param array<int, int> $items order_item_id => qty
     * @throws \InvalidArgumentException for unknown oids, zero/negative qty
     * @throws ItemCapacityExceededException if requested qty exceeds remaining
     */
    public function assertCapacity(OrderInterface $order, array $items, ?int $excludeRequestId): void
    {
        $purchasedQty = [];
        foreach (($order->getItems() ?? []) as $oi) {
            // Capacity is the still-returnable quantity: ordered minus units
            // cancelled before invoice or already refunded via a native
            // Magento credit-memo. Mirrors RefundCalculator so a partially
            // refunded line cannot be re-withdrawn.
            $returnable = (float) $oi->getQtyOrdered()
                - (float) ($oi->getQtyCanceled() ?? 0.0)
                - $this->refundedQty->refundedQty($order, $oi);
            $purchasedQty[(int) $oi->getItemId()] = max(0, (int) $returnable);
        }

        foreach ($items as $oid => $qty) {
            if (!isset($purchasedQty[$oid])) {
                throw new \InvalidArgumentException(sprintf('oid %d not in order', $oid));
            }
            if (!is_int($qty) || $qty <= 0) {
                throw new \InvalidArgumentException(
                    sprintf('qty must be positive int for oid %d', $oid),
                );
            }
        }

        $connection = $this->resource->getConnection();
        $orderId = (int) $order->getEntityId();

        // 1) Lock the sales_order row to serialize concurrent partial submits.
        $lockSelect = $connection->select()
            ->from($this->resource->getTableName('sales_order'), ['entity_id'])
            ->where('entity_id = ?', $orderId)
            ->forUpdate(true);
        $connection->fetchOne($lockSelect);

        // 2) Compute blocked qty per oid across active (pending/approved) requests.
        $blockedByOid = $this->getActiveHolds($order, $excludeRequestId);

        // 3) Check capacity per requested oid.
        foreach ($items as $oid => $qty) {
            $blocked = (int) ($blockedByOid[$oid] ?? 0);
            $remaining = $purchasedQty[$oid] - $blocked;
            if ($qty > $remaining) {
                throw new ItemCapacityExceededException(
                    orderItemId: $oid,
                    requestedQty: $qty,
                    remainingQty: max(0, $remaining),
                );
            }
        }
    }

    /**
     * Units of each order item held by other active (pending/approved) withdrawal
     * requests — the qty already spoken for and not available to a new request.
     * The refund calculator needs it to tell a full contract withdrawal (this
     * request takes every unheld unit) from a partial one, in step with the
     * storefront form, which subtracts the same holds from what it offers.
     *
     * @param OrderInterface $order
     * @param int|null $excludeRequestId The request being priced, excluded so it
     *        does not count its own units as held.
     * @return array<int, int> order_item_id => held qty
     */
    public function getActiveHolds(OrderInterface $order, ?int $excludeRequestId): array
    {
        $connection = $this->resource->getConnection();
        $reqTable = $this->resource->getTableName('mm_eu_withdrawal_request');
        $itemTable = $this->resource->getTableName('mm_eu_withdrawal_item');
        $memoItemTable = $this->resource->getTableName('sales_creditmemo_item');

        // A request's units stop being held once its refund is booked: subtract, per
        // order item, the qty already refunded by the request's linked credit memo
        // (bounded to the withdrawn qty so an over-large memo cannot release more than
        // was held). Without this a refunded unit is counted both here and in
        // qty_refunded, double-subtracting from what a later request may still take.
        $select = $connection->select()
            ->from(
                ['i' => $itemTable],
                ['order_item_id', new \Zend_Db_Expr('SUM(GREATEST(0, i.qty_withdraw - COALESCE(cmi.qty, 0))) AS blocked')]
            )
            ->join(['r' => $reqTable], 'r.request_id = i.request_id', [])
            ->joinLeft(
                ['cmi' => $memoItemTable],
                'cmi.parent_id = r.refund_creditmemo_id AND cmi.order_item_id = i.order_item_id',
                []
            )
            ->where('r.order_id = ?', (int) $order->getEntityId())
            ->where('r.status IN (?)', self::ACTIVE_STATUSES)
            ->group('i.order_item_id');

        if ($excludeRequestId !== null) {
            $select->where('r.request_id != ?', $excludeRequestId);
        }

        $holds = [];
        foreach ($connection->fetchPairs($select) as $oid => $blocked) {
            $holds[(int) $oid] = (int) $blocked;
        }

        // A dynamic bundle parent's linked memo row carries the inflated invoiced
        // qty, so the subtraction above cannot be trusted for it; recompute those
        // holds per request from each request's own linked memo child rows.
        foreach ($this->heldQty->heldForDynamicParents($order, self::ACTIVE_STATUSES, $excludeRequestId) as $oid => $held) {
            $holds[$oid] = $held;
        }

        return $holds;
    }
}
