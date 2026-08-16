<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Ui\DataProvider\Product\Form\Modifier;

use MageMe\EUWithdrawal\Model\Item\PersonalisedOptionSource;
use MageMe\EUWithdrawal\Model\Rule\Preset\CustomMadePreset;
use MageMe\EUWithdrawal\Setup\Patch\Data\AddPersonalisedOptionsAttribute;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Locator\LocatorInterface;
use Magento\Catalog\Model\Product;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Stdlib\ArrayManager;
use Magento\Ui\DataProvider\Modifier\ModifierInterface;

/**
 * Renders the personalisation attribute as a tick list of the product's own
 * customisable options instead of a plain multiselect.
 */
class PersonalisedOptions implements ModifierInterface
{
    private const DATA_SOURCE_DEFAULT = 'product';

    /**
     * Constructor.
     *
     * @param LocatorInterface $locator
     * @param ArrayManager $arrayManager
     * @param PersonalisedOptionSource $optionSource
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        private readonly LocatorInterface $locator,
        private readonly ArrayManager $arrayManager,
        private readonly PersonalisedOptionSource $optionSource,
        private readonly ScopeConfigInterface $scopeConfig,
    ) {
    }

    /**
     * Modify data.
     *
     * @param array $data
     * @return array
     */
    public function modifyData(array $data): array
    {
        $product = $this->locator->getProduct();
        $path = (int) $product->getId()
            . '/' . self::DATA_SOURCE_DEFAULT
            . '/' . AddPersonalisedOptionsAttribute::ATTRIBUTE;
        $stored = $this->arrayManager->get($path, $data);
        if (!is_string($stored) || $stored === '') {
            return $data;
        }

        $ticks = array_values(array_filter(array_map('trim', explode(',', $stored))));
        $offered = $this->offeredKeys($this->optionSource->toOptionArray($product));
        if ($offered !== []) {
            $ticks = array_values(array_intersect($ticks, $offered));
        }

        return $this->arrayManager->set($path, $data, $ticks);
    }

    /**
     * Modify meta.
     *
     * @param array $meta
     * @return array
     */
    public function modifyMeta(array $meta): array
    {
        $path = $this->arrayManager->findPath(
            AddPersonalisedOptionsAttribute::ATTRIBUTE,
            $meta,
            null,
            'children',
        );
        if ($path === null) {
            return $meta;
        }

        $product = $this->locator->getProduct();
        $choices = $this->optionSource->toOptionArray($product);
        $orphaned = $this->orphanedTicks($product, $choices);

        if ($choices === [] && $orphaned === []) {
            return $this->arrayManager->merge($path . '/arguments/data/config', $meta, [
                'visible' => false,
            ]);
        }

        return $this->arrayManager->merge($path . '/arguments/data/config', $meta, [
            'formElement' => 'checkboxset',
            'component' => 'Magento_Ui/js/form/element/checkbox-set',
            'template' => 'MageMe_EUWithdrawal/form/element/checkbox-set',
            'dataType' => 'text',
            'multiple' => true,
            'options' => $choices,
            'warning' => $this->warning($orphaned !== []),
            'notice' => $this->notice(),
        ]);
    }

    /**
     * Saved ticks that no longer name an option of this product.
     *
     * @param ProductInterface $product
     * @param array $choices
     * @return string[]
     */
    private function orphanedTicks(ProductInterface $product, array $choices): array
    {
        if (!$product instanceof Product) {
            return [];
        }

        $raw = (string) $product->getData(AddPersonalisedOptionsAttribute::ATTRIBUTE);
        $stored = array_filter(array_map('trim', explode(',', $raw)));
        if ($stored === []) {
            return [];
        }

        return array_values(array_diff($stored, $this->offeredKeys($choices)));
    }

    /**
     * Keys of the choices the form offers, headings aside.
     *
     * @param array $choices
     * @return string[]
     */
    private function offeredKeys(array $choices): array
    {
        $keys = [];
        foreach ($choices as $choice) {
            if ($choice['value'] !== '') {
                $keys[] = $choice['value'];
            }
        }

        return $keys;
    }

    /**
     * Warning shown above the tick list, empty when there is nothing to warn about.
     *
     * @param bool $hasOrphanedTicks
     * @return string
     */
    private function warning(bool $hasOrphanedTicks): string
    {
        $parts = [];

        if ((string) $this->scopeConfig->getValue(CustomMadePreset::CONFIG_PATH) === '0') {
            $parts[] = (string) __(
                'These ticks do nothing yet: the Custom-Made Goods rule is off under Stores > '
                . 'Configuration > MageMe Extensions > EU Withdrawal > Eligibility Rules.',
            );
        }

        if ($hasOrphanedTicks) {
            $parts[] = (string) __(
                'Some ticks saved earlier no longer match this product\'s own options, and are '
                . 'ignored — that happens when a product is duplicated or an option is recreated. '
                . 'Tick the options that apply here and save.',
            );
        }

        return implode(' ', $parts);
    }

    /**
     * Help text under the tick list.
     *
     * @return string
     */
    private function notice(): string
    {
        return (string) __(
            'An item ordered with a ticked option is treated as personalised, so the 14-day right of '
            . 'withdrawal does not apply to it. Tick an option only when it makes the item itself made '
            . 'to the customer\'s specification or clearly personalised.',
        );
    }
}
