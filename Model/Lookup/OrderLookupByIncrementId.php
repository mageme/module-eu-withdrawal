<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Lookup;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;

/**
 * Load a sales_order row by its user-facing `increment_id` (the zero-padded
 * string shown in URLs and admin). `OrderRepositoryInterface::get()` expects
 * the numeric `entity_id` — passing `(int) "000000042"` silently loads the
 * wrong order (entity_id = 42) or throws NoSuchEntityException for deleted
 * rows. Use this helper anywhere the id came from a URL/form field.
 */
class OrderLookupByIncrementId
{
    /**
     * Constructor.
     *
     * @param OrderRepositoryInterface $orderRepository
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     */
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
    ) {
    }

    /**
     * Find.
     *
     * @param string $incrementId
     * @return ?OrderInterface
     */
    public function find(string $incrementId): ?OrderInterface
    {
        $incrementId = trim($incrementId);
        if ($incrementId === '') {
            return null;
        }

        $criteria = $this->searchCriteriaBuilder
            ->addFilter(OrderInterface::INCREMENT_ID, $incrementId)
            ->setPageSize(1)
            ->create();

        $items = $this->orderRepository->getList($criteria)->getItems();
        return $items !== [] ? array_values($items)[0] : null;
    }
}
