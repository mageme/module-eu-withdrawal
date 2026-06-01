<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Setup\Patch\Data;

use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

class ActivatePresetRules implements DataPatchInterface
{
    /**
     * Constructor.
     *
     * @param ModuleDataSetupInterface $moduleDataSetup
     */
    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
    ) {
    }

    /**
     * Get dependencies.
     *
     * @return array
     */
    public static function getDependencies(): array
    {
        return [InsertPresetRules::class];
    }

    /**
     * Get aliases.
     *
     * @return array
     */
    public function getAliases(): array
    {
        return [];
    }

    /**
     * Apply.
     *
     * @return self
     */
    public function apply(): self
    {
        $connection = $this->moduleDataSetup->getConnection();
        $table = $this->moduleDataSetup->getTable('mm_eu_withdrawal_rule');

        $presetCodes = ['custom', 'perishable', 'sealed_hygiene', 'sealed_av', 'digital_waiver'];
        $connection->update(
            $table,
            ['is_active' => 1, 'priority' => 50],
            ['preset_code IN (?)' => $presetCodes],
        );

        return $this;
    }
}
