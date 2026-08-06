<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Item;

use MageMe\EUWithdrawal\Api\Seal\SealKindResolverInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderItemInterface;

/**
 * What is inside a bundle that is returned as one unit.
 *
 * The rule is a property of the returnable line, not of the store's bundle
 * mode: whenever the line the consumer withdraws is a bundle PARENT, its
 * children describe the goods actually going back. That covers whole-bundle
 * mode and — per ReturnGroupBuilder::build() — a fixed-price bundle in
 * per-component mode too. When the children themselves are the returnable
 * lines there is nothing to expand, and a configurable's simple child is a
 * variant rather than a content, so both yield an empty list.
 *
 * Order rows are the source: a bundle's composition, names, SKUs and row
 * amounts are fixed when the order is placed and never change afterwards, so
 * the same list resolves identically before and after the request is filed.
 */
class BundleContentsResolver
{
    private const TYPE_BUNDLE = 'bundle';

    /** Product types that ship nothing back. */
    private const NON_PHYSICAL_TYPES = ['virtual', 'downloadable'];

    private const EPSILON = 0.005;

    public function __construct(
        private readonly ItemAmountResolver $itemAmounts,
        private readonly ProductNameDecoder $productName,
        private readonly SealKindResolverInterface $sealKind,
    ) {
    }

    /**
     * Components of the given returnable line, ordered by order-item id.
     *
     * @param OrderInterface $order
     * @param int $lineOrderItemId
     * @return BundleContentLine[]
     */
    public function resolve(OrderInterface $order, int $lineOrderItemId): array
    {
        $parent = $lineOrderItemId > 0 ? $order->getItemById($lineOrderItemId) : null;
        if (!$parent instanceof OrderItemInterface || $parent->getProductType() !== self::TYPE_BUNDLE) {
            return [];
        }

        $children = $this->childrenOf($order, $lineOrderItemId);
        if ($children === []) {
            return [];
        }

        // A bundle that prices on its children carries the money there, so each
        // component's own amounts are exactly its share and they sum to the
        // parent by construction. A fixed-price bundle prices on the parent and
        // its children cost nothing — quoting their zero would read as free, so
        // they say "Included" instead. A bundle that came to nothing (fully
        // discounted, zero-priced) does the same rather than printing 0.00 on
        // every part.
        $priced = $this->itemAmounts->isChildCalculatedBundle($parent);
        $amountsByChild = [];
        if ($priced) {
            $gross = 0.0;
            foreach ($children as $child) {
                $amounts = $this->itemAmounts->resolve($order, $child);
                $amountsByChild[(int) $child->getItemId()] = $amounts;
                $gross += $amounts->net() + $amounts->taxTotal();
            }
            $priced = abs($gross) > self::EPSILON;
        }

        $parentQtyOrdered = (float) $parent->getQtyOrdered();
        $storeId = (int) $order->getStoreId();

        $lines = [];
        foreach ($children as $child) {
            $amounts = $priced ? $amountsByChild[(int) $child->getItemId()] : null;
            $productId = (int) $child->getProductId();
            $kind = $productId > 0
                ? $this->sealKind->resolve($productId, $storeId)
                : null;

            $lines[] = new BundleContentLine(
                orderItemId: (int) $child->getItemId(),
                name: $this->productName->decode((string) $child->getName()),
                sku: (string) $child->getSku(),
                qtyPerParentUnit: $parentQtyOrdered > 0.0
                    ? (float) $child->getQtyOrdered() / $parentQtyOrdered
                    : 0.0,
                rowNet: $amounts !== null ? round($amounts->net(), 4, PHP_ROUND_HALF_EVEN) : 0.0,
                rowTax: $amounts !== null ? round($amounts->taxTotal(), 4, PHP_ROUND_HALF_EVEN) : 0.0,
                priced: $priced,
                physical: !in_array((string) $child->getProductType(), self::NON_PHYSICAL_TYPES, true),
                sealed: $kind !== null && $kind->isSealed()
                    ? new SealedComponent($kind, $lineOrderItemId)
                    : null,
            );
        }

        usort($lines, static fn (BundleContentLine $a, BundleContentLine $b) => $a->orderItemId <=> $b->orderItemId);

        return $lines;
    }

    /**
     * @param OrderInterface $order
     * @param int $parentItemId
     * @return OrderItemInterface[]
     */
    private function childrenOf(OrderInterface $order, int $parentItemId): array
    {
        $children = [];
        foreach ($order->getAllItems() as $item) {
            if ($item->getParentItemId() !== null && (int) $item->getParentItemId() === $parentItemId) {
                $children[] = $item;
            }
        }
        return $children;
    }
}
