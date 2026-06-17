<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Frontend;

use MageMe\EUWithdrawal\Model\Customer\OrderWithdrawalBadgeService;
use MageMe\EUWithdrawal\Model\Frontend\Dto\EligibleOrdersPage;
use MageMe\EUWithdrawal\Model\Period\DeliveryDateResolver;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SortOrder;
use Magento\Framework\Api\SortOrderBuilder;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;

class EligibleOrdersProvider
{
    public const DEFAULT_LIMIT = 20;
    public const LIMIT_STEP = 20;
    public const MAX_LIMIT = 200;

    private const WITHDRAWAL_WINDOW_DAYS = 14;
    private const XML_PERIOD_DAYS = 'mageme_eu_withdrawal/withdrawal_window/period_days';
    private const SCAN_PAGE_SIZE = 100;
    private const MAX_SCAN_ORDERS = 1000;

    public function __construct(
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly DeliveryDateResolver $deliveryDate,
        private readonly OrderWithdrawalBadgeService $badgeService,
        private readonly OrderEligibilityResolver $eligibilityResolver,
        private readonly SortOrderBuilder $sortOrderBuilder,
        private readonly LoggerInterface $logger,
        private readonly ScopeConfigInterface $scopeConfig,
    ) {
    }

    /**
     * Newest-first page of the customer's withdrawal-eligible orders.
     *
     * Eligibility is decided per order in PHP, so the query is paged from the
     * top (created_at DESC) and filtered until $limit eligible are collected,
     * one extra is peeked to report `hasMore`, the order history is exhausted,
     * or the safety scan cap is reached.
     */
    public function forCustomer(int $customerId, int $limit = self::DEFAULT_LIMIT): EligibleOrdersPage
    {
        if ($customerId <= 0) {
            return new EligibleOrdersPage([], false);
        }

        $want = $limit + 1;
        $periodDays = max(
            self::WITHDRAWAL_WINDOW_DAYS,
            (int) $this->scopeConfig->getValue(self::XML_PERIOD_DAYS, ScopeInterface::SCOPE_STORE),
        );
        // Start-of-day cut-off to match PeriodRule's end-of-day (23:59:59) deadline.
        $cutoff = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify('-' . $periodDays . ' days')
            ->setTime(0, 0, 0)
            ->format('Y-m-d H:i:s');

        $eligible = [];
        $scanned = 0;
        $page = 1;
        while (count($eligible) < $want && $scanned < self::MAX_SCAN_ORDERS) {
            $orders = $this->fetchOrderPage($customerId, $page);
            if ($orders === []) {
                break;
            }
            $scanned += count($orders);
            $this->collectEligible($orders, $cutoff, $want, $eligible);
            if (count($orders) < self::SCAN_PAGE_SIZE) {
                break;
            }
            $page++;
        }

        if (count($eligible) < $want && $scanned >= self::MAX_SCAN_ORDERS) {
            $this->logger->warning(
                'EUWithdrawal eligible-orders scan reached the safety cap before satisfying the request',
                ['customer_id' => $customerId, 'scanned' => $scanned, 'limit' => $limit],
            );
        }

        return new EligibleOrdersPage(array_slice($eligible, 0, $limit), count($eligible) > $limit);
    }

    /** @return OrderInterface[] */
    private function fetchOrderPage(int $customerId, int $page): array
    {
        $newestFirst = $this->sortOrderBuilder
            ->setField('created_at')
            ->setDirection(SortOrder::SORT_DESC)
            ->create();

        $criteria = $this->searchCriteriaBuilder
            ->addFilter('customer_id', $customerId)
            ->addFilter('state', [Order::STATE_CANCELED, Order::STATE_CLOSED], 'nin')
            ->setSortOrders([$newestFirst])
            ->setPageSize(self::SCAN_PAGE_SIZE)
            ->setCurrentPage($page)
            ->create();

        return array_values($this->orderRepository->getList($criteria)->getItems());
    }

    /**
     * @param OrderInterface[] $orders
     * @param OrderInterface[] $eligible accumulator, mutated in place
     */
    private function collectEligible(array $orders, string $cutoff, int $want, array &$eligible): void
    {
        $ids = array_map(static fn (OrderInterface $o) => (int) $o->getEntityId(), $orders);
        $badges = $this->badgeService->getBadges($ids);

        foreach ($orders as $order) {
            if (count($eligible) >= $want) {
                return;
            }
            $id = (int) $order->getEntityId();
            if (($badges[$id] ?? null) === OrderWithdrawalBadgeService::BADGE_FULL) {
                continue;
            }
            // Drop orders delivered before the window opened. A null delivered-at
            // (not yet delivered, or no delivery status configured and no shipment)
            // keeps an unstarted clock — the order stays listed.
            $deliveredAt = $this->deliveryDate->resolve($order);
            if ($deliveredAt !== null && $deliveredAt < $cutoff) {
                continue;
            }
            if (!$this->eligibilityResolver->isEligibleForOrder($order)) {
                continue;
            }
            $eligible[] = $order;
        }
    }
}
