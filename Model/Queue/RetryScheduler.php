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

    /**
     * Next.
     *
     * @param int $attempts
     * @param \DateTimeImmutable $now
     * @return ?\DateTimeImmutable
     */
    public function next(int $attempts, \DateTimeImmutable $now): ?\DateTimeImmutable
    {
        if (!isset(self::DELAYS[$attempts])) {
            return null;
        }
        return $now->modify('+' . self::DELAYS[$attempts] . ' seconds');
    }
}
