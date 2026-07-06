<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Block\Withdraw;

use MageMe\EUWithdrawal\Model\Customer\OrderWithdrawalBadgeService;
use MageMe\EUWithdrawal\Model\Frontend\Dto\EligibleOrdersPage;
use MageMe\EUWithdrawal\Model\Frontend\EligibleOrdersProvider;
use MageMe\EUWithdrawal\Model\Frontend\PeriodDaysConfigReader;
use MageMe\EUWithdrawal\Model\Period\DeliveryDateResolver;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderItemInterface;

class OrderSelector extends Template
{
    private ?EligibleOrdersPage $page = null;

    /**
     * Constructor.
     *
     * @param Context $context
     * @param CustomerSession $customerSession
     * @param EligibleOrdersProvider $eligibleOrders
     * @param ProductThumbnail $thumbnail
     * @param DeliveryDateResolver $deliveryDate
     * @param TimezoneInterface $timezone
     * @param PriceCurrencyInterface $priceCurrency
     * @param OrderWithdrawalBadgeService $badgeService
     * @param PeriodDaysConfigReader $periodDays
     * @param array $data
     */
    public function __construct(
        Context $context,
        private readonly CustomerSession $customerSession,
        private readonly EligibleOrdersProvider $eligibleOrders,
        private readonly ProductThumbnail $thumbnail,
        private readonly DeliveryDateResolver $deliveryDate,
        private readonly TimezoneInterface $timezone,
        private readonly PriceCurrencyInterface $priceCurrency,
        private readonly OrderWithdrawalBadgeService $badgeService,
        private readonly PeriodDaysConfigReader $periodDays,
        array $data = [],
    ) {
        parent::__construct($context, $data);
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
     * Is customer logged in.
     *
     * @return bool
     */
    public function isCustomerLoggedIn(): bool
    {
        $this->customerSession->start();
        return $this->customerSession->isLoggedIn();
    }

    /** @return OrderInterface[] */
    public function getOrders(): array
    {
        return $this->page()->orders;
    }

    /**
     * Whether a "Show more" control should be rendered.
     *
     * @return bool
     */
    public function canLoadMore(): bool
    {
        return $this->page()->hasMore && $this->getShowLimit() < EligibleOrdersProvider::MAX_LIMIT;
    }

    /**
     * URL that reveals the next batch of eligible orders.
     *
     * @return string
     */
    public function getLoadMoreUrl(): string
    {
        $next = min(
            EligibleOrdersProvider::MAX_LIMIT,
            $this->getShowLimit() + EligibleOrdersProvider::LIMIT_STEP,
        );
        return $this->getUrl('withdraw-contract', [
            '_query' => ['show' => $next],
            '_fragment' => 'mm-eu-w-order-list',
        ]);
    }

    /**
     * AJAX endpoint that returns the next batch of eligible-order rows.
     *
     * @return string
     */
    public function getLoadMoreEndpoint(): string
    {
        $next = min(
            EligibleOrdersProvider::MAX_LIMIT,
            $this->getShowLimit() + EligibleOrdersProvider::LIMIT_STEP,
        );
        return $this->getUrl('withdraw-contract/withdraw/orders', ['_query' => ['show' => $next]]);
    }

    private function page(): EligibleOrdersPage
    {
        if ($this->page !== null) {
            return $this->page;
        }
        if (!$this->isCustomerLoggedIn()) {
            return $this->page = new EligibleOrdersPage([], false);
        }
        return $this->page = $this->eligibleOrders->forCustomer(
            (int) $this->customerSession->getCustomerId(),
            $this->getShowLimit(),
        );
    }

    private function getShowLimit(): int
    {
        $raw = (int) $this->getRequest()->getParam('show', EligibleOrdersProvider::DEFAULT_LIMIT);
        $snapped = intdiv(max(0, $raw), EligibleOrdersProvider::LIMIT_STEP) * EligibleOrdersProvider::LIMIT_STEP;
        return max(EligibleOrdersProvider::DEFAULT_LIMIT, min(EligibleOrdersProvider::MAX_LIMIT, $snapped));
    }

    /**
     * @return array<int, array{url:string, alt:string}>
     */
    public function getItemThumbnails(OrderInterface $order, int $limit = 3): array
    {
        $items = $this->orderItems($order);
        $out = [];
        foreach (array_slice($items, 0, $limit) as $item) {
            $out[] = [
                'url' => $this->thumbnail->urlFor($item),
                'alt' => (string) $item->getName(),
            ];
        }
        return $out;
    }

    /**
     * Get item count.
     *
     * @param OrderInterface $order
     * @return int
     */
    public function getItemCount(OrderInterface $order): int
    {
        return count($this->orderItems($order));
    }

    /**
     * Get extra item count.
     *
     * @param OrderInterface $order
     * @param int $visibleLimit
     * @return int
     */
    public function getExtraItemCount(OrderInterface $order, int $visibleLimit = 3): int
    {
        return max(0, $this->getItemCount($order) - $visibleLimit);
    }

    /**
     * Format order date.
     *
     * @param OrderInterface $order
     * @return string
     */
    public function formatOrderDate(OrderInterface $order): string
    {
        $raw = (string) $order->getCreatedAt();
        if ($raw === '') {
            return '';
        }
        try {
            $dt = new \DateTimeImmutable($raw, new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return $raw;
        }
        return $this->timezone->formatDateTime($dt, \IntlDateFormatter::MEDIUM, \IntlDateFormatter::NONE);
    }

    /**
     * Format delivery date.
     *
     * @param OrderInterface $order
     * @return string
     */
    public function formatDeliveryDate(OrderInterface $order): string
    {
        $raw = $this->deliveryDate->resolve($order);
        if ($raw === null) {
            return '';
        }
        try {
            $dt = new \DateTimeImmutable($raw, new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return $raw;
        }
        return $this->timezone->formatDateTime($dt, \IntlDateFormatter::MEDIUM, \IntlDateFormatter::NONE);
    }

    /**
     * Format order total.
     *
     * @param OrderInterface $order
     * @return string
     */
    public function formatOrderTotal(OrderInterface $order): string
    {
        return (string) $this->priceCurrency->format(
            (float) $order->getGrandTotal(),
            false,
            PriceCurrencyInterface::DEFAULT_PRECISION,
            null,
            (string) $order->getOrderCurrencyCode(),
        );
    }

    /**
     * Get shipping method label.
     *
     * @param OrderInterface $order
     * @return string
     */
    public function getShippingMethodLabel(OrderInterface $order): string
    {
        return (string) $order->getShippingDescription();
    }

    /**
     * Get order increment id.
     *
     * @param OrderInterface $order
     * @return string
     */
    public function getOrderIncrementId(OrderInterface $order): string
    {
        return (string) $order->getIncrementId();
    }

    /**
     * Get step2 url.
     *
     * @param OrderInterface $order
     * @return string
     */
    public function getStep2Url(OrderInterface $order): string
    {
        return $this->getUrl('withdraw-contract', [
            '_query' => ['order_id' => (int) $order->getEntityId()],
        ]);
    }

    /**
     * Get order history url.
     *
     * @return string
     */
    public function getOrderHistoryUrl(): string
    {
        return $this->getUrl('sales/order/history');
    }

    /**
     * Returns parent-level items only. For configurable purchases Magento
     * writes two rows to sales_order_item (the configurable parent + the
     * simple child with parent_item_id set); the child would render as a
     * duplicate thumbnail. Dropping rows with a non-null parent_item_id
     * keeps one visible item per line the customer actually bought.
     *
     * @return OrderItemInterface[]
     */
    private function orderItems(OrderInterface $order): array
    {
        $items = $order->getItems();
        if ($items === null) {
            return [];
        }
        $all = is_array($items) ? $items : iterator_to_array($items);
        return array_values(array_filter(
            $all,
            static fn (OrderItemInterface $i): bool => $i->getParentItemId() === null,
        ));
    }
}
