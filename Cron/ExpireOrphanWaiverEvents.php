<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Cron;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;

class ExpireOrphanWaiverEvents
{
    public const TABLE = 'mm_eu_withdrawal_waiver_event';
    public const XML_QUOTE_LIFETIME_DAYS = 'checkout/cart/delete_quote_after';

    private const FALLBACK_DAYS = 30;

    /**
     * Constructor.
     *
     * @param ResourceConnection $resource
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly ScopeConfigInterface $scopeConfig,
    ) {
    }

    /**
     * Execute.
     *
     * @return void
     */
    public function execute(): void
    {
        // Purge only pre-promotion (order_id=0) consent rows whose quote has
        // certainly expired. Mirror Magento's quote lifetime so a customer who
        // pays days after consenting still has the consent row available for
        // promotion at order place.
        $days = (int) $this->scopeConfig->getValue(self::XML_QUOTE_LIFETIME_DAYS);
        if ($days <= 0) {
            $days = self::FALLBACK_DAYS;
        }
        $conn = $this->resource->getConnection();
        $conn->delete(
            $this->resource->getTableName(self::TABLE),
            [
                'order_id = ?' => 0,
                'created_at < ?' => gmdate('Y-m-d H:i:s', time() - $days * 86400),
            ],
        );
    }
}
