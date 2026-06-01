<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Period;

use MageMe\EUWithdrawal\Exception\InvalidConfigurationException;
use MageMe\EUWithdrawal\Exception\NoDeliveryInfoException;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Phrase;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Resolves the 14-day withdrawal-period anchor as the timestamp of the
 * order's transition into the merchant-configured "delivery confirmation"
 * status (Stores → Configuration → MageMe Extensions → EU Withdrawal →
 * Withdrawal Period → Delivery Confirmation Status).
 *
 * Source of truth is `sales_order_status_history` — the row's `created_at`
 * column captures the moment the admin/automation marked the order as
 * delivered (Art. 9(2)(b) CRD).
 *
 * Behaviour:
 *  - Status not configured → `InvalidConfigurationException`. The merchant
 *    must explicitly pick a status; we refuse to silently default — the
 *    14-day period start is too legally consequential to guess.
 *  - Order has not yet transitioned into the configured status →
 *    `NoDeliveryInfoException`. `PeriodRule` treats that as "open period"
 *    (decision stays eligible, period_end_at remains NULL).
 */
class AnchorResolver
{
    public const XML_DELIVERY_STATUS_CODE = 'mageme_eu_withdrawal/withdrawal_window/delivery_status_code';

    private const TABLE_STATUS_HISTORY = 'sales_order_status_history';

    /**
     * Constructor.
     *
     * @param ScopeConfigInterface $scopeConfig
     * @param ResourceConnection $resource
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly ResourceConnection $resource,
    ) {
    }

    /**
     * Resolve.
     *
     * @param OrderInterface $order
     * @param int $storeId
     * @return \DateTimeImmutable
     */
    public function resolve(OrderInterface $order, int $storeId): \DateTimeImmutable
    {
        $rawConfig = (string) $this->scopeConfig->getValue(
            self::XML_DELIVERY_STATUS_CODE,
            ScopeInterface::SCOPE_STORE,
            $storeId,
        );
        $statuses = $this->parseStatuses($rawConfig);
        if ($statuses === []) {
            throw new InvalidConfigurationException(new Phrase(
                'Delivery confirmation status is not configured. Set Stores → Configuration → MageMe Extensions → EU Withdrawal → Withdrawal Period → Delivery Confirmation Statuses.',
            ));
        }

        $connection = $this->resource->getConnection();
        // Earliest transition into ANY of the configured statuses — protects
        // against multiple back-and-forth transitions: legally the clock
        // starts the first time the customer's "delivered" event fires,
        // regardless of which of the configured terminal statuses was used.
        $select = $connection->select()
            ->from(
                $this->resource->getTableName(self::TABLE_STATUS_HISTORY),
                ['created_at'],
            )
            ->where('parent_id = ?', (int) $order->getEntityId())
            ->where('status IN (?)', $statuses)
            ->order('created_at ASC')
            ->limit(1);
        $raw = $connection->fetchOne($select);
        if ($raw === false || $raw === null || $raw === '') {
            throw new NoDeliveryInfoException();
        }

        try {
            return new \DateTimeImmutable($raw . 'Z', new \DateTimeZone('UTC'));
        } catch (\Throwable) {
            throw new NoDeliveryInfoException();
        }
    }

    /**
     * Multiselect persists the value as a comma-separated string. Trim, split
     * on commas, drop empties.
     *
     * @return string[]
     */
    private function parseStatuses(string $rawConfig): array
    {
        $rawConfig = trim($rawConfig);
        if ($rawConfig === '') {
            return [];
        }
        $parts = array_map('trim', explode(',', $rawConfig));
        return array_values(array_filter($parts, static fn (string $s) => $s !== ''));
    }
}
