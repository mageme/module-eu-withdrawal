<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Queue;

class RetryScheduler
{
    /** @var array<int,int> */
    private const DELAYS = [1 => 60, 2 => 300, 3 => 1800];
    private const MAX_DELAY = 1800;

    /**
     * Next.
     *
     * @param int $attempts
     * @param \DateTimeImmutable $now
     * @param int $maxAttempts
     * @return ?\DateTimeImmutable
     */
    public function next(int $attempts, \DateTimeImmutable $now, int $maxAttempts = 3): ?\DateTimeImmutable
    {
        if ($attempts < 1 || $attempts > $maxAttempts) {
            return null;
        }
        $delay = self::DELAYS[$attempts] ?? self::MAX_DELAY;
        return $now->modify('+' . $delay . ' seconds');
    }
}
