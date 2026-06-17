<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Frontend;

use MageMe\EUWithdrawal\Model\Config\Source\ItemSelectionMode;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

class SelectionModeConfig
{
    private const XML_PATH_MODE = 'mageme_eu_withdrawal/frontend/item_selection_mode';

    public function __construct(private readonly ScopeConfigInterface $scopeConfig)
    {
    }

    public function isFullOrderMode(?int $storeId = null): bool
    {
        $value = $this->scopeConfig->getValue(self::XML_PATH_MODE, ScopeInterface::SCOPE_STORE, $storeId);
        return $value === ItemSelectionMode::FULL_ORDER;
    }
}
