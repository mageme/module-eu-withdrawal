<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Customer;

use MageMe\EUWithdrawal\Api\Data\RequestInterface;
use MageMe\EUWithdrawal\Model\ResourceModel\Item\CollectionFactory as ItemCollectionFactory;
use Magento\Framework\DB\Select;
use Psr\Log\LoggerInterface;

/**
 * Returns a map of `order_entity_id => 'partial' | 'full'` for the input
 * list of orders. Orders with no withdrawal activity are absent from the
 * map (no-badge is represented by absence, not a magic string).
 *
 * A single aggregate SQL joins the withdrawal tables to sales_order_item
 * to compute, per item, how much has been withdrawn vs ordered. Only
 * requests with status in ('pending', 'approved') count.
 */
class OrderWithdrawalBadgeService
{
    private const TABLE_REQUEST = 'mm_eu_withdrawal_request';
    private const TABLE_ITEM    = 'mm_eu_withdrawal_item';
    private const TABLE_ORDER_ITEM = 'sales_order_item';

    private const COUNTED_STATUSES = [RequestInterface::STATUS_PENDING, RequestInterface::STATUS_APPROVED];

    public const BADGE_PARTIAL = 'partial';
    public const BADGE_FULL    = 'full';

    /**
     * Constructor.
     *
     * @param ItemCollectionFactory $itemCollectionFactory
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly ItemCollectionFactory $itemCollectionFactory,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param int[] $orderEntityIds
     * @return array<int, string> order_id => 'partial'|'full' (absent = no badge)
     */
    public function getBadges(array $orderEntityIds): array
    {
        $ids = array_values(array_unique(array_filter($orderEntityIds, static fn ($v) => (int) $v > 0)));
        if ($ids === []) {
            return [];
        }

        try {
            // Collection-as-query-builder: the item collection supplies the
            // connection + table names; the aggregate (SUM/MAX/GROUP BY across
            // the withdrawal + sales_order_item join) is built on its select and
            // fetched raw, since hydrating Item models doesn't fit aggregate rows.
            $collection = $this->itemCollectionFactory->create();
            $connection = $collection->getConnection();
            $select = $collection->getSelect()
                ->reset(Select::COLUMNS)
                ->columns(['order_item_id' => 'main_table.order_item_id', 'withdrawn' => new \Zend_Db_Expr('SUM(main_table.qty_withdraw)')])
                ->join(
                    ['r' => $collection->getTable(self::TABLE_REQUEST)],
                    'r.request_id = main_table.request_id',
                    ['order_id' => 'r.order_id'],
                )
                ->join(
                    ['oi' => $collection->getTable(self::TABLE_ORDER_ITEM)],
                    'oi.item_id = main_table.order_item_id AND oi.parent_item_id IS NULL',
                    ['item_id' => 'oi.item_id', 'ordered' => new \Zend_Db_Expr('MAX(oi.qty_ordered)')],
                )
                ->where('r.status IN (?)', self::COUNTED_STATUSES)
                ->where('r.order_id IN (?)', $ids)
                ->group(['r.order_id', 'main_table.order_item_id']);

            $rows = $connection->fetchAll($select);

            // The aggregate above only returns rows for items that have at
            // least one withdrawal record. To correctly distinguish "every
            // billable item was withdrawn" (full) from "all items WITH
            // requests are withdrawn but other items have none yet" (still
            // partial), pull the total billable item count per order.
            $totalsSelect = $connection->select()
                ->from(
                    ['oi' => $collection->getTable(self::TABLE_ORDER_ITEM)],
                    ['order_id' => 'oi.order_id', 'total' => new \Zend_Db_Expr('COUNT(*)')],
                )
                ->where('oi.parent_item_id IS NULL')
                ->where('oi.order_id IN (?)', $ids)
                ->group('oi.order_id');
            $totals = $connection->fetchPairs($totalsSelect);
        } catch (\Throwable $e) {
            $this->logger->warning(
                'EUWithdrawal badge query failed: ' . $e->getMessage(),
                ['order_ids' => $ids],
            );
            return [];
        }

        // Per (order_id, item_id) tuple: remaining = ordered - withdrawn.
        // Order badge: 'full' iff every item has remaining <= 0; 'partial'
        // iff any item has withdrawn > 0 and order is not full.
        $perOrder = [];
        foreach ($rows as $row) {
            $orderId = (int) $row['order_id'];
            $perOrder[$orderId] ??= ['items' => [], 'anyWithdrawn' => false];
            $perOrder[$orderId]['items'][] = [
                'withdrawn' => (float) $row['withdrawn'],
                'ordered'   => (float) $row['ordered'],
            ];
            if ((float) $row['withdrawn'] > 0) {
                $perOrder[$orderId]['anyWithdrawn'] = true;
            }
        }

        $out = [];
        foreach ($perOrder as $orderId => $data) {
            $full = true;
            foreach ($data['items'] as $item) {
                if ($item['withdrawn'] < $item['ordered']) {
                    $full = false;
                    break;
                }
            }
            // Even when every item-with-a-record is fully withdrawn, the
            // order is only FULL if every billable item in the order is
            // covered by a withdrawal record. Items the customer never
            // requested keep the order PARTIAL.
            if ($full) {
                $totalItems = (int) ($totals[$orderId] ?? 0);
                if ($totalItems === 0 || count($data['items']) < $totalItems) {
                    $full = false;
                }
            }
            if ($full) {
                $out[$orderId] = self::BADGE_FULL;
            } elseif ($data['anyWithdrawn']) {
                $out[$orderId] = self::BADGE_PARTIAL;
            }
            // else: absent — no badge.
        }
        return $out;
    }
}
