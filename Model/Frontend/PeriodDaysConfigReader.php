<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Frontend;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Single source for the merchant-configured withdrawal-period length used in
 * customer-facing copy (checkout precontract subhead, withdrawal-flow notices,
 * status emails). Mirrors the value the Annex I body renders, so the wrapper
 * text and the legal disclosure always quote the same number of days.
 */
class PeriodDaysConfigReader
{
    public const XML_PATH_PERIOD_DAYS = 'mageme_eu_withdrawal/withdrawal_window/period_days';
    public const DEFAULT_DAYS = 14;

    /**
     * Constructor.
     *
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
    ) {
    }

    /**
     * Configured withdrawal-period length in days (falls back to the statutory
     * 14 when unset or non-positive).
     *
     * @param ?int $storeId
     * @return int
     */
    public function getDays(?int $storeId = null): int
    {
        $value = (int) $this->scopeConfig->getValue(
            self::XML_PATH_PERIOD_DAYS,
            ScopeInterface::SCOPE_STORE,
            $storeId,
        );
        return $value > 0 ? $value : self::DEFAULT_DAYS;
    }
}
