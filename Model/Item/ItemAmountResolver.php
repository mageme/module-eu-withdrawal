<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Item;

use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderItemInterface;
use Magento\Sales\Model\Order\Item as OrderItemModel;

/**
 * Resolves the amounts a returnable unit is actually worth.
 *
 * A dynamic-price bundle keeps its money on the children: Magento distributes a
 * cart discount to them and resets the parent's `discount_amount` and
 * `discount_tax_compensation_amount` to zero
 * (\Magento\SalesRule\Model\RulesApplier::applyRule). Reading the parent's own
 * fields therefore yields a pre-discount amount. Fold the children in.
 */
class ItemAmountResolver
{
    private const TYPE_BUNDLE = 'bundle';

    /**
     * \Magento\Bundle\Model\Product\Price::CALCULATE_CHILD — written to the
     * bundle parent's `product_options` by Bundle\Model\Product\Type. Inlined so
     * the module does not have to depend on magento/module-bundle.
     */
    private const CALCULATE_CHILD = 0;

    private ?OrderInterface $indexedOrder = null;

    /** @var array<int, OrderItemInterface[]> */
    private array $childrenByParent = [];

    /**
     * @param OrderInterface $order The order $item belongs to.
     * @param OrderItemInterface $item
     * @return ItemAmounts
     */
    public function resolve(OrderInterface $order, OrderItemInterface $item): ItemAmounts
    {
        $children = $this->childrenOf($order, (int) $item->getItemId());

        if ($children !== [] && $this->isChildCalculatedBundle($item)) {
            return $this->sumOf($children);
        }

        $own = $this->ownAmountsOf($item);
        if ($children === []) {
            return $own;
        }

        // A composite whose price lives on the parent — a configurable, a
        // fixed-price bundle — still carries its fixed product tax on the
        // children: Magento's Weee collector walks `$item->getChildren()` for
        // every composite, and `recalculateParent()` copies only the blob and
        // the per-unit amount upward, never `weee_tax_applied_row_amount`.
        [$weee, $weeeTax] = $this->fixedProductTaxOfAll($children);

        return new ItemAmounts(
            $own->rowTotal,
            $own->discount,
            $own->tax,
            $own->discountTaxCompensation,
            $own->weee + $weee,
            $own->weeeTax + $weeeTax,
        );
    }

    /**
     * @param OrderItemInterface[] $items
     * @return array{0: float, 1: float}
     */
    private function fixedProductTaxOfAll(array $items): array
    {
        $weee = 0.0;
        $weeeTax = 0.0;
        foreach ($items as $item) {
            [$itemWeee, $itemWeeeTax] = $this->fixedProductTaxOf($item);
            $weee += $itemWeee;
            $weeeTax += $itemWeeeTax;
        }

        return [$weee, $weeeTax];
    }

    /**
     * True when the item is a bundle whose money — price, discount, tax — lives
     * on its children rather than on itself (dynamic price type).
     *
     * `product_options` is not on OrderItemInterface — only the concrete order
     * item exposes it, which is what the sales repository always returns.
     * Anything else is treated as carrying its own money.
     *
     * @param OrderItemInterface $item
     * @return bool
     */
    public function isChildCalculatedBundle(OrderItemInterface $item): bool
    {
        if ($item->getProductType() !== self::TYPE_BUNDLE || !$item instanceof OrderItemModel) {
            return false;
        }
        $options = $item->getProductOptions();
        if (!is_array($options) || !isset($options['product_calculations'])) {
            return false;
        }

        return (int) $options['product_calculations'] === self::CALCULATE_CHILD;
    }

    /**
     * @param OrderItemInterface[] $children
     * @return ItemAmounts
     */
    private function sumOf(array $children): ItemAmounts
    {
        $rowTotal = 0.0;
        $discount = 0.0;
        $tax = 0.0;
        $compensation = 0.0;
        foreach ($children as $child) {
            $rowTotal += (float) $child->getRowTotal();
            $discount += (float) $child->getDiscountAmount();
            $tax += (float) $child->getTaxAmount();
            $compensation += (float) $child->getDiscountTaxCompensationAmount();
        }
        [$weee, $weeeTax] = $this->fixedProductTaxOfAll($children);

        return new ItemAmounts($rowTotal, $discount, $tax, $compensation, $weee, $weeeTax);
    }

    /**
     * @param OrderItemInterface $item
     * @return ItemAmounts
     */
    private function ownAmountsOf(OrderItemInterface $item): ItemAmounts
    {
        [$weee, $weeeTax] = $this->fixedProductTaxOf($item);

        return new ItemAmounts(
            (float) $item->getRowTotal(),
            (float) $item->getDiscountAmount(),
            (float) $item->getTaxAmount(),
            (float) $item->getDiscountTaxCompensationAmount(),
            $weee,
            $weeeTax,
        );
    }

    /**
     * Fixed product tax charged on the row, and the VAT levied on it.
     *
     * The principal has its own column. The VAT does not: Magento keeps it only
     * inside the serialised `weee_tax_applied` blob, as the difference between
     * `row_amount_incl_tax` and `row_amount` (`Weee\Total\Creditmemo\Weee`
     * reconstructs it the same way). Neither field exists on `OrderItemInterface`,
     * so a bare interface implementation reports no fee rather than guessing.
     *
     * `weee_tax_row_disposition` is deliberately not read: it means the fee folded
     * *into* the price, and current Magento always writes zero.
     *
     * @param OrderItemInterface $item
     * @return array{0: float, 1: float}
     */
    private function fixedProductTaxOf(OrderItemInterface $item): array
    {
        if (!$item instanceof OrderItemModel) {
            return [0.0, 0.0];
        }

        $principal = (float) $item->getData('weee_tax_applied_row_amount');
        if ($principal === 0.0) {
            return [0.0, 0.0];
        }

        $applied = $item->getData('weee_tax_applied');
        if (!is_string($applied) || $applied === '') {
            return [$principal, 0.0];
        }

        $rows = json_decode($applied, true);
        if (!is_array($rows)) {
            return [$principal, 0.0];
        }

        $inclTax = 0.0;
        foreach ($rows as $row) {
            if (is_array($row) && isset($row['row_amount_incl_tax'])) {
                $inclTax += (float) $row['row_amount_incl_tax'];
            }
        }

        return [$principal, max(0.0, $inclTax - $principal)];
    }

    /**
     * Child index, cached against the order instance itself. Not against its
     * entity id (0 for an unsaved order, stale for a re-loaded one), and not
     * against spl_object_id, which PHP reuses once the object is collected.
     *
     * @param OrderInterface $order
     * @param int $parentItemId
     * @return OrderItemInterface[]
     */
    private function childrenOf(OrderInterface $order, int $parentItemId): array
    {
        if ($this->indexedOrder !== $order) {
            $this->childrenByParent = [];
            foreach (($order->getItems() ?? []) as $item) {
                $parentId = $item->getParentItemId();
                if ($parentId !== null) {
                    $this->childrenByParent[(int) $parentId][] = $item;
                }
            }
            $this->indexedOrder = $order;
        }

        return $this->childrenByParent[$parentItemId] ?? [];
    }
}
