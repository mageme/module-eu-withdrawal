<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Refund;

use MageMe\EUWithdrawal\Api\Data\EligibilityResultInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderItemInterface;

class RefundCalculator
{
    /**
     * @param array<int,int> $items order_item_id => qty
     */
    public function calculate(
        OrderInterface $order,
        array $items,
        EligibilityResultInterface $eligibility,
    ): RefundBreakdown {
        if ($items === []) {
            throw new \InvalidArgumentException('items must not be empty');
        }

        $orderItems     = [];
        $orderItemsBase = 0.0;
        $orderItemsTax  = 0.0;
        foreach (($order->getItems() ?? []) as $oi) {
            $orderItems[(int) $oi->getItemId()] = $oi;
            if ($oi->getParentItemId() !== null) {
                continue; // children carry price=0; parent holds the row total
            }
            $orderItemsBase += (float) $oi->getRowTotal() - (float) $oi->getDiscountAmount();
            $orderItemsTax  += (float) $oi->getTaxAmount();
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
            if ($qty > $ordered) {
                throw new \InvalidArgumentException(
                    sprintf('qty %d exceeds ordered %f for oid %d', $qty, $ordered, $oid),
                );
            }

            $rowTotal = (float) $oi->getRowTotal();
            $discount = (float) $oi->getDiscountAmount();
            $tax = (float) $oi->getTaxAmount();

            $lineSubtotal = $this->round4($qty * ($rowTotal - $discount) / $ordered);
            $lineTax = $this->round4($qty * $tax / $ordered);
            $unitDisplay = $ordered > 0 ? $this->round4(($rowTotal - $discount) / $ordered) : 0.0;

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

        $isFullReturn = $this->isFullEligibleReturn($orderItems, $items, $eligibility);

        // EU Art. 14(2) Directive 2011/83 — on full withdrawal the merchant
        // refunds all sums received from the consumer including delivery
        // costs. Magento splits delivery cost into `shipping_amount` (net) +
        // `shipping_tax_amount` (VAT), so a full refund must include BOTH or
        // the consumer is shorted the tax portion. Bake shipping VAT into
        // `shippingRefund` (rather than into `taxRefund`) so the gross-shipping
        // total persists into `mm_eu_withdrawal_request.shipping_refund` —
        // the schema has no separate shipping-tax column and total is
        // recomputed at admin-grid render time as
        // `SUM(item.refund_amount) + request.shipping_refund`.
        $shippingNet = $isFullReturn ? (float) $order->getShippingAmount() : 0.0;
        $shippingTax = $isFullReturn ? (float) $order->getShippingTaxAmount() : 0.0;
        $shippingRefund = $this->round4($shippingNet + $shippingTax);

        $itemsSubtotal = $this->round4($itemsSubtotal);
        $taxRefund = $this->round4($taxRefund);
        $total = $this->round4($itemsSubtotal + $taxRefund + $shippingRefund);

        $grandTotal = $this->round4((float) $order->getGrandTotal());

        // Detect order-level adjustments that are not captured in item-level
        // fields — e.g. payment-method discounts, gift cards, or any custom
        // total collector that modifies grand_total without distributing to
        // item discount_amount.  The gap is the authoritative grand_total minus
        // the sum that item fields account for.  Distribute it proportionally
        // to the fraction of the order's item value being returned, so that a
        // partial return gets a fair share and a full return always matches
        // grand_total exactly.
        $orderLevelGap = $this->round4(
            $grandTotal
            - $orderItemsBase
            - $orderItemsTax
            - (float) $order->getShippingAmount()
            - (float) $order->getShippingTaxAmount(),
        );
        if (abs($orderLevelGap) > 0.005 && $orderItemsBase > 0.0) {
            $ratio = $itemsSubtotal / $orderItemsBase;
            $total = $this->round4($total + $orderLevelGap * $ratio);
        }

        // Epsilon 0.01 (one cent) accommodates Magento's 2-decimal rounding of
        // grand_total vs our 4-decimal sum of parts. Tighter values risk false
        // positives on valid orders with inclusive-tax rounding.
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
        );
    }

    /**
     * @param array<int, OrderItemInterface> $orderItems
     * @param array<int, int> $items
     */
    private function isFullEligibleReturn(
        array $orderItems,
        array $items,
        EligibilityResultInterface $eligibility,
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
            if (!isset($items[$oid])) {
                return false;
            }
        }

        foreach ($items as $oid => $qty) {
            $ordered = (int) ((float) $orderItems[$oid]->getQtyOrdered());
            if ($qty !== $ordered) {
                return false;
            }
        }

        return true;
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
