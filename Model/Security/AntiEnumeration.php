<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Security;

use MageMe\EUWithdrawal\Exception\ItemCapacityExceededException;
use MageMe\EUWithdrawal\Exception\NoEligibleItemsException;
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
     * Legacy form-post entry point: runs the shared create pipeline and maps
     * its outcome to a uniform redirect response.
     *
     * @param callable(CreateRequestInput): CreateRequestResult $action
     */
    public function handle(CreateRequestInput $input, callable $action): UniformResponse
    {
        try {
            $result = $this->process($input, $action);
        } catch (ItemCapacityExceededException | NoEligibleItemsException $e) {
            // Real, user-actionable domain failures — let the controller map each to
            // its own message instead of masking it as a uniform "check your email".
            // They fire only after the order and identity are verified (the creator
            // returns a silent failure for any mismatch), so surfacing them leaks
            // nothing to a non-owner.
            throw $e;
        } catch (\Throwable $e) {
            $this->logger->warning(
                'EUWithdrawal submit threw: ' . $e->getMessage(),
                ['ip_hash' => $this->hashIp($input->ip ?? '')],
            );
            $this->audit($this->hashIp($input->ip ?? ''), 'error');
            return UniformResponse::uniform();
        }

        if ($result === null || !$result->isSuccess()) {
            return UniformResponse::uniform();
        }

        return UniformResponse::redirect('withdraw-contract/withdraw/success', []);
    }

    /**
     * Shared enumeration-safe create pipeline for both the form-post and the
     * SPA/JSON controllers. Enforces the rate-limit gate, audits the outcome,
     * and on success sends the submission notification and records the session
     * marker. Returns the create result, or null when the request was throttled
     * before the action ran; domain exceptions from $action propagate so the
     * caller can map them to its own response.
     *
     * @param CreateRequestInput $input
     * @param callable(CreateRequestInput): CreateRequestResult $action
     * @return ?CreateRequestResult
     */
    public function process(CreateRequestInput $input, callable $action): ?CreateRequestResult
    {
        $ipHash = $this->hashIp($input->ip ?? '');

        if (!$this->rateLimiter->allow($ipHash)) {
            $this->audit($ipHash, 'rate_limited');
            $this->eventManager->dispatch(
                'mageme_eu_withdrawal_anti_enumeration_throttled',
                [
                    'endpoint'       => 'withdrawal_submit',
                    'ip'             => $ipHash,
                    'attempts'       => $this->rateLimiter->getBudget(),
                    'window_seconds' => $this->rateLimiter->getWindowSeconds(),
                ],
            );
            return null;
        }

        $result = $action($input);

        $this->audit($ipHash, $result->isSuccess() ? 'accepted' : 'silent_failure');

        if ($result->isSuccess()) {
            $this->notify($input, $result);
            $this->withdrawalSession->setLastWithdrawalRequestId((int) $result->getRequestId());
        }

        return $result;
    }

    /**
     * Send the submission-confirmation notification (and its merchant BCC) for
     * a successfully created request.
     *
     * @param CreateRequestInput $input
     * @param CreateRequestResult $result
     * @return void
     */
    private function notify(CreateRequestInput $input, CreateRequestResult $result): void
    {
        $this->notificationSender->send(
            toEmail: $input->customerEmail,
            consumerName: $input->customerName,
            orderIncrementId: $input->orderIncrementId,
            withdrawalIncrementId: sprintf('%09d', (int) $result->getRequestId()),
            locale: $this->normaliseLocale($input->locale),
            storeId: (int) $result->getStoreId(),
        );
    }

    /**
     * Accept any well-formed `xx_XX` locale tag so multi-language stores get a
     * localised email; fall back to en_US for anything else (e.g. a store code
     * passed in by mistake).
     *
     * @param string $input
     * @return string
     */
    private function normaliseLocale(string $input): string
    {
        return preg_match('/^[a-z]{2}_[A-Z]{2}$/', $input) === 1 ? $input : 'en_US';
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
