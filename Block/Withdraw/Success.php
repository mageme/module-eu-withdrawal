<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Block\Withdraw;

use MageMe\EUWithdrawal\Api\Data\ItemInterface;
use MageMe\EUWithdrawal\Api\Data\RequestInterface;
use MageMe\EUWithdrawal\Api\ItemRepositoryInterface;
use MageMe\EUWithdrawal\Api\RequestRepositoryInterface;
use MageMe\EUWithdrawal\Model\Frontend\PeriodDaysConfigReader;
use MageMe\EUWithdrawal\Model\Frontend\TaxDisplayConfig;
use MageMe\EUWithdrawal\Model\Session as WithdrawalSession;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderItemInterface;
use Magento\Sales\Api\OrderRepositoryInterface;

class Success extends Template
{
    private ?RequestInterface $withdrawalRequest = null;
    private bool $requestResolved = false;
    private ?OrderInterface $order = null;
    private bool $orderResolved = false;
    /** @var ItemInterface[]|null */
    private ?array $items = null;

    /**
     * Constructor.
     *
     * @param Context $context
     * @param WithdrawalSession $session
     * @param RequestRepositoryInterface $requestRepository
     * @param ItemRepositoryInterface $itemRepository
     * @param OrderRepositoryInterface $orderRepository
     * @param PriceCurrencyInterface $priceCurrency
     * @param TimezoneInterface $timezone
     * @param PeriodDaysConfigReader $periodDays
     * @param TaxDisplayConfig $taxDisplay
     * @param array $data
     */
    public function __construct(
        Context $context,
        private readonly WithdrawalSession $session,
        private readonly RequestRepositoryInterface $requestRepository,
        private readonly ItemRepositoryInterface $itemRepository,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly PriceCurrencyInterface $priceCurrency,
        private readonly TimezoneInterface $timezone,
        private readonly PeriodDaysConfigReader $periodDays,
        private readonly TaxDisplayConfig $taxDisplay,
        array $data = [],
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Whether the informational VAT line is suppressed. The confirmation page
     * always shows gross figures, so the line is dropped only when the store
     * folds tax into the grand total (sales-display "grandtotal" setting).
     *
     * @deprecated Named like the form's predicate but never meant the same
     *     thing. Use isVatLineHidden(), which the whole module now shares.
     * @see self::isVatLineHidden()
     * @return bool
     */
    public function isTaxLineHidden(): bool
    {
        return $this->isVatLineHidden();
    }

    /**
     * Whether the informational VAT row is suppressed.
     *
     * @return bool
     */
    public function isVatLineHidden(): bool
    {
        return $this->taxDisplay->isTaxFoldedIntoTotal();
    }

    /**
     * Configured withdrawal-period length in days, shown in customer-facing copy.
     *
     * @return int
     */
    public function getWithdrawalPeriodDays(): int
    {
        return $this->periodDays->getDays();
    }

    /**
     * Get withdrawal request.
     *
     * @return ?RequestInterface
     */
    public function getWithdrawalRequest(): ?RequestInterface
    {
        if ($this->requestResolved) {
            return $this->withdrawalRequest;
        }
        $this->requestResolved = true;
        $id = $this->session->getLastWithdrawalRequestId();
        if ($id === null || $id <= 0) {
            return $this->withdrawalRequest = null;
        }
        try {
            $this->withdrawalRequest = $this->requestRepository->get($id);
        } catch (NoSuchEntityException) {
            $this->withdrawalRequest = null;
        }
        return $this->withdrawalRequest;
    }

    /**
     * Has withdrawal request.
     *
     * @return bool
     */
    public function hasWithdrawalRequest(): bool
    {
        return $this->getWithdrawalRequest() !== null;
    }

    /**
     * Get increment id display.
     *
     * @return string
     */
    public function getIncrementIdDisplay(): string
    {
        $r = $this->getWithdrawalRequest();
        if ($r === null) {
            return '';
        }
        return (string) ($r->getIncrementId() ?? sprintf('%09d', (int) $r->getRequestId()));
    }

    /**
     * Get order.
     *
     * @return ?OrderInterface
     */
    public function getOrder(): ?OrderInterface
    {
        if ($this->orderResolved) {
            return $this->order;
        }
        $this->orderResolved = true;
        $r = $this->getWithdrawalRequest();
        if ($r === null) {
            return $this->order = null;
        }
        try {
            $this->order = $this->orderRepository->get((int) $r->getOrderId());
        } catch (NoSuchEntityException) {
            $this->order = null;
        }
        return $this->order;
    }

    /**
     * Get order increment id.
     *
     * @return string
     */
    public function getOrderIncrementId(): string
    {
        $order = $this->getOrder();
        return $order !== null ? (string) $order->getIncrementId() : '';
    }

    /**
     * Get order url.
     *
     * @return string
     */
    public function getOrderUrl(): string
    {
        $order = $this->getOrder();
        if ($order === null) {
            return '';
        }
        return $this->getUrl('sales/order/view', ['order_id' => (int) $order->getEntityId()]);
    }

    /**
     * Format submitted at.
     *
     * @return string
     */
    public function formatSubmittedAt(): string
    {
        $r = $this->getWithdrawalRequest();
        if ($r === null) {
            return '';
        }
        $raw = (string) $r->getCreatedAt();
        if ($raw === '') {
            return '';
        }
        try {
            $dt = new \DateTimeImmutable($raw, new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return $raw;
        }
        return $this->timezone->formatDateTime(
            $dt,
            \IntlDateFormatter::MEDIUM,
            \IntlDateFormatter::SHORT,
        );
    }

    /** @return ItemInterface[] */
    public function getItems(): array
    {
        if ($this->items !== null) {
            return $this->items;
        }
        $r = $this->getWithdrawalRequest();
        if ($r === null) {
            return $this->items = [];
        }
        return $this->items = $this->itemRepository->getByRequest((int) $r->getRequestId());
    }

    /**
     * Get order item.
     *
     * @param int $orderItemId
     * @return ?OrderItemInterface
     */
    public function getOrderItem(int $orderItemId): ?OrderItemInterface
    {
        $order = $this->getOrder();
        if ($order === null || $orderItemId <= 0) {
            return null;
        }
        $item = $order->getItemById($orderItemId);
        return $item instanceof OrderItemInterface ? $item : null;
    }

    /**
     * Extracts configurable-product selections + custom-option values from the
     * sales_order_item.product_options payload. Matches the structure used on
     * sales/order/view ("Size: 28 · Color: Green").
     *
     * @return array<int, array{label: string, value: string}>
     */
    public function getOrderItemOptions(int $orderItemId): array
    {
        $item = $this->getOrderItem($orderItemId);
        if ($item === null) {
            return [];
        }
        $productOptions = $item->getProductOptions();
        if (!is_array($productOptions)) {
            return [];
        }
        $out = [];
        foreach (($productOptions['attributes_info'] ?? []) as $row) {
            if (!is_array($row) || !isset($row['label'], $row['value'])) {
                continue;
            }
            $out[] = ['label' => (string) $row['label'], 'value' => (string) $row['value']];
        }
        foreach (($productOptions['options'] ?? []) as $row) {
            if (!is_array($row) || !isset($row['label'], $row['value'])) {
                continue;
            }
            $out[] = ['label' => (string) $row['label'], 'value' => (string) $row['value']];
        }
        return $out;
    }

    /**
     * Get items subtotal.
     *
     * @return float
     */
    public function getItemsSubtotal(): float
    {
        $total = 0.0;
        foreach ($this->getItems() as $item) {
            $total += (float) $item->getRefundAmount();
        }
        return $total;
    }

    /**
     * Get shipping refund.
     *
     * @return float
     */
    public function getShippingRefund(): float
    {
        $r = $this->getWithdrawalRequest();
        if ($r === null) {
            return 0.0;
        }
        return (float) $r->getShippingRefund();
    }

    /**
     * Get order adjustment refund (signed; 0 for standard orders).
     *
     * @return float
     */
    public function getOrderAdjustmentRefund(): float
    {
        $r = $this->getWithdrawalRequest();
        if ($r === null) {
            return 0.0;
        }
        return (float) $r->getOrderAdjustmentRefund();
    }

    /**
     * Informational VAT contained in the refund (item VAT + shipping VAT).
     *
     * @return float
     */
    public function getRefundTaxLine(): float
    {
        return (float) ($this->getWithdrawalRequest()?->getTaxRefund() ?? 0.0);
    }

    /**
     * Get total refund.
     *
     * @return float
     */
    public function getTotalRefund(): float
    {
        $stored = $this->getWithdrawalRequest()?->getTotalRefund();
        return $stored !== null
            ? (float) $stored
            : $this->getItemsSubtotal() + $this->getShippingRefund() + $this->getOrderAdjustmentRefund();
    }

    /**
     * Format price.
     *
     * @param float $amount
     * @return string
     */
    public function formatPrice(float $amount): string
    {
        $order = $this->getOrder();
        $code = $order !== null ? (string) $order->getOrderCurrencyCode() : 'EUR';
        return (string) $this->priceCurrency->format(
            $amount,
            false,
            PriceCurrencyInterface::DEFAULT_PRECISION,
            null,
            $code,
        );
    }

    /**
     * Get withdrawal landing url.
     *
     * @return string
     */
    public function getWithdrawalLandingUrl(): string
    {
        return $this->getUrl('withdraw-contract');
    }
}
