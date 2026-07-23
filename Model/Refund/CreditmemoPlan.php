<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Refund;

class CreditmemoPlan
{
    public function __construct(
        public readonly ?int $invoiceId,
        public readonly array $items, // array<int,float> oid=>qty
        public readonly ?float $shippingAmount, // null=omit, 0.0=suppress
        public readonly float $expectedTotal,
        public readonly string $currency,
        public readonly bool $autoEligible,
        public readonly ?string $manualReason,
        public readonly array $diagnostics, // string[]
    ) {
    }

    public function isAutoIssuable(): bool
    {
        return $this->autoEligible && $this->invoiceId !== null && $this->items !== [];
    }
}
