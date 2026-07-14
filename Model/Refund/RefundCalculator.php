<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Refund;

use MageMe\EUWithdrawal\Api\Data\EligibilityResultInterface;
use MageMe\EUWithdrawal\Model\Item\ItemAmountResolver;
use MageMe\EUWithdrawal\Model\Item\ReturnableItemsResolver;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderItemInterface;

class RefundCalculator
{
    public function __construct(
        private readonly ReturnableItemsResolver $returnableItems,
        private readonly ItemAmountResolver $itemAmounts,
        private readonly ShippingAmountResolver $shippingAmounts,
        private readonly BundleRefundedQtyResolver $refundedQty,
    ) {
    }

    /**
     * @param array<int,int> $items order_item_id => qty
     * @param array<int,int> $heldByOid order_item_id => qty already held by other
     *        active (pending/approved) withdrawal requests. A withdrawal is a full
     *        contract withdrawal — and owes the delivery under Art. 14(2) — when it
     *        takes the last units the consumer still holds, which excludes those
     *        another request is already returning. The storefront form subtracts
     *        the same holds from what it offers, so passing them keeps the server's
     *        full/partial verdict in step with the figure the consumer consented to.
     */
    public function calculate(
        OrderInterface $order,
        array $items,
        EligibilityResultInterface $eligibility,
        array $heldByOid = [],
    ): RefundBreakdown {
        if ($items === []) {
            throw new \InvalidArgumentException('items must not be empty');
        }

        $orderItems = [];
        foreach (($order->getItems() ?? []) as $oi) {
            $orderItems[(int) $oi->getItemId()] = $oi;
        }

        $lines = [];
        $itemsSubtotal = 0.0;
        $taxRefund = 0.0;

        foreach ($items as $oid => $qty) {
            if (!is_int($qty) || $qty <= 0) {
                throw new \InvalidArgumentException(
                    sprintf('qty must be positive int, got %s for oid %d', var_export($qty, true), $oid),
                );
            }
            if (!isset($orderItems[$oid])) {
                throw new \InvalidArgumentException(sprintf('order_item_id %d not in order', $oid));
            }
            $oi = $orderItems[$oid];
            $ordered = (float) $oi->getQtyOrdered();
            // Only the quantity still in the consumer's hands is returnable:
            // units already cancelled before invoice or already refunded via a
            // native Magento credit-memo were never paid for (or were paid back)
            // and must not be refunded again. The per-unit price divisor stays
            // `ordered` so the unit amount (row_total / ordered) is unchanged.
            $returnable = $this->returnableQty($order, $oi);
            if ($qty > $returnable) {
                throw new \InvalidArgumentException(
                    sprintf('qty %d exceeds returnable %f for oid %d', $qty, $returnable, $oid),
                );
            }

            $amounts = $this->itemAmounts->resolve($order, $oi);

            $lineSubtotal = $this->round4($qty * $amounts->net() / $ordered);
            $lineTax = $this->round4($qty * $amounts->taxTotal() / $ordered);
            $unitDisplay = $ordered > 0 ? $this->round4($amounts->net() / $ordered) : 0.0;

            $lines[] = new ItemRefundLine(
                orderItemId: $oid,
                sku: (string) $oi->getSku(),
                name: (string) $oi->getName(),
                qty: $qty,
                unitDisplayPrice: $unitDisplay,
                lineSubtotal: $lineSubtotal,
                lineTax: $lineTax,
            );
            $itemsSubtotal += $lineSubtotal;
            $taxRefund += $lineTax;
        }

        $isFullReturn = $this->isFullEligibleReturn($order, $orderItems, $items, $eligibility, $heldByOid);

        // EU Art. 14(2) Directive 2011/83 — on full withdrawal the merchant
        // refunds all sums received from the consumer including delivery
        // costs. The delivery cost paid is the discounted one, and its VAT
        // must be included or the consumer is shorted the tax portion. Bake
        // shipping VAT into `shippingRefund` (rather than into `taxRefund`) so
        // the gross-shipping total persists into
        // `mm_eu_withdrawal_request.shipping_refund` — the schema has no
        // separate shipping-tax column and total is recomputed at admin-grid
        // render time as `SUM(item.refund_amount) + request.shipping_refund`.
        //
        // Only what a native credit memo has not already paid back: carriage can
        // be refunded on its own, without touching a single item quantity.
        $shipping = $this->shippingAmounts->resolveRefundable($order);
        $shippingNet = $isFullReturn ? $shipping->net() : 0.0;
        $shippingTax = $isFullReturn ? $shipping->taxTotal() : 0.0;
        $shippingRefund = $this->round4($shippingNet + $shippingTax);

        $itemsSubtotal = $this->round4($itemsSubtotal);
        $taxRefund = $this->round4($taxRefund);
        $total = $this->round4($itemsSubtotal + $taxRefund + $shippingRefund);

        $grandTotal = $this->round4((float) $order->getGrandTotal());

        // Order-level totals (payment-method discount, gift card, custom-total
        // collectors) move grand_total without distributing to item
        // discount_amount. Distribute that gap by the returned share so a full
        // return reconciles to grand_total exactly and a partial return takes a
        // proportional slice. Zero for standard orders.
        ['base' => $orderItemsBase, 'gap' => $orderLevelGap] = $this->orderLevelGap($order);
        $orderAdjustment = 0.0;
        if (abs($orderLevelGap) > 0.005 && $orderItemsBase > 0.0) {
            $orderAdjustment = $this->round4($orderLevelGap * ($itemsSubtotal / $orderItemsBase));
            $total = $this->round4($total + $orderAdjustment);
        }

        // Epsilon 0.01 (one cent) accommodates Magento's 2-decimal rounding of
        // grand_total vs our 4-decimal sum of parts. Tighter values risk false
        // positives on valid orders with inclusive-tax rounding.
        //
        // Deliberately measured against grand_total and not against
        // `grand_total - total_refunded`: a credit memo's `adjustment_positive`
        // is goodwill, not a prepayment of a later withdrawal, and subtracting
        // it would reject the units the consumer still legitimately holds.
        // Over-refund is prevented per component instead — items by
        // `qty_refunded`, delivery by ShippingAmountResolver::resolveRefundable().
        if ($total > $grandTotal + 0.01) {
            throw new \LogicException(
                sprintf('Refund post-condition violated: total %f > grand_total %f', $total, $grandTotal),
            );
        }

        return new RefundBreakdown(
            items: $lines,
            itemsSubtotal: $itemsSubtotal,
            shippingRefund: $shippingRefund,
            taxRefund: $taxRefund,
            total: $total,
            currency: (string) $order->getOrderCurrencyCode(),
            isFullReturn: $isFullReturn,
            orderAdjustmentRefund: $orderAdjustment,
            shippingTaxRefund: $shippingTax,
        );
    }

    /**
     * Order-level item base (net, parents only) and the gross gap between
     * grand_total and what the item + shipping fields account for. The gap is
     * non-zero when a payment-method discount, gift card or custom-total
     * collector moved grand_total without touching item discount_amount. Shared
     * by calculate() and the storefront sidebar preview so both distribute the
     * same gap.
     *
     * @return array{base: float, gap: float}
     */
    public function orderLevelGap(OrderInterface $order): array
    {
        $base = 0.0;
        $tax = 0.0;
        // Sum the same returnable line set that calculate() sums, through the
        // same amount resolver, so the reconciliation base stays consistent
        // with itemsSubtotal. Anything left over is genuine order-level money.
        foreach ($this->returnableItems->resolve($order) as $oi) {
            $amounts = $this->itemAmounts->resolve($order, $oi);
            $base += $amounts->net();
            $tax += $amounts->taxTotal();
        }

        $shipping = $this->shippingAmounts->resolve($order);
        $gap = $this->round4(
            (float) $order->getGrandTotal()
            - $base
            - $tax
            - $shipping->net()
            - $shipping->taxTotal(),
        );

        return ['base' => $this->round4($base), 'gap' => $gap];
    }

    /**
     * @param OrderInterface $order
     * @param array<int, OrderItemInterface> $orderItems
     * @param array<int, int> $items
     */
    private function isFullEligibleReturn(
        OrderInterface $order,
        array $orderItems,
        array $items,
        EligibilityResultInterface $eligibility,
        array $heldByOid = [],
    ): bool {
        $decisions = $eligibility->getItemDecisions();
        $eligibleOids = [];
        foreach ($decisions as $oid => $d) {
            if ($d->isEligible()) {
                $eligibleOids[(int) $oid] = true;
            }
        }

        foreach ($items as $oid => $_qty) {
            if (!isset($eligibleOids[$oid])) {
                return false;
            }
        }

        foreach (array_keys($eligibleOids) as $oid) {
            // A line with nothing left for THIS request to take — fully cancelled,
            // fully refunded, or every remaining unit already held by another
            // active request — cannot appear here (a quantity must be positive).
            // Requiring it would make the order permanently un-fully-returnable and
            // withhold the delivery for ever.
            if (isset($orderItems[$oid])
                && $this->returnableQty($order, $orderItems[$oid], (int) ($heldByOid[$oid] ?? 0)) <= 0.0
            ) {
                continue;
            }
            if (!isset($items[$oid])) {
                return false;
            }
        }

        // Every unit the consumer still holds, not every unit the order once
        // listed. A unit cancelled before invoice was never paid for, and one a
        // native credit memo already refunded is back in the consumer's pocket —
        // returning the rest is still a withdrawal from the whole contract, and
        // Art. 14(2) owes the delivery back.
        //
        // Compared as floats: a fractional remainder must never truncate into
        // fullness, or half a unit still in the consumer's hands would buy them
        // the whole delivery back.
        foreach ($items as $oid => $qty) {
            if (abs($qty - $this->returnableQty($order, $orderItems[$oid], (int) ($heldByOid[$oid] ?? 0))) > 0.0001) {
                return false;
            }
        }

        return true;
    }

    /**
     * Units this request can still take: ordered, less those cancelled before
     * invoice, those a native credit memo already refunded, and those another
     * active withdrawal request is already returning.
     *
     * @param OrderInterface $order
     * @param OrderItemInterface $item
     * @param int $held Units held by other active requests.
     * @return float
     */
    private function returnableQty(OrderInterface $order, OrderItemInterface $item, int $held = 0): float
    {
        return (float) $item->getQtyOrdered()
            - (float) ($item->getQtyCanceled() ?? 0.0)
            - $this->refundedQty->refundedQty($order, $item)
            - (float) $held;
    }

    /**
     * Round4.
     *
     * @param float $value
     * @return float
     */
    private function round4(float $value): float
    {
        return round($value, 4, PHP_ROUND_HALF_EVEN);
    }
}
