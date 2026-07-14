<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Refund;

use MageMe\EUWithdrawal\Model\Item\ItemAmounts;
use Magento\Sales\Api\Data\OrderInterface;

/**
 * What the consumer actually paid for delivery.
 *
 * A cart rule with "apply to shipping" leaves `shipping_amount` at the carrier's
 * quote and records the reduction in `shipping_discount_amount`, exactly as it
 * does for an order line. `shipping_tax_amount` is already the tax on the
 * discounted carriage. Reading the raw amount therefore over-states the delivery
 * refund and leaves the difference to be prorated across the returned items.
 *
 * The ordinary Free Shipping action does not go through here: the carrier itself
 * returns `shipping_amount = 0` and no discount is recorded.
 *
 * Returns `ItemAmounts` because carriage obeys the same four-field algebra as an
 * order line — `net() = amount - discount`, `taxTotal() = tax + compensation` —
 * and both must round the same way. Only `rowTotal` reads oddly here; nothing
 * consumes it directly.
 */
class ShippingAmountResolver
{
    /**
     * Resolve.
     *
     * @param OrderInterface $order
     * @return ItemAmounts
     */
    public function resolve(OrderInterface $order): ItemAmounts
    {
        return new ItemAmounts(
            rowTotal: (float) $order->getShippingAmount(),
            discount: (float) $order->getShippingDiscountAmount(),
            tax: (float) $order->getShippingTaxAmount(),
            discountTaxCompensation: (float) $order->getShippingDiscountTaxCompensationAmount(),
        );
    }

    /**
     * The part of the paid delivery a withdrawal may still refund. A native
     * credit memo can refund carriage on its own, leaving every item quantity
     * intact — a subsequent full withdrawal would otherwise pay the delivery a
     * second time.
     *
     * Magento books the refund against the **pre-discount** carriage
     * (`Creditmemo\Total\Shipping` allows `shipping_amount - shipping_refunded`)
     * and accumulates the refunded carriage and its tax in two separate columns.
     * Subtract those directly rather than scaling the gross: the columns already
     * carry Magento's own per-component rounding.
     *
     * The delivery discount and its tax compensation have no refunded
     * counterpart to subtract — neither `sales_order` nor `sales_creditmemo`
     * separates the shipping discount from the item discount — so they are
     * prorated by the unrefunded share of carriage, which is how Magento
     * prorates them into the credit memo in the first place. An exact ledger of
     * "delivery money already paid back" is therefore not derivable from the
     * schema; this is the closest it can be reconstructed.
     *
     * @param OrderInterface $order
     * @return ItemAmounts
     */
    public function resolveRefundable(OrderInterface $order): ItemAmounts
    {
        $quoted = (float) $order->getShippingAmount();
        if ($quoted <= 0.0) {
            return new ItemAmounts(0.0, 0.0, 0.0, 0.0);
        }

        $remainingCarriage = max(0.0, $quoted - (float) $order->getShippingRefunded());
        if ($remainingCarriage <= 0.0) {
            // No carriage left, so no tax on it either — whatever the
            // accumulators say. Data can be inconsistent; money must not be.
            return new ItemAmounts(0.0, 0.0, 0.0, 0.0);
        }
        $unrefundedShare = min(1.0, $remainingCarriage / $quoted);

        return new ItemAmounts(
            rowTotal: $remainingCarriage,
            discount: (float) $order->getShippingDiscountAmount() * $unrefundedShare,
            tax: max(0.0, (float) $order->getShippingTaxAmount() - (float) $order->getShippingTaxRefunded()),
            discountTaxCompensation:
                (float) $order->getShippingDiscountTaxCompensationAmount() * $unrefundedShare,
        );
    }
}
