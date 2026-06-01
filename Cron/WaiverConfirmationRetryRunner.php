<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Cron;

use MageMe\EUWithdrawal\Model\Queue\WaiverConfirmationPublisher;
use MageMe\EUWithdrawal\Model\Queue\WaiverConfirmationStateRepository;

/**
 * Republishes due waiver-confirmation messages whose previous attempt failed
 * (state `failed_retry`, `next_send_at` passed). Mirrors ReceiptRetryRunner:
 * the consumer never re-throws, so failed sends rely on this cron to retry.
 */
class WaiverConfirmationRetryRunner
{
    private const LIMIT = 50;
    private const LEASE_SECONDS = 300;

    /**
     * Constructor.
     *
     * @param WaiverConfirmationStateRepository $stateRepo
     * @param WaiverConfirmationPublisher $publisher
     */
    public function __construct(
        private readonly WaiverConfirmationStateRepository $stateRepo,
        private readonly WaiverConfirmationPublisher $publisher,
    ) {
    }

    /**
     * Execute.
     *
     * @return void
     */
    public function execute(): void
    {
        foreach ($this->stateRepo->claimDueForRetry(self::LIMIT, self::LEASE_SECONDS) as $orderId) {
            $this->publisher->publish((int) $orderId);
        }
    }
}
