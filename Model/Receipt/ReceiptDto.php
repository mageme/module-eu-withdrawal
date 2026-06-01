<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Receipt;

class ReceiptDto
{
    /** toArray() keys (forensic-canonicalized; values are byte-identical to the prior literals). */
    public const CONSUMER = 'consumer';
    public const ORDER    = 'order';
    public const ITEMS    = 'items';
    public const REFUND   = 'refund';
    public const RECEIPT  = 'receipt';
    public const MERCHANT = 'merchant';
    public const LEGAL    = 'legal';

    /**
     * @param array{name:string,email:string,reason:?string} $consumer
     * @param array{increment_id:string,created_at:string,total:string} $order
     * @param array<int, array{order_item_id:int,sku:string,qty:int,refund_amount:string}> $items
     * @param array{items:string,shipping:string,tax:string,total:string} $refund
     * @param array{created_at:string,confirmed_at:string,locale:string,ip_hash:string,user_agent:string} $receipt
     * @param array{name:string,vat_id:string,address:string} $merchant
     * @param array{withdrawal_period_days:int,article_ref:string} $legal
     */
    public function __construct(
        public readonly int $requestId,
        public readonly array $consumer,
        public readonly array $order,
        public readonly array $items,
        public readonly array $refund,
        public readonly array $receipt,
        public readonly array $merchant,
        public readonly array $legal,
    ) {
    }

    /**
     * To array.
     *
     * @return array
     */
    public function toArray(): array
    {
        $items = $this->items;
        usort($items, static fn($a, $b) => $a['order_item_id'] <=> $b['order_item_id']);
        return [
            self::CONSUMER => $this->consumer,
            self::ORDER    => $this->order,
            self::ITEMS    => array_values($items),
            self::REFUND   => $this->refund,
            self::RECEIPT  => $this->receipt,
            self::MERCHANT => $this->merchant,
            self::LEGAL    => $this->legal,
        ];
    }
}
