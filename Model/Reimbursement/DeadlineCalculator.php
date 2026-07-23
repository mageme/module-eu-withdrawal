<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Reimbursement;

/**
 * The Art. 13(1) reimbursement deadline: 14 days after the request was created,
 * in UTC. Derived on read, never stored — it is a pure function of created_at, so
 * every surface (the admin grid due-state column and the overdue digest cron)
 * reproduces the same value and no backfill is ever needed for existing rows.
 *
 * PERIOD_DAYS is the single source of the 14-day period; the digest cron reads it
 * to build the equivalent `created_at + INTERVAL 14 DAY` SQL.
 */
class DeadlineCalculator
{
    public const PERIOD_DAYS = 14;

    /**
     * @param string $createdAtUtc UTC 'Y-m-d H:i:s'
     * @return string UTC 'Y-m-d H:i:s'
     */
    public function deadlineFor(string $createdAtUtc): string
    {
        return (new \DateTimeImmutable($createdAtUtc, new \DateTimeZone('UTC')))
            ->modify('+' . self::PERIOD_DAYS . ' days')
            ->format('Y-m-d H:i:s');
    }
}
