<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Observer;

use MageMe\EUWithdrawal\Api\Data\EligibilityResultInterface;
use MageMe\EUWithdrawal\Model\EligibilitySnapshot;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Api\Data\OrderItemInterface;

class SaveEligibilitySnapshot implements ObserverInterface
{
    /**
     * Constructor.
     *
     * @param EligibilitySnapshot $snapshot
     */
    public function __construct(
        private readonly EligibilitySnapshot $snapshot,
    ) {
    }

    /**
     * Execute.
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        $result = $observer->getEvent()->getData('eligibility_result');
        if (!$result instanceof EligibilityResultInterface) {
            return;
        }
        $requestId = (int) $observer->getEvent()->getData('request_id');
        $orderId = (int) $observer->getEvent()->getData('order_id');

        $orderItems = $observer->getEvent()->getData('order_items');
        $orderItems = is_array($orderItems)
            ? array_values(array_filter(
                $orderItems,
                static fn ($item): bool => $item instanceof OrderItemInterface,
            ))
            : [];

        $submittedAt = $observer->getEvent()->getData('submitted_at');
        if (!$submittedAt instanceof \DateTimeImmutable) {
            $submittedAt = new \DateTimeImmutable();
        }

        $this->snapshot->persist($requestId, $orderId, $result, $orderItems, $submittedAt);
    }
}
