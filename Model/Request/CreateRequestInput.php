<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Request;

class CreateRequestInput
{
    /**
     * @param array<int, int> $items order_item_id => qty. Empty = "full eligible" default.
     * @param array<int, array{code: ?string, text: ?string}> $itemReasons
     *        order_item_id => per-item reason. Both fields nullable; absent oid = no reason.
     */
    public function __construct(
        public readonly string $orderIncrementId,
        public readonly string $customerName,
        public readonly string $customerEmail,
        public readonly ?string $reasonText,
        public readonly string $jurisdiction,
        public readonly string $locale,
        public readonly ?string $ip,
        public readonly ?string $userAgent,
        public readonly ?int $customerId,
        public readonly array $items = [],
        public readonly array $itemReasons = [],
        public readonly ?string $referrerHost = null,
        /** @var array<int, bool> $sealAnswers subject order_item_id => opened? */
        public readonly array $sealAnswers = [],
    ) {
    }
}
