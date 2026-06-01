<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Api\Data;

interface RefundBreakdownInterface
{
    /** toArray() keys. */
    public const ITEMS          = 'items';
    public const ITEMS_SUBTOTAL = 'items_subtotal';
    public const SHIPPING_REFUND = 'shipping_refund';
    public const TAX_REFUND     = 'tax_refund';
    public const TOTAL          = 'total';
    public const CURRENCY       = 'currency';
    public const IS_FULL_RETURN = 'is_full_return';

    /** @return \MageMe\EUWithdrawal\Model\Refund\ItemRefundLine[] concrete type; no interface in the base module tier */
    public function getItems(): array;

    /**
     * Get items subtotal.
     *
     * @return float
     */
    public function getItemsSubtotal(): float;

    /**
     * Get shipping refund.
     *
     * @return float
     */
    public function getShippingRefund(): float;

    /**
     * Get tax refund.
     *
     * @return float
     */
    public function getTaxRefund(): float;

    /**
     * Get total.
     *
     * @return float
     */
    public function getTotal(): float;

    /**
     * Get currency.
     *
     * @return string
     */
    public function getCurrency(): string;

    /**
     * Is full return.
     *
     * @return bool
     */
    public function isFullReturn(): bool;

    /** @return array<string, mixed> */
    public function toArray(): array;
}
