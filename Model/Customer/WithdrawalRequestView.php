<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Customer;

class WithdrawalRequestView
{
    /**
     * @param array<int, array{order_item_id:int,sku:string,name:string,qty:int,refund_amount:string,eligibility:string,reason_code:?string,reason_text:?string}> $items
     */
    public function __construct(
        public readonly int $requestId,
        public readonly string $incrementId,
        public readonly string $status,
        public readonly string $submittedAt,
        public readonly array $items,
        public readonly string $refundTotal,
        public readonly string $currency,
        public readonly bool $cancellable,
        public readonly ?string $statusChangeNote = null,
        public readonly ?string $statusChangeLegalBasis = null,
        public readonly ?string $statusChangeActor = null,
    ) {
    }
}
