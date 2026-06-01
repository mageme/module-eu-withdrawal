<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Order;

use Magento\Framework\App\ResourceConnection;
use Psr\Log\LoggerInterface;

class LatestShipmentDateResolver
{
    private const TABLE_SHIPMENT = 'sales_shipment';

    /**
     * Constructor.
     *
     * @param ResourceConnection $resource
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Resolve.
     *
     * @param int $orderEntityId
     * @return ?string
     */
    public function resolve(int $orderEntityId): ?string
    {
        if ($orderEntityId <= 0) {
            return null;
        }
        try {
            $connection = $this->resource->getConnection();
            $select = $connection->select()
                ->from($this->resource->getTableName(self::TABLE_SHIPMENT), ['created_at'])
                ->where('order_id = ?', $orderEntityId)
                ->order('created_at DESC')
                ->limit(1);
            $raw = $connection->fetchOne($select);
            if ($raw === false || $raw === null || $raw === '') {
                return null;
            }
            return (string) $raw;
        } catch (\Throwable $e) {
            $this->logger->warning(
                'EUWithdrawal LatestShipmentDateResolver query failed: ' . $e->getMessage(),
                ['order_id' => $orderEntityId],
            );
            return null;
        }
    }
}
