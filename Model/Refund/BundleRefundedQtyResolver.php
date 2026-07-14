<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Refund;

use MageMe\EUWithdrawal\Model\Item\ItemAmountResolver;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderItemInterface;

/**
 * The refunded quantity that can be trusted for an order item.
 *
 * A dynamic-price bundle is a dummy parent line whose children carry the money.
 * Magento's credit memo writes the parent's qty_refunded as the full invoiced
 * qty regardless of how much was really refunded (CreditmemoFactory forces the
 * dummy bundle parent to getQtyInvoiced()), so a partial refund reads as a full
 * one. This resolves the parent's true refunded qty from its priced children
 * instead — a whole bundle counts as refunded only once every child has been —
 * and passes any other line through unchanged.
 */
class BundleRefundedQtyResolver
{
    public function __construct(private readonly ItemAmountResolver $itemAmounts)
    {
    }

    /**
     * @param OrderInterface $order
     * @param OrderItemInterface $item
     * @return float refunded qty to subtract from what is still returnable
     */
    public function refundedQty(OrderInterface $order, OrderItemInterface $item): float
    {
        if (!$this->itemAmounts->isChildCalculatedBundle($item)) {
            return (float) ($item->getQtyRefunded() ?? 0.0);
        }

        $parentOrdered = (float) $item->getQtyOrdered();
        if ($parentOrdered <= 0.0) {
            return (float) ($item->getQtyRefunded() ?? 0.0);
        }

        $parentId = (int) $item->getItemId();
        $ratios = [];
        foreach (($order->getItems() ?? []) as $child) {
            if ((int) $child->getParentItemId() !== $parentId) {
                continue;
            }
            $childOrdered = (float) $child->getQtyOrdered();
            if ($childOrdered <= 0.0) {
                continue;
            }
            // The child's ordered qty spans every parent unit, so its refunded
            // share converts back to whole parent units by the same ratio the
            // forward expansion uses.
            $ratios[] = (float) ($child->getQtyRefunded() ?? 0.0) * $parentOrdered / $childOrdered;
        }

        if ($ratios === []) {
            return (float) ($item->getQtyRefunded() ?? 0.0);
        }

        return floor(min($ratios));
    }
}
