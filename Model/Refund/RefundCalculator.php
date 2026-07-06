<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Refund;

use MageMe\EUWithdrawal\Api\Data\EligibilityResultInterface;
use MageMe\EUWithdrawal\Model\Item\ReturnableItemsResolver;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderItemInterface;

class RefundCalculator
{
    public function __construct(private readonly ReturnableItemsResolver $returnableItems)
    {
    }

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
            $returnable = $ordered
                - (float) ($oi->getQtyCanceled() ?? 0.0)
                - (float) ($oi->getQtyRefunded() ?? 0.0);
            if ($qty > $returnable) {
                throw new \InvalidArgumentException(
                    sprintf('qty %d exceeds returnable %f for oid %d', $qty, $returnable, $oid),
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
        // Sum the same returnable line set that calculate() sums, so the
        // reconciliation base stays net/gross-consistent with itemsSubtotal.
        // For an expanded dynamic bundle the discount and tax live on the
        // children, so summing parents only would double-count them.
        foreach ($this->returnableItems->resolve($order) as $oi) {
            $base += (float) $oi->getRowTotal() - (float) $oi->getDiscountAmount();
            $tax += (float) $oi->getTaxAmount();
        }

        $gap = $this->round4(
            (float) $order->getGrandTotal()
            - $base
            - $tax
            - (float) $order->getShippingAmount()
            - (float) $order->getShippingTaxAmount(),
        );

        return ['base' => $this->round4($base), 'gap' => $gap];
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
