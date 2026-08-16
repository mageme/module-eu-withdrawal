<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Item;

use MageMe\EUWithdrawal\Setup\Patch\Data\AddPersonalisedOptionsAttribute;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Sales\Api\Data\OrderItemInterface;
use Magento\Sales\Model\Order\Item as OrderItem;

/**
 * Decides whether an order line was personalised, by matching the choices the
 * merchant ticked on the product against the options the shopper actually made.
 *
 * A tick is only honoured when the option carries a value on that line: an
 * empty text field or an untouched option never removes the right of
 * withdrawal.
 */
class PersonalisedOptionDetector
{
    /**
     * Was this line personalised.
     *
     * @param ?OrderItemInterface $item
     * @param ?ProductInterface $product
     * @return bool
     */
    public function isPersonalised(?OrderItemInterface $item, ?ProductInterface $product): bool
    {
        if (!$item instanceof OrderItem || $product === null) {
            return false;
        }

        $ticked = $this->tickedChoices($product);
        if ($ticked === []) {
            return false;
        }

        foreach ($item->getProductOptions()['options'] ?? [] as $chosen) {
            if ($this->matches($chosen, $ticked)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Choices the merchant ticked on the product.
     *
     * @param ProductInterface $product
     * @return string[]
     */
    private function tickedChoices(ProductInterface $product): array
    {
        $attr = $product->getCustomAttribute(AddPersonalisedOptionsAttribute::ATTRIBUTE);
        $raw = $attr === null ? '' : trim((string) $attr->getValue());
        if ($raw === '') {
            return [];
        }

        $keys = array_map('trim', explode(',', $raw));

        return array_values(array_filter($keys, static fn (string $key): bool => $key !== ''));
    }

    /**
     * Does this chosen option match any ticked choice.
     *
     * @param array $chosen
     * @param string[] $ticked
     * @return bool
     */
    private function matches(array $chosen, array $ticked): bool
    {
        $optionValue = trim((string) ($chosen['option_value'] ?? ''));
        if ($optionValue === '' && trim((string) ($chosen['value'] ?? '')) === '') {
            return false;
        }

        $optionKey = PersonalisedOptionSource::OPTION_PREFIX . (int) ($chosen['option_id'] ?? 0);
        if (in_array($optionKey, $ticked, true)) {
            return true;
        }

        // Only a list option stores value ids in option_value; a text or date entry
        // that happens to read like an id must never match a ticked list value.
        if (!in_array((string) ($chosen['option_type'] ?? ''), PersonalisedOptionSource::LIST_TYPES, true)) {
            return false;
        }

        foreach (explode(',', $optionValue) as $valueId) {
            $valueId = trim($valueId);
            if ($valueId === '' || !ctype_digit($valueId)) {
                continue;
            }
            if (in_array(PersonalisedOptionSource::VALUE_PREFIX . $valueId, $ticked, true)) {
                return true;
            }
        }

        return false;
    }
}
