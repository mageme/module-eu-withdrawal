<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Refund;

use MageMe\EUWithdrawal\Model\Item\ItemAmountResolver;
use Magento\Framework\App\ResourceConnection;
use Magento\Sales\Api\Data\OrderInterface;

/**
 * Units of a dynamic bundle parent still held by active withdrawal requests.
 *
 * A dynamic bundle is recorded on its parent line, whose credit-memo row carries
 * the full invoiced qty rather than what was refunded, so the plain per-request
 * memo subtraction releases the whole hold on any partial refund. This attributes
 * each request's refund to ITS OWN linked credit memo's child rows — an unrelated
 * or native refund can never discharge a different request's hold — and converts
 * the refunded child qty back to whole parent units.
 */
class BundleHeldQtyResolver
{
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly ItemAmountResolver $itemAmounts,
    ) {
    }

    /**
     * @param OrderInterface $order
     * @param string[] $statuses request statuses counted as active
     * @param int|null $excludeRequestId request excluded from the tally
     * @return array<int, int> parent order_item_id => held qty (dynamic bundle parents only)
     */
    public function heldForDynamicParents(OrderInterface $order, array $statuses, ?int $excludeRequestId): array
    {
        $parentOrdered = [];
        foreach (($order->getItems() ?? []) as $item) {
            if ($this->itemAmounts->isChildCalculatedBundle($item)) {
                $parentOrdered[(int) $item->getItemId()] = (float) $item->getQtyOrdered();
            }
        }
        if ($parentOrdered === []) {
            return [];
        }

        $childrenOf = [];
        $childOids = [];
        foreach (($order->getItems() ?? []) as $item) {
            $parentId = (int) $item->getParentItemId();
            $childOrdered = (float) $item->getQtyOrdered();
            if (isset($parentOrdered[$parentId]) && $childOrdered > 0.0) {
                $childOid = (int) $item->getItemId();
                $childrenOf[$parentId][$childOid] = $childOrdered;
                $childOids[] = $childOid;
            }
        }

        $rows = $this->activeRequestRows($order, array_keys($parentOrdered), $statuses, $excludeRequestId);
        if ($rows === []) {
            return [];
        }

        $childMemoQty = $this->childMemoQtys($this->linkedMemoIds($rows), $childOids);

        $held = [];
        foreach ($rows as $row) {
            $parentId = (int) $row['order_item_id'];
            $refunded = $this->refundedParentUnits(
                $parentOrdered[$parentId],
                (int) ($row['refund_creditmemo_id'] ?? 0),
                $childrenOf[$parentId] ?? [],
                $childMemoQty,
            );
            $held[$parentId] = ($held[$parentId] ?? 0) + max(0, (int) $row['qty_withdraw'] - $refunded);
        }

        return $held;
    }

    /**
     * Whole parent units a single credit memo refunded, from its child rows. A
     * parent unit is refunded only once every child has been, hence the floored
     * minimum of the per-child ratios.
     *
     * @param array<int, float> $childOrdered childOid => qty_ordered
     * @param array<int, array<int, float>> $childMemoQty memoId => [childOid => qty]
     */
    private function refundedParentUnits(
        float $parentOrdered,
        int $memoId,
        array $childOrdered,
        array $childMemoQty,
    ): int {
        if ($memoId <= 0 || $parentOrdered <= 0.0 || $childOrdered === []) {
            return 0;
        }

        $ratios = [];
        foreach ($childOrdered as $childOid => $ordered) {
            $refunded = $childMemoQty[$memoId][$childOid] ?? 0.0;
            $ratios[] = $refunded * $parentOrdered / $ordered;
        }

        return (int) floor(min($ratios));
    }

    /**
     * @param int[] $parentOids
     * @param string[] $statuses
     * @return array<int, array<string, mixed>>
     */
    private function activeRequestRows(
        OrderInterface $order,
        array $parentOids,
        array $statuses,
        ?int $excludeRequestId,
    ): array {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from(
                ['i' => $this->resource->getTableName('mm_eu_withdrawal_item')],
                ['order_item_id', 'qty_withdraw']
            )
            ->join(
                ['r' => $this->resource->getTableName('mm_eu_withdrawal_request')],
                'r.request_id = i.request_id',
                ['refund_creditmemo_id']
            )
            ->where('r.order_id = ?', (int) $order->getEntityId())
            ->where('r.status IN (?)', $statuses)
            ->where('i.order_item_id IN (?)', $parentOids);
        if ($excludeRequestId !== null) {
            $select->where('r.request_id != ?', $excludeRequestId);
        }

        return $connection->fetchAll($select);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return int[]
     */
    private function linkedMemoIds(array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            $memoId = (int) ($row['refund_creditmemo_id'] ?? 0);
            if ($memoId > 0) {
                $ids[$memoId] = $memoId;
            }
        }

        return array_values($ids);
    }

    /**
     * @param int[] $memoIds
     * @param int[] $childOids
     * @return array<int, array<int, float>> memoId => [childOid => qty]
     */
    private function childMemoQtys(array $memoIds, array $childOids): array
    {
        if ($memoIds === [] || $childOids === []) {
            return [];
        }

        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from(
                $this->resource->getTableName('sales_creditmemo_item'),
                ['parent_id', 'order_item_id', 'qty']
            )
            ->where('parent_id IN (?)', $memoIds)
            ->where('order_item_id IN (?)', $childOids);

        $out = [];
        foreach ($connection->fetchAll($select) as $row) {
            $out[(int) $row['parent_id']][(int) $row['order_item_id']] = (float) $row['qty'];
        }

        return $out;
    }
}
