<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Item;

/**
 * One component of a bundle that is returned as a single unit. Describes what
 * physically travels back inside the parcel, so the consumer can see that a
 * one-line bundle is in fact several goods.
 *
 * Amounts are row-level (the whole ordered quantity of the parent), never
 * per-unit: callers prorate them for the quantity actually being withdrawn,
 * running the same single-round arithmetic RefundCalculator does.
 */
class BundleContentLine
{
    /**
     * @param int $orderItemId
     * @param string $name Plain text, entities decoded (see ProductNameDecoder).
     *        Not safe HTML — escape on output.
     * @param string $sku
     * @param float $qtyPerParentUnit How many of this component one bundle holds.
     * @param float $rowNet Net actually paid for the component row, discount
     *        deducted. Zero when the bundle prices on the parent.
     * @param float $rowTax Tax actually paid on the component row.
     * @param bool $priced Whether the component carries its own share of the
     *        price. False for a fixed-price bundle, whose children cost 0 and
     *        must read "Included" rather than a misleading zero.
     * @param bool $physical False for a virtual or downloadable component —
     *        there is nothing to ship back for it.
     * @param ?SealedComponent $sealed Seal state, when the component is sealed.
     */
    public function __construct(
        public readonly int $orderItemId,
        public readonly string $name,
        public readonly string $sku,
        public readonly float $qtyPerParentUnit,
        public readonly float $rowNet,
        public readonly float $rowTax,
        public readonly bool $priced,
        public readonly bool $physical,
        public readonly ?SealedComponent $sealed = null,
    ) {
    }

    /**
     * Gross row amount the component contributes to the bundle.
     *
     * @return float
     */
    public function rowGross(): float
    {
        return $this->rowNet + $this->rowTax;
    }
}
