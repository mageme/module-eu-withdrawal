<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Observer;

use MageMe\EUWithdrawal\Api\RequestRepositoryInterface;
use MageMe\EUWithdrawal\Model\Mail\AdminAlertSender;
use MageMe\EUWithdrawal\Model\Mail\EmailConfig;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;

/**
 * Loads the just-created request and hands it to AdminAlertSender for the
 * new-request admin alert. Fail-soft: any repository miss is logged at
 * WARNING and swallowed so the user-facing transaction is unaffected.
 */
class AdminAlertOnRequestCreate implements ObserverInterface
{
    public function __construct(
        private readonly RequestRepositoryInterface $requestRepository,
        private readonly AdminAlertSender $sender,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function execute(Observer $observer): void
    {
        $requestId = (int) $observer->getEvent()->getData('request_id');
        if ($requestId <= 0) {
            return;
        }

        try {
            $request = $this->requestRepository->get($requestId);
        } catch (\Throwable $e) {
            $this->logger->warning(sprintf(
                'AdminAlertOnRequestCreate failed to load request_id=%d: %s',
                $requestId,
                $e->getMessage(),
            ));
            return;
        }

        $this->sender->send(EmailConfig::TYPE_ADMIN_NEW_REQUEST, $request);
    }
}
