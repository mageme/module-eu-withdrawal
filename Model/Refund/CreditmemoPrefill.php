<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Refund;

/**
 * Prefill data for the admin "New Credit Memo" form, computed for every
 * approved request regardless of the auto-issue gates. Unlike CreditmemoPlan
 * this always carries an invoice to prefill against (the single covering paid
 * invoice, or the first invoice as a fallback) so the admin never loses its
 * form even on partial or multi-invoice orders.
 */
class CreditmemoPrefill
{
    public function __construct(
        public readonly ?int $invoiceId,
        public readonly array $items, // array<int,float> oid=>qty
        public readonly ?float $shippingAmount, // null=omit, 0.0=suppress
        public readonly float $expectedTotal,
        public readonly bool $coverageClean,
    ) {
    }
}
