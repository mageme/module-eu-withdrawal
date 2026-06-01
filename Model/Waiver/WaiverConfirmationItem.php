<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Waiver;

class WaiverConfirmationItem
{
    /** toArray() keys. */
    public const ORDER_ITEM_ID = 'order_item_id';
    public const SKU           = 'sku';
    public const NAME          = 'name';
    public const PRICE         = 'price';
    public const PRODUCT_TYPE  = 'product_type';

    /**
     * Constructor.
     *
     * @param int $orderItemId
     * @param string $sku
     * @param string $name
     * @param string $price
     * @param string $productType
     */
    public function __construct(
        public readonly int $orderItemId,
        public readonly string $sku,
        public readonly string $name,
        public readonly string $price,
        public readonly string $productType,
    ) {
    }

    /**
     * To array.
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            self::ORDER_ITEM_ID => $this->orderItemId,
            self::SKU => $this->sku,
            self::NAME => $this->name,
            self::PRICE => $this->price,
            self::PRODUCT_TYPE => $this->productType,
        ];
    }
}
