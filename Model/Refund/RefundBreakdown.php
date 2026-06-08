<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Refund;

use MageMe\EUWithdrawal\Api\Data\RefundBreakdownInterface;

class RefundBreakdown implements RefundBreakdownInterface
{
    /**
     * @param ItemRefundLine[] $items
     */
    public function __construct(
        private readonly array $items,
        private readonly float $itemsSubtotal,
        private readonly float $shippingRefund,
        private readonly float $taxRefund,
        private readonly float $total,
        private readonly string $currency,
        private readonly bool $isFullReturn,
        private readonly float $orderAdjustmentRefund = 0.0,
    ) {
    }

    /**
     * Get items.
     *
     * @return array
     */
    public function getItems(): array
    {
        return $this->items;
    }

    /**
     * Get items subtotal.
     *
     * @return float
     */
    public function getItemsSubtotal(): float
    {
        return $this->itemsSubtotal;
    }

    /**
     * Get shipping refund.
     *
     * @return float
     */
    public function getShippingRefund(): float
    {
        return $this->shippingRefund;
    }

    /**
     * Get tax refund.
     *
     * @return float
     */
    public function getTaxRefund(): float
    {
        return $this->taxRefund;
    }

    /**
     * Get order adjustment refund.
     *
     * @return float
     */
    public function getOrderAdjustmentRefund(): float
    {
        return $this->orderAdjustmentRefund;
    }

    /**
     * Get total.
     *
     * @return float
     */
    public function getTotal(): float
    {
        return $this->total;
    }

    /**
     * Get currency.
     *
     * @return string
     */
    public function getCurrency(): string
    {
        return $this->currency;
    }

    /**
     * Is full return.
     *
     * @return bool
     */
    public function isFullReturn(): bool
    {
        return $this->isFullReturn;
    }

    /**
     * To array.
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            self::ITEMS => array_map(fn (ItemRefundLine $l) => $l->toArray(), $this->items),
            self::ITEMS_SUBTOTAL => $this->itemsSubtotal,
            self::SHIPPING_REFUND => $this->shippingRefund,
            self::TAX_REFUND => $this->taxRefund,
            self::ORDER_ADJUSTMENT_REFUND => $this->orderAdjustmentRefund,
            self::TOTAL => $this->total,
            self::CURRENCY => $this->currency,
            self::IS_FULL_RETURN => $this->isFullReturn,
        ];
    }
}
