<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Observer;

use MageMe\EUWithdrawal\Model\Waiver\PerformanceDetector;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Api\OrderItemRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Listens on `downloadable_link_purchased_item_save_after` (Magento dispatches
 * this whenever the row is saved). The Link controller saves it after each
 * successful download click. We detect a real download by comparing
 * `number_of_downloads_used` against `getOrigData()` — incremented = the
 * customer just downloaded; unchanged = it's the initial save during order
 * placement (which we ignore so performance only fires on real consumption).
 */
class MarkPerformanceOnDownloadablePurchased implements ObserverInterface
{
    /**
     * Constructor.
     *
     * @param PerformanceDetector $performance
     * @param OrderItemRepositoryInterface $orderItemRepo
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly PerformanceDetector $performance,
        private readonly OrderItemRepositoryInterface $orderItemRepo,
        private readonly LoggerInterface $logger,
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
        $item = $observer->getEvent()->getObject();
        if (!$item instanceof \Magento\Downloadable\Model\Link\Purchased\Item) {
            return;
        }
        $used = (int) $item->getNumberOfDownloadsUsed();
        $orig = (int) ($item->getOrigData('number_of_downloads_used') ?? 0);
        if ($used <= $orig) {
            return; // not a real download — skip initial save and no-op saves
        }
        $orderItemId = (int) $item->getOrderItemId();
        if ($orderItemId <= 0) {
            return;
        }
        try {
            $orderItem = $this->orderItemRepo->get($orderItemId);
        } catch (\Throwable) {
            return;
        }
        try {
            $this->performance->markStarted(
                (int) $orderItem->getOrderId(),
                $orderItemId,
                PerformanceDetector::TRIGGER_DOWNLOADABLE,
            );
        } catch (\Throwable $t) {
            $this->logger->warning(
                'MageMe_EUWithdrawal: performance-start mark failed for order item '
                . $orderItemId . '; the download/save is unaffected.',
                ['exception' => $t],
            );
        }
    }
}
