<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Single source of truth for the master "Enable Module" admin toggle
 * (`Stores → Configuration → MageMe Extensions → EU Withdrawal → General →
 * Enable Module`). When the flag is off:
 *
 *   - Customer-facing storefront blocks suppress themselves (sidebar link,
 *     footer link, order-view withdrawals section, magic-link email snippet).
 *   - Storefront controllers under `/withdraw-contract/*` redirect to the
 *     homepage via `Observer\GuardDisabledModule` (anti-leakage — clients
 *     poking the route do not learn that the module is installed).
 *   - The status-change email observer and the receipt-publish observer
 *     silently skip — no customer mail leaves the box while the module is
 *     in dormant state.
 *
 * Admin-area surfaces (request grid, audit log, queue consumers, system
 * config) keep working so merchants can wind down existing requests.
 */
class ModuleConfig
{
    public const XML_ENABLED = 'mageme_eu_withdrawal/general/enabled';
    public const XML_FOOTER_LINK = 'mageme_eu_withdrawal/frontend/placements/footer_link';

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
     * Is enabled.
     *
     * @param ?int $storeId
     * @return bool
     */
    public function isEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId,
        );
    }

    /**
     * Is the storefront footer link placement enabled.
     *
     * @param ?int $storeId
     * @return bool
     */
    public function isFooterLinkEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_FOOTER_LINK,
            ScopeInterface::SCOPE_STORE,
            $storeId,
        );
    }
}
