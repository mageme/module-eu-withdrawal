<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Observer;

use MageMe\EUWithdrawal\Api\RequestRepositoryInterface;
use MageMe\EUWithdrawal\Model\Mail\StatusChangeNotifier as MailNotifier;
use MageMe\EUWithdrawal\Model\ModuleConfig;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;

/**
 * Listens on `mageme_eu_withdrawal_audit_admin_status_changed` (dispatched by
 * StatusMachine on every transition) and routes to the customer-facing
 * status-change email sender. Same event also feeds the audit-log observer —
 * both are independent listeners, neither depends on the other.
 *
 * No-throw guarantee: any failure in the sender is logged but never escapes,
 * so a failed send cannot break the StatusMachine transaction.
 */
class StatusChangeNotifier implements ObserverInterface
{
    /**
     * Constructor.
     *
     * @param RequestRepositoryInterface $repository
     * @param MailNotifier $sender
     * @param ModuleConfig $moduleConfig
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly RequestRepositoryInterface $repository,
        private readonly MailNotifier $sender,
        private readonly ModuleConfig $moduleConfig,
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
        if (!$this->moduleConfig->isEnabled()) {
            return;
        }
        try {
            $data = $observer->getEvent()->getData();
            $requestId = (int) ($data['request_id'] ?? 0);
            if ($requestId === 0) {
                return;
            }
            $request = $this->repository->get($requestId);
            $this->sender->sendForTransition(
                $request,
                (string) ($data['from'] ?? ''),
                (string) ($data['to'] ?? ''),
                $data,
            );
        } catch (\Throwable $e) {
            $this->logger->warning(
                'EUWithdrawal status-change observer failed: ' . $e->getMessage(),
                ['event' => $observer->getEvent()?->getName()],
            );
        }
    }
}
