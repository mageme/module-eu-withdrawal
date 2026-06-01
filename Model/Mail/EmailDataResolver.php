<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Mail;

use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Sales\Api\OrderRepositoryInterface;

/**
 * Shared resolver for common email-template variables that depend on the
 * order/request context: formatted dates, currency-formatted refund totals,
 * and the resolved payment / refund method label. Used by every customer
 * email sender (notification, status-change, receipt) so the visible details
 * are consistent across the family.
 */
class EmailDataResolver
{
    /**
     * Constructor.
     *
     * @param OrderRepositoryInterface $orderRepository
     * @param PriceCurrencyInterface $priceCurrency
     * @param TimezoneInterface $timezone
     */
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly PriceCurrencyInterface $priceCurrency,
        private readonly TimezoneInterface $timezone,
    ) {
    }

    /**
     * "May 28, 2025" — medium date in store locale.
     */
    public function formatDate(?string $mysqlDate, int $storeId): string
    {
        if ($mysqlDate === null || $mysqlDate === '') {
            return '';
        }
        try {
            $dt = $this->timezone->date(new \DateTimeImmutable($mysqlDate, new \DateTimeZone('UTC')));
            return (string) $this->timezone->formatDate(
                $dt,
                \IntlDateFormatter::MEDIUM,
                false,
            );
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * "May 28, 2025 14:32 UTC" — used by the receipt for the legal timestamp.
     */
    public function formatDateTimeUtc(?string $mysqlDate): string
    {
        if ($mysqlDate === null || $mysqlDate === '') {
            return '';
        }
        try {
            $dt = new \DateTimeImmutable($mysqlDate, new \DateTimeZone('UTC'));
            return $dt->format('M j, Y H:i') . ' UTC';
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Currency-formatted price for the given store. `$amount` is a numeric
     * string (e.g. "70.00") in the store's base currency.
     */
    public function formatPrice(string $amount, int $storeId, ?string $currencyCode = null): string
    {
        $value = (float) $amount;
        $store = null;
        if ($storeId > 0) {
            try {
                $store = $storeId;
            } catch (\Throwable) {
                $store = null;
            }
        }
        return (string) $this->priceCurrency->format(
            $value,
            false,
            PriceCurrencyInterface::DEFAULT_PRECISION,
            $store,
            $currencyCode,
        );
    }

    /**
     * Resolves the refund method label from the order's payment. Falls back
     * to "Original payment method" when nothing more specific is available
     * (matches the design language of the customer-facing mockup).
     */
    public function getRefundMethod(int $orderId): string
    {
        if ($orderId <= 0) {
            return (string) __('Original payment method');
        }
        try {
            $order = $this->orderRepository->get($orderId);
        } catch (\Throwable) {
            return (string) __('Original payment method');
        }

        $payment = $order->getPayment();
        if ($payment === null) {
            return (string) __('Original payment method');
        }

        try {
            $title = (string) ($payment->getMethodInstance()->getTitle() ?? '');
        } catch (\Throwable) {
            $title = '';
        }
        if ($title === '') {
            $info = $payment->getAdditionalInformation();
            if (is_array($info) && !empty($info['method_title'])) {
                $title = (string) $info['method_title'];
            }
        }
        if ($title === '') {
            $title = (string) ($payment->getMethod() ?? __('Original payment method'));
        }

        $last4 = (string) ($payment->getCcLast4() ?? '');
        $ccType = (string) ($payment->getCcType() ?? '');
        if ($last4 !== '') {
            $brand = $ccType !== '' ? strtoupper($ccType) : $title;
            return sprintf('%s •••• %s', $brand, $last4);
        }
        return $title;
    }
}
