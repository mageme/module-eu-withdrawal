<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Item;

use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderItemInterface;

/**
 * Resolves the set of order lines that represent a distinct returnable unit.
 *
 * By default a bundle is one returnable unit — its priced children merely break
 * down the parent's row total, so the parent line is kept and the order total is
 * never over- or under-counted. When the merchant opts into per-component bundle
 * returns (frontend/bundle_return_per_component), a dynamic-price bundle whose
 * children reconcile to the parent is represented by those children instead, so
 * each real product (variant + accessory) and its VAT is offered for return
 * under its own SKU. Configurable variants and fixed-price bundle components
 * (zero-priced children) always keep the price on the parent, and simple and
 * virtual lines are returnable as themselves.
 */
class ReturnableItemsResolver
{
    /**
     * Half-cent tolerance: matches the rounding slack the refund math already
     * allows between a 4-decimal sum of parts and Magento's 2-decimal totals.
     */
    private const EPSILON = 0.005;

    public function __construct(private readonly BundleReturnModeResolver $bundleReturnMode)
    {
    }

    /**
     * @return OrderItemInterface[]
     */
    public function resolve(OrderInterface $order): array
    {
        $items = $order->getItems() ?? [];
        $perComponent = $this->bundleReturnMode->isPerComponent($order);

        $childrenByParent = [];
        foreach ($items as $item) {
            $parentId = $item->getParentItemId();
            if ($parentId !== null) {
                $childrenByParent[(int) $parentId][] = $item;
            }
        }

        $returnable = [];
        foreach ($items as $item) {
            if ($item->getParentItemId() !== null) {
                continue;
            }

            $children = $childrenByParent[(int) $item->getItemId()] ?? [];
            if ($perComponent && $this->childrenAreReturnableUnits($item, $children)) {
                foreach ($children as $child) {
                    if ((float) $child->getRowTotal() > self::EPSILON) {
                        $returnable[] = $child;
                    }
                }
                continue;
            }

            $returnable[] = $item;
        }

        return $returnable;
    }

    /**
     * True when the children carry the parent's money (dynamic bundle /
     * accessory-as-child): at least one priced child, and the children's row
     * totals reconcile to the parent's own row total — or the parent itself
     * carries nothing, so the children hold the whole price.
     *
     * @param OrderItemInterface[] $children
     */
    private function childrenAreReturnableUnits(OrderItemInterface $parent, array $children): bool
    {
        $childrenSum = 0.0;
        $hasPricedChild = false;
        foreach ($children as $child) {
            $rowTotal = (float) $child->getRowTotal();
            $childrenSum += $rowTotal;
            if ($rowTotal > self::EPSILON) {
                $hasPricedChild = true;
            }
        }

        if (!$hasPricedChild) {
            return false;
        }

        $parentRow = (float) $parent->getRowTotal();

        return $parentRow <= self::EPSILON
            || abs($childrenSum - $parentRow) <= self::EPSILON;
    }
}
