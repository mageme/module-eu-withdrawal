<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Security;

class ResponseTimer
{
    private ?float $startTime = null;

    /**
     * Start.
     *
     * @return void
     */
    public function start(): void
    {
        $this->startTime = microtime(true);
    }

    /**
     * Pad.
     *
     * @param int $minMs
     * @return void
     */
    public function pad(int $minMs = 200): void
    {
        if ($this->startTime === null) {
            return;
        }
        $elapsedMs = (microtime(true) - $this->startTime) * 1000;
        $remainingMs = $minMs - $elapsedMs;
        if ($remainingMs > 0) {
            usleep((int) ($remainingMs * 1000));
        }
    }

    /**
     * Get start time.
     *
     * @return float
     */
    public function getStartTime(): float
    {
        return $this->startTime ?? microtime(true);
    }
}
