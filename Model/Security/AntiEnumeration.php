<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Security;

use MageMe\EUWithdrawal\Model\Config;
use MageMe\EUWithdrawal\Model\Mail\WithdrawalNotificationSender;
use MageMe\EUWithdrawal\Model\Request\CreateRequestInput;
use MageMe\EUWithdrawal\Model\Request\CreateRequestResult;
use MageMe\EUWithdrawal\Model\Session as WithdrawalSession;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Psr\Log\LoggerInterface;

class AntiEnumeration
{
    /**
     * Constructor.
     *
     * @param RateLimiter $rateLimiter
     * @param LoggerInterface $logger
     * @param EventManager $eventManager
     * @param Config $config
     * @param WithdrawalNotificationSender $notificationSender
     * @param WithdrawalSession $withdrawalSession
     */
    public function __construct(
        private readonly RateLimiter $rateLimiter,
        private readonly LoggerInterface $logger,
        private readonly EventManager $eventManager,
        private readonly Config $config,
        private readonly WithdrawalNotificationSender $notificationSender,
        private readonly WithdrawalSession $withdrawalSession,
    ) {
    }

    /**
     * @param callable(CreateRequestInput): CreateRequestResult $action
     */
    public function handle(CreateRequestInput $input, callable $action): UniformResponse
    {
        $ipHash = $this->hashIp($input->ip ?? '');

        if (!$this->rateLimiter->allow($ipHash)) {
            $this->audit($ipHash, 'rate_limited');
            // Dedicated throttle event for the audit log (Pro OnRateLimitHit
            // consumes it). Payload keys match RateLimitHitPayload::build; the
            // already-hashed IP is passed (no raw IP at this layer).
            $this->eventManager->dispatch(
                'mageme_eu_withdrawal_anti_enumeration_throttled',
                [
                    'endpoint'       => 'withdrawal_submit',
                    'ip'             => $ipHash,
                    'attempts'       => $this->rateLimiter->getBudget(),
                    'window_seconds' => $this->rateLimiter->getWindowSeconds(),
                ],
            );
            return UniformResponse::uniform();
        }

        try {
            $result = $action($input);
        } catch (\Throwable $e) {
            $this->logger->warning(
                'EUWithdrawal submit threw: ' . $e->getMessage(),
                ['ip_hash' => $ipHash],
            );
            $this->audit($ipHash, 'error');
            return UniformResponse::uniform();
        }

        $this->audit($ipHash, $result->isSuccess() ? 'accepted' : 'silent_failure');

        if (!$result->isSuccess()) {
            return UniformResponse::uniform();
        }

        // The request was already created in the `submitted` state (RequestCreator
        // does everything in one transaction). Send the notification ack and
        // redirect to success — withdrawal is as easy as purchase (Art. 2
        // Directive (EU) 2023/2673), so there is no separate confirmation step.
        $requestId = (int) $result->getRequestId();
        $this->notificationSender->send(
            toEmail: $input->customerEmail,
            consumerName: $input->customerName,
            orderIncrementId: $input->orderIncrementId,
            withdrawalIncrementId: sprintf('%09d', $requestId),
            locale: $this->normaliseLocale($input->locale),
            storeId: (int) $result->getStoreId(),
        );

        $this->withdrawalSession->setLastWithdrawalRequestId($requestId);
        return UniformResponse::redirect('withdraw-contract/withdraw/success', []);
    }

    /**
     * Normalise locale.
     *
     * @param string $input
     * @return string
     */
    private function normaliseLocale(string $input): string
    {
        $allowed = ['en_US', 'de_DE', 'fr_FR'];
        return in_array($input, $allowed, true) ? $input : 'en_US';
    }

    /**
     * Hash ip.
     *
     * @param string $ip
     * @return string
     */
    private function hashIp(string $ip): string
    {
        return hash('sha256', $ip . '|' . $this->config->getIpHashSalt());
    }

    /**
     * Audit.
     *
     * @param string $ipHash
     * @param string $outcome
     * @return void
     */
    private function audit(string $ipHash, string $outcome): void
    {
        $this->eventManager->dispatch(
            'mageme_eu_withdrawal_submit_attempt',
            ['ip_hash' => $ipHash, 'outcome' => $outcome],
        );
    }
}
