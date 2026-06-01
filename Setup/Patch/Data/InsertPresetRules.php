<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Setup\Patch\Data;

use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

class InsertPresetRules implements DataPatchInterface
{
    private ModuleDataSetupInterface $moduleDataSetup;

    /**
     * Constructor.
     *
     * @param ModuleDataSetupInterface $moduleDataSetup
     */
    public function __construct(ModuleDataSetupInterface $moduleDataSetup)
    {
        $this->moduleDataSetup = $moduleDataSetup;
    }

    /**
     * Get dependencies.
     *
     * @return array
     */
    public static function getDependencies(): array
    {
        return [];
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

        $presets = [
            [
                'preset_code' => 'custom',
                'name' => 'Art. 16(c) — Custom-made goods',
                'condition_json' => '{"attr":"is_custom_made","op":"eq","value":true}',
                'action' => 'exclude',
                'legal_basis' => 'Art. 16(c)',
                'priority' => 50,
                'is_active' => 1,
            ],
            [
                'preset_code' => 'perishable',
                'name' => 'Art. 16(d) — Perishable goods',
                'condition_json' => '{"attr":"is_perishable","op":"eq","value":true}',
                'action' => 'exclude',
                'legal_basis' => 'Art. 16(d)',
                'priority' => 50,
                'is_active' => 1,
            ],
            [
                'preset_code' => 'sealed_hygiene',
                'name' => 'Art. 16(e) — Sealed hygiene / health goods',
                'condition_json' => '{"attr":"is_sealed_hygiene","op":"eq","value":true}',
                'action' => 'require_seal_check',
                'legal_basis' => 'Art. 16(e)',
                'priority' => 50,
                'is_active' => 1,
            ],
            [
                'preset_code' => 'sealed_av',
                'name' => 'Art. 16(i) — Sealed audio/video/software',
                'condition_json' => '{"attr":"is_sealed_av","op":"eq","value":true}',
                'action' => 'require_seal_check',
                'legal_basis' => 'Art. 16(i)',
                'priority' => 50,
                'is_active' => 1,
            ],
            [
                'preset_code' => 'digital_waiver',
                'name' => 'Art. 16(m) — Digital content with waiver',
                'condition_json' => '{"attr":"contract_type","op":"eq","value":"digital_content"}',
                'action' => 'require_waiver_event',
                'legal_basis' => 'Art. 16(m)',
                'priority' => 50,
                'is_active' => 1,
            ],
        ];

        foreach ($presets as $preset) {
            $connection->insert($table, $preset);
        }

        return $this;
    }
}
