<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Frontend;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

class BundleReturnConfig
{
    private const XML_PATH_PER_COMPONENT = 'mageme_eu_withdrawal/frontend/bundle_return_per_component';

    public function __construct(private readonly ScopeConfigInterface $scopeConfig)
    {
    }

    /**
     * Whether a bundle's priced children are offered as individual returnable
     * lines (opt-in) rather than the bundle being returned as a single unit
     * (default).
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isPerComponentEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_PER_COMPONENT,
            ScopeInterface::SCOPE_STORE,
            $storeId,
        );
    }
}
