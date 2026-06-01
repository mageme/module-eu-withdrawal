<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Refund;

class ItemRefundLine
{
    /** toArray() keys. */
    public const ORDER_ITEM_ID     = 'order_item_id';
    public const SKU               = 'sku';
    public const NAME              = 'name';
    public const QTY               = 'qty';
    public const UNIT_DISPLAY_PRICE = 'unit_display_price';
    public const LINE_SUBTOTAL     = 'line_subtotal';
    public const LINE_TAX          = 'line_tax';

    /**
     * Constructor.
     *
     * @param int $orderItemId
     * @param string $sku
     * @param string $name
     * @param int $qty
     * @param float $unitDisplayPrice
     * @param float $lineSubtotal
     * @param float $lineTax
     */
    public function __construct(
        public readonly int $orderItemId,
        public readonly string $sku,
        public readonly string $name,
        public readonly int $qty,
        public readonly float $unitDisplayPrice,
        public readonly float $lineSubtotal,
        public readonly float $lineTax,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            self::ORDER_ITEM_ID => $this->orderItemId,
            self::SKU => $this->sku,
            self::NAME => $this->name,
            self::QTY => $this->qty,
            self::UNIT_DISPLAY_PRICE => $this->unitDisplayPrice,
            self::LINE_SUBTOTAL => $this->lineSubtotal,
            self::LINE_TAX => $this->lineTax,
        ];
    }
}
