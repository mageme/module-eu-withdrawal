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
     * @param string $name Plain text, entities already decoded (see ProductNameDecoder).
     *        Not safe HTML — escape on output, or write it to `textContent`.
     * @param int $purchasedQty
     * @param int $remainingQty
     * @param int $pendingQty
     * @param int $alreadyWithdrawnQty
     * @param float $unitDisplayPrice Net (ex-tax) per-unit price actually paid.
     * @param string $eligibility
     * @param ?string $exclusionReason
     * @param float $rowTaxAmount Tax actually paid on the whole row, including
     *        discount-tax compensation. Row-level, not per-unit, so clients can
     *        run the same single-round `qty * rowTax / qtyOrdered` the server does.
     * @param ?float $rowNetAmount Net actually paid on the whole row, discount
     *        deducted. Row-level for the same reason: multiplying the already
     *        rounded `unitDisplayPrice` drifts from the server's proration.
     *        Null — never 0.0 — when the producer did not supply it, so a
     *        consumer can tell "absent" from "this row cost nothing" and fall
     *        back instead of quoting a free line.
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
        public readonly float $rowTaxAmount = 0.0,
        public readonly ?float $rowNetAmount = null,
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
