<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Refund;

use MageMe\EUWithdrawal\Model\Item\ItemAmountResolver;
use Magento\Sales\Api\Data\OrderInterface;

/**
 * Expands a withdrawal's recorded order-item quantities into the set Magento's
 * credit memo needs.
 *
 * In whole-bundle mode only the bundle parent is recorded. For a dynamic-price
 * bundle the parent is a dummy line and every cent sits on the children, which
 * \Magento\Sales\Model\Order\CreditmemoFactory drops unless they carry a
 * quantity of their own.
 */
class CreditMemoQtyExpander
{
    public function __construct(private readonly ItemAmountResolver $itemAmounts)
    {
    }

    /**
     * @param OrderInterface $order
     * @param array<int, int> $qtys order_item_id => qty, as recorded on the request
     * @return array<int, float> order_item_id => qty, children included
     */
    public function expand(OrderInterface $order, array $qtys): array
    {
        if ($qtys === []) {
            return [];
        }

        $out = [];
        foreach ($qtys as $oid => $qty) {
            $out[(int) $oid] = (float) $qty;
        }

        $byId = [];
        foreach (($order->getItems() ?? []) as $item) {
            $byId[(int) $item->getItemId()] = $item;
        }

        foreach ($byId as $item) {
            $parentId = (int) $item->getParentItemId();
            if ($parentId <= 0 || !isset($qtys[$parentId], $byId[$parentId])) {
                continue;
            }
            $parent = $byId[$parentId];
            if (!$this->itemAmounts->isChildCalculatedBundle($parent)) {
                continue;
            }
            $parentOrdered = (float) $parent->getQtyOrdered();
            if ($parentOrdered <= 0.0) {
                continue;
            }
            // The child's ordered qty already spans every parent unit, so the
            // per-selected-bundle share is a plain ratio.
            $out[(int) $item->getItemId()] = (float) $qtys[$parentId]
                * (float) $item->getQtyOrdered() / $parentOrdered;
        }

        return $out;
    }
}
