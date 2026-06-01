<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Setup\Patch\Data;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Setup\CategorySetup;
use Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface;
use Magento\Eav\Setup\EavSetup;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

class AddWithdrawalProductAttributes implements DataPatchInterface
{
    private const GROUP = 'EU Withdrawal Compliance';
    private const ATTRIBUTES = [
        'is_custom_made'     => 'Custom-made / personalised (Art. 16(c))',
        'is_perishable'      => 'Perishable (Art. 16(d))',
        'is_sealed_hygiene'  => 'Sealed hygiene / health (Art. 16(e))',
        'is_sealed_av'       => 'Sealed A/V / software (Art. 16(i))',
        'is_digital_content' => 'Digital content (Art. 16(m))',
    ];

    /**
     * Constructor.
     *
     * @param ModuleDataSetupInterface $moduleDataSetup
     * @param EavSetupFactory $eavSetupFactory
     */
    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly EavSetupFactory $eavSetupFactory,
    ) {
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
        /** @var EavSetup $eavSetup */
        $eavSetup = $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup]);
        foreach (self::ATTRIBUTES as $code => $label) {
            $eavSetup->addAttribute(
                Product::ENTITY,
                $code,
                [
                    'type'     => 'int',
                    'backend'  => '',
                    'frontend' => '',
                    'label'    => $label,
                    'input'    => 'boolean',
                    'class'    => '',
                    'source'   => \Magento\Eav\Model\Entity\Attribute\Source\Boolean::class,
                    'global'   => ScopedAttributeInterface::SCOPE_GLOBAL,
                    'visible'  => true,
                    'required' => false,
                    'user_defined' => true,
                    'default'  => 0,
                    'searchable' => false,
                    'filterable' => false,
                    'comparable' => false,
                    'visible_on_front' => false,
                    'used_in_product_listing' => true,
                    'unique'   => false,
                    'group'    => self::GROUP,
                ],
            );
        }
        return $this;
    }
}
