<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Observer;

use MageMe\EUWithdrawal\Model\ModuleConfig;
use MageMe\EUWithdrawal\Model\Queue\ReceiptSendPublisher;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

class PublishReceiptOnFinalize implements ObserverInterface
{
    /**
     * Constructor.
     *
     * @param ReceiptSendPublisher $publisher
     * @param ManagerInterface $eventManager
     * @param ModuleConfig $moduleConfig
     */
    public function __construct(
        private readonly ReceiptSendPublisher $publisher,
        private readonly ManagerInterface $eventManager,
        private readonly ModuleConfig $moduleConfig,
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
        if (!$this->moduleConfig->isEnabled()) {
            return;
        }
        $requestId = (int) $observer->getEvent()->getData('request_id');
        if ($requestId <= 0) {
            return;
        }
        $messageId = $this->publisher->publish($requestId);
        $this->eventManager->dispatch('mageme_eu_withdrawal_audit_receipt_queued', [
            'request_id' => $requestId,
            'topic'      => ReceiptSendPublisher::TOPIC,
            'message_id' => $messageId,
        ]);
    }
}
