<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Frontend;

use MageMe\EUWithdrawal\Model\Customer\OrderWithdrawalBadgeService;
use MageMe\EUWithdrawal\Model\Order\LatestShipmentDateResolver;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;

class EligibleOrdersProvider
{
    private const WITHDRAWAL_WINDOW_DAYS = 14;

    /**
     * Constructor.
     *
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param OrderRepositoryInterface $orderRepository
     * @param LatestShipmentDateResolver $latestShipmentDate
     * @param OrderWithdrawalBadgeService $badgeService
     * @param OrderEligibilityResolver $eligibilityResolver
     */
    public function __construct(
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly LatestShipmentDateResolver $latestShipmentDate,
        private readonly OrderWithdrawalBadgeService $badgeService,
        private readonly OrderEligibilityResolver $eligibilityResolver,
    ) {
    }

    /**
     * @return OrderInterface[]
     */
    public function forCustomer(int $customerId, int $limit = 20): array
    {
        if ($customerId <= 0) {
            return [];
        }

        $criteria = $this->searchCriteriaBuilder
            ->addFilter('customer_id', $customerId)
            ->addFilter('state', [Order::STATE_CANCELED, Order::STATE_CLOSED], 'nin')
            ->setPageSize(100)
            ->setCurrentPage(1)
            ->create();

        $items = $this->orderRepository->getList($criteria)->getItems();
        $items = array_values($items);

        $ids = array_map(static fn (OrderInterface $o) => (int) $o->getEntityId(), $items);
        $badges = $this->badgeService->getBadges($ids);

        $cutoff = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify('-' . self::WITHDRAWAL_WINDOW_DAYS . ' days')
            ->format('Y-m-d H:i:s');

        $eligible = [];
        foreach ($items as $order) {
            $id = (int) $order->getEntityId();
            if (($badges[$id] ?? null) === OrderWithdrawalBadgeService::BADGE_FULL) {
                continue;
            }
            $shipDate = $this->latestShipmentDate->resolve($id);
            if ($shipDate !== null && $shipDate < $cutoff) {
                continue;
            }
            // Filter out orders that are excluded under Art. 16 (custom-made,
            // perishable, fully-consumed digital, etc.) or have no remaining
            // capacity (everything already withdrawn). `hasRemainingCapacity`
            // is the same flag the order-view section uses to decide whether
            // to show the Start-withdrawal button.
            if (!$this->eligibilityResolver->isEligibleForOrder($order)) {
                continue;
            }
            $eligible[] = $order;
            if (count($eligible) >= $limit) {
                break;
            }
        }
        return $eligible;
    }
}
