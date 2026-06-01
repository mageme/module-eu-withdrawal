<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Order;

use Magento\Framework\App\ResourceConnection;
use Psr\Log\LoggerInterface;

/**
 * Answers "does this sales_order have any shipment rows?" for the
 * pre-shipment withdrawal info-banner flow. Fail-soft on DB errors:
 * returns false (banner is suppressed) and logs a warning. A 5xx on
 * the withdrawal page because of a shipment-count lookup is worse
 * than silently hiding the notice.
 */
class ShipmentExistenceChecker
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
     * Has shipments.
     *
     * @param int $orderEntityId
     * @return bool
     */
    public function hasShipments(int $orderEntityId): bool
    {
        if ($orderEntityId <= 0) {
            return false;
        }
        try {
            $connection = $this->resource->getConnection();
            $select = $connection->select()
                ->from(
                    $this->resource->getTableName(self::TABLE_SHIPMENT),
                    [new \Zend_Db_Expr('COUNT(*)')],
                )
                ->where('order_id = ?', $orderEntityId);
            return (int) $connection->fetchOne($select) > 0;
        } catch (\Throwable $e) {
            $this->logger->warning(
                'EUWithdrawal ShipmentExistenceChecker query failed: ' . $e->getMessage(),
                ['order_id' => $orderEntityId],
            );
            return false;
        }
    }
}
