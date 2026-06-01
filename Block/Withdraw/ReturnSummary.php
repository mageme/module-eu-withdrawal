<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Block\Withdraw;

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
        array $data = [],
    ) {
        parent::__construct($context, $data);
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
            'shipping_paid'   => $order !== null ? (float) $order->getShippingAmount() : 0.0,
            'shipping_refund' => 0.0,
            'total_refund'    => 0.0,
        ];
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
