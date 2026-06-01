<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Waiver;

class WaiverItem
{
    /**
     * Constructor.
     *
     * @param int $quoteItemId
     * @param string $sku
     * @param string $name
     * @param string $price
     * @param string $productType
     */
    public function __construct(
        public readonly int $quoteItemId,
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
            'quote_item_id' => $this->quoteItemId,
            'sku' => $this->sku,
            'name' => $this->name,
            'price' => $this->price,
            'product_type' => $this->productType,
        ];
    }
}
