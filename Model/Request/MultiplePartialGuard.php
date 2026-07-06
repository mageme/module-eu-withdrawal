<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Request;

use MageMe\EUWithdrawal\Api\Data\RequestInterface;
use MageMe\EUWithdrawal\Exception\ItemCapacityExceededException;
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
     */
    public function __construct(
        private readonly ResourceConnection $resource,
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
                - (float) ($oi->getQtyRefunded() ?? 0.0);
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
        $reqTable = $this->resource->getTableName('mm_eu_withdrawal_request');
        $itemTable = $this->resource->getTableName('mm_eu_withdrawal_item');

        $select = $connection->select()
            ->from(['i' => $itemTable], ['order_item_id', new \Zend_Db_Expr('SUM(i.qty_withdraw) AS blocked')])
            ->join(['r' => $reqTable], 'r.request_id = i.request_id', [])
            ->where('r.order_id = ?', $orderId)
            ->where('r.status IN (?)', self::ACTIVE_STATUSES)
            ->group('i.order_item_id');

        if ($excludeRequestId !== null) {
            $select->where('r.request_id != ?', $excludeRequestId);
        }

        /** @var array<int, numeric-string> $blockedByOid */
        $blockedByOid = $connection->fetchPairs($select);

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
}
