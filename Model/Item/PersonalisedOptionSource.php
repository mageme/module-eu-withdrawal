<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Item;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Product;

/**
 * Turns a product's customisable options into the tickable choices shown on the
 * product form, and defines the keys those ticks are stored under.
 *
 * A choice with predefined values (drop-down, radio, checkbox, multiple select)
 * is offered per value under a heading row carrying the option's own title,
 * because only some of a list's values mean personalisation. Every other option
 * type is offered as a whole.
 */
class PersonalisedOptionSource
{
    public const OPTION_PREFIX = 'o';
    public const VALUE_PREFIX = 'v';

    /** Option types whose stored value is a list of option value ids. */
    public const LIST_TYPES = ['drop_down', 'radio', 'checkbox', 'multiple'];

    /**
     * Tickable choices, with heading rows for options that have values.
     *
     * @param ProductInterface $product
     * @return array<int, array<string, mixed>>
     */
    public function toOptionArray(ProductInterface $product): array
    {
        if (!$product instanceof Product) {
            return [];
        }

        $choices = [];
        foreach ($product->getOptions() ?? [] as $option) {
            $title = (string) $option->getTitle();
            $typeLabel = $this->typeLabel((string) $option->getType());

            if (!$option->hasValues()) {
                $choices[] = [
                    'value' => self::OPTION_PREFIX . (int) $option->getOptionId(),
                    'label' => (string) __('%1 (any entry)', $title),
                    'typeLabel' => $typeLabel,
                ];
                continue;
            }

            $choices[] = ['value' => '', 'label' => $title, 'header' => true, 'typeLabel' => $typeLabel];
            foreach ($option->getValues() ?? [] as $value) {
                $choices[] = [
                    'value' => self::VALUE_PREFIX . (int) $value->getOptionTypeId(),
                    'label' => (string) $value->getTitle(),
                    'child' => true,
                ];
            }
        }

        return $choices;
    }

    /**
     * Human name of a customisable option's type.
     *
     * @param string $type
     * @return string
     */
    private function typeLabel(string $type): string
    {
        return (string) match ($type) {
            'field' => __('Text Field'),
            'area' => __('Text Area'),
            'file' => __('File Upload'),
            'date' => __('Date'),
            'date_time' => __('Date & Time'),
            'time' => __('Time'),
            'drop_down' => __('Drop-down'),
            'radio' => __('Radio Buttons'),
            'checkbox' => __('Checkbox'),
            'multiple' => __('Multiple Select'),
            default => '',
        };
    }
}
