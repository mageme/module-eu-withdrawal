<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model;

use MageMe\EUWithdrawal\Api\FeatureFlagInterface;
use Magento\Framework\Module\Manager as ModuleManager;

class FeatureFlag implements FeatureFlagInterface
{
    private const MODULE_PRO = 'MageMe_EUWithdrawalPro';

    private ModuleManager $moduleManager;

    /**
     * Constructor.
     *
     * @param ModuleManager $moduleManager
     */
    public function __construct(ModuleManager $moduleManager)
    {
        $this->moduleManager = $moduleManager;
    }

    /**
     * Get installed tier.
     *
     * @return string
     */
    public function getInstalledTier(): string
    {
        if ($this->moduleManager->isEnabled(self::MODULE_PRO)) {
            return self::TIER_PRO;
        }

        return self::TIER_FREE;
    }

    /**
     * Is feature enabled.
     *
     * @param string $flag
     * @return bool
     */
    public function isFeatureEnabled(string $flag): bool
    {
        // In the base module package every feature is off. Pro add-ons override
        // this preference in their own etc/di.xml and return true for flags
        // within their tier.
        return false;
    }
}
