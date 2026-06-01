<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Item;

class RemainingItemState
{
    public const ELIGIBILITY_ELIGIBLE = 'ELIGIBLE';
    public const ELIGIBILITY_EXCLUDED = 'EXCLUDED';

    /**
     * Constructor.
     *
     * @param int $orderItemId
     * @param string $sku
     * @param string $name
     * @param int $purchasedQty
     * @param int $remainingQty
     * @param int $pendingQty
     * @param int $alreadyWithdrawnQty
     * @param float $unitDisplayPrice
     * @param string $eligibility
     * @param ?string $exclusionReason
     */
    public function __construct(
        public readonly int $orderItemId,
        public readonly string $sku,
        public readonly string $name,
        public readonly int $purchasedQty,
        public readonly int $remainingQty,
        public readonly int $pendingQty,
        public readonly int $alreadyWithdrawnQty,
        public readonly float $unitDisplayPrice,
        public readonly string $eligibility,
        public readonly ?string $exclusionReason,
    ) {
    }

    /**
     * Is eligible.
     *
     * @return bool
     */
    public function isEligible(): bool
    {
        return $this->eligibility === self::ELIGIBILITY_ELIGIBLE && $this->remainingQty > 0;
    }
}
