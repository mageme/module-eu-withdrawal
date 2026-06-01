<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Observer;

use MageMe\EUWithdrawal\Model\Queue\WaiverConfirmationPublisher;
use MageMe\EUWithdrawal\Model\Queue\WaiverConfirmationStateRepository;
use MageMe\EUWithdrawal\Service\DigitalContentDetector;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;

class PublishWaiverConfirmationOnOrderPlace implements ObserverInterface
{
    /**
     * Constructor.
     *
     * @param DigitalContentDetector $detector
     * @param WaiverConfirmationPublisher $publisher
     * @param WaiverConfirmationStateRepository $stateRepo
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly DigitalContentDetector $detector,
        private readonly WaiverConfirmationPublisher $publisher,
        private readonly WaiverConfirmationStateRepository $stateRepo,
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
        $order = $observer->getEvent()->getOrder();
        if (!$order || !$order->getEntityId()) {
            return;
        }
        $digital = $this->detector->filterDigitalItems($order->getAllVisibleItems());
        if (empty($digital)) {
            return;
        }
        $orderId = (int) $order->getEntityId();
        if ($this->stateRepo->getByOrderId($orderId) !== null) {
            return; // idempotent — already queued.
        }

        try {
            $this->stateRepo->markPending($orderId);
            $this->publisher->publish($orderId);
        } catch (\Throwable $t) {
            $this->logger->error(
                'MageMe_EUWithdrawal: waiver-confirmation enqueue failed at order place for order '
                . $orderId . '; order placement is unaffected.',
                ['exception' => $t],
            );
        }
    }
}
