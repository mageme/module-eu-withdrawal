<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Item;

use MageMe\EUWithdrawal\Api\Data\RequestInterface;
use MageMe\EUWithdrawal\Model\Frontend\BundleReturnConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Sales\Api\Data\OrderInterface;

/**
 * Decides the effective bundle-return mode for an order. Once an order has an
 * active (pending/approved) withdrawal, the mode is pinned to whichever
 * returnable-unit identity that withdrawal used — bundle children (per-component)
 * or the bundle parent (whole bundle) — so toggling the store flag mid-lifecycle
 * can never re-offer already-withdrawn goods under a different order_item_id. An
 * order with no bundle withdrawal yet follows the store flag.
 */
class BundleReturnModeResolver
{
    /** @var array<int, bool> per-order-id request cache */
    private array $cache = [];

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly BundleReturnConfig $config,
    ) {
    }

    public function isPerComponent(OrderInterface $order): bool
    {
        $orderId = (int) $order->getEntityId();
        if ($orderId > 0 && array_key_exists($orderId, $this->cache)) {
            return $this->cache[$orderId];
        }

        $locked = $orderId > 0 ? $this->lockedMode($orderId) : null;
        $result = $locked ?? $this->config->isPerComponentEnabled(
            $order->getStoreId() !== null ? (int) $order->getStoreId() : null,
        );

        if ($orderId > 0) {
            $this->cache[$orderId] = $result;
        }

        return $result;
    }

    /**
     * The returnable-unit mode already committed by the order's active requests:
     * true if a bundle CHILD line was withdrawn, false if a bundle PARENT line
     * was withdrawn, null if neither (no bundle touched) so the caller falls back
     * to the store flag. A query failure also yields null (degrade to the flag).
     */
    private function lockedMode(int $orderId): ?bool
    {
        try {
            $connection = $this->resource->getConnection();
            $soi = $this->resource->getTableName('sales_order_item');
            $select = $connection->select()
                ->from(['i' => $this->resource->getTableName('mm_eu_withdrawal_item')], [])
                ->join(
                    ['r' => $this->resource->getTableName('mm_eu_withdrawal_request')],
                    'r.request_id = i.request_id',
                    [],
                )
                ->join(
                    ['oi' => $soi],
                    'oi.item_id = i.order_item_id',
                    [
                        'has_child' => new \Zend_Db_Expr(
                            'MAX(CASE WHEN oi.parent_item_id IS NOT NULL THEN 1 ELSE 0 END)',
                        ),
                        'has_parent' => new \Zend_Db_Expr(
                            'MAX(CASE WHEN oi.parent_item_id IS NULL AND oi.product_type = \'bundle\' '
                            . 'AND oi.item_id IN '
                            . '(SELECT c.parent_item_id FROM ' . $soi
                            . ' c WHERE c.parent_item_id IS NOT NULL AND c.order_id = oi.order_id) '
                            . 'THEN 1 ELSE 0 END)',
                        ),
                    ],
                )
                ->where('r.order_id = ?', $orderId)
                ->where('r.status IN (?)', [RequestInterface::STATUS_PENDING, RequestInterface::STATUS_APPROVED]);

            $row = $connection->fetchRow($select);
        } catch (\Throwable) {
            return null;
        }

        if (!is_array($row)) {
            return null;
        }
        if ((int) ($row['has_child'] ?? 0) === 1) {
            return true;
        }
        if ((int) ($row['has_parent'] ?? 0) === 1) {
            return false;
        }

        return null;
    }
}
