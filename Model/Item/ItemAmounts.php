<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Item;

/**
 * The monetary fields a returnable unit is worth, already attributed to that
 * unit. Mirrors Magento's own credit-memo arithmetic:
 * gross = row_total - discount + tax + discount_tax_compensation + FPT + FPT VAT.
 *
 * The fixed product tax sits outside `row_total` and outside `tax_amount` — its
 * own collector puts it straight into `grand_total`. It is money the consumer
 * paid for this line, so it belongs to this line and not to an order-level
 * remainder prorated by net value.
 */
class ItemAmounts
{
    /**
     * Constructor.
     *
     * @param float $rowTotal Ex-tax, before discount.
     * @param float $discount Ex-tax discount.
     * @param float $tax
     * @param float $discountTaxCompensation Tax the discount did not reduce.
     * @param float $weee Fixed product tax charged on the row, ex its own VAT.
     * @param float $weeeTax VAT charged on the fixed product tax. Zero unless
     *        the store has "Apply Tax To FPT" on.
     */
    public function __construct(
        public readonly float $rowTotal,
        public readonly float $discount,
        public readonly float $tax,
        public readonly float $discountTaxCompensation,
        public readonly float $weee = 0.0,
        public readonly float $weeeTax = 0.0,
    ) {
    }

    /**
     * Ex-tax amount actually paid for the row, fixed product tax included: it is
     * a charge on the product, not a tax on the price.
     *
     * @return float
     */
    public function net(): float
    {
        return $this->rowTotal - $this->discount + $this->weee;
    }

    /**
     * Tax actually paid on the row, including any VAT levied on the fixed
     * product tax.
     *
     * @return float
     */
    public function taxTotal(): float
    {
        return $this->tax + $this->discountTaxCompensation + $this->weeeTax;
    }
}
