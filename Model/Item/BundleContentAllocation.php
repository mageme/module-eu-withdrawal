<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Item;

/**
 * A bundle component as it appears for one concrete withdrawal: how many of it
 * travel back, and how much of the bundle line's refund it accounts for.
 */
class BundleContentAllocation
{
    /**
     * @param BundleContentLine $line
     * @param float $qty Components going back for the quantity being withdrawn.
     * @param ?float $amount Gross share of the bundle line's refund, or null
     *        when the bundle prices on the parent and the component has no
     *        share of its own to quote.
     */
    public function __construct(
        public readonly BundleContentLine $line,
        public readonly float $qty,
        public readonly ?float $amount,
    ) {
    }
}
