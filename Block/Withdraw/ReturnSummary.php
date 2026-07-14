<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Block\Withdraw;

use MageMe\EUWithdrawal\Model\Frontend\TaxDisplayConfig;
use MageMe\EUWithdrawal\Model\Refund\ShippingAmountResolver;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Sales\Api\Data\OrderInterface;

class ReturnSummary extends Template
{
    /**
     * Constructor.
     *
     * @param Context $context
     * @param PriceCurrencyInterface $priceCurrency
     * @param array $data
     */
    public function __construct(
        Context $context,
        private readonly PriceCurrencyInterface $priceCurrency,
        private readonly TaxDisplayConfig $taxDisplay,
        private readonly ShippingAmountResolver $shippingAmounts,
        array $data = [],
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Whether the store displays sales prices including tax.
     *
     * @deprecated The refund summary always quotes gross figures now.
     * @see self::isVatLineHidden()
     * @return bool
     */
    public function isInclTaxDisplay(): bool
    {
        return $this->taxDisplay->showsGrossFigures();
    }

    /**
     * Whether the standalone tax line is suppressed (incl mode with tax folded
     * into the grand total, mirroring the store's sales-display setting).
     *
     * @deprecated Retained for released Hyvä companions that call it through
     *     method_exists(). The summary uses isVatLineHidden().
     * @see self::isVatLineHidden()
     * @return bool
     */
    public function isTaxLineHidden(): bool
    {
        return $this->taxDisplay->isTaxLineHidden();
    }

    /**
     * Whether the informational VAT row is suppressed. The summary quotes gross
     * figures, so the row is a breakdown rather than an addend and folds away
     * exactly when the store folds tax into the grand total.
     *
     * @return bool
     */
    public function isVatLineHidden(): bool
    {
        return $this->taxDisplay->isTaxFoldedIntoTotal();
    }

    /**
     * Get order.
     *
     * @return ?OrderInterface
     */
    public function getOrder(): ?OrderInterface
    {
        $o = $this->getData('order');
        return $o instanceof OrderInterface ? $o : null;
    }

    /**
     * @return array{items_total:float, shipping_paid:float, shipping_refund:float, total_refund:float}
     */
    public function getInitialTotals(): array
    {
        $order = $this->getOrder();
        return [
            'items_total'     => 0.0,
            'tax'             => 0.0,
            'shipping_paid'   => $order !== null ? $this->shippingPaidGross($order) : 0.0,
            'shipping_refund' => 0.0,
            'total_refund'    => 0.0,
        ];
    }

    /**
     * Delivery still refundable, VAT included: the discounted cost the consumer
     * paid, less whatever a native credit memo already returned. Seeds the first
     * paint; the JS summary recomputes it per selection.
     *
     * @param OrderInterface $order
     * @return float
     */
    private function shippingPaidGross(OrderInterface $order): float
    {
        $shipping = $this->shippingAmounts->resolveRefundable($order);
        return round($shipping->net() + $shipping->taxTotal(), 4, PHP_ROUND_HALF_EVEN);
    }

    /**
     * Format price.
     *
     * @param float $amount
     * @return string
     */
    public function formatPrice(float $amount): string
    {
        $code = $this->getOrder()?->getOrderCurrencyCode() ?? 'EUR';
        return (string) $this->priceCurrency->format(
            $amount,
            false,
            PriceCurrencyInterface::DEFAULT_PRECISION,
            null,
            (string) $code,
        );
    }

    /**
     * Get refund policy text.
     *
     * @return string
     */
    public function getRefundPolicyText(): string
    {
        return (string) __(
            'The refund will be issued using your original payment method within 5-7 business days after we receive your return.'
        );
    }

    /**
     * Get currency code.
     *
     * @return string
     */
    public function getCurrencyCode(): string
    {
        return (string) ($this->getOrder()?->getOrderCurrencyCode() ?? 'EUR');
    }
}
