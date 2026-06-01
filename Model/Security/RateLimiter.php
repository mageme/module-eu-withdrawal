<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Security;

use Magento\Framework\App\CacheInterface;

class RateLimiter
{
    public const CACHE_PREFIX = 'mm_eu_w_rl_';
    public const CACHE_TAG = 'MAGEME_EU_WITHDRAWAL_RATE_LIMIT';

    /**
     * Constructor.
     *
     * @param CacheInterface $cache
     * @param int $budget
     * @param int $windowSeconds
     */
    public function __construct(
        private readonly CacheInterface $cache,
        private readonly int $budget = 10,
        private readonly int $windowSeconds = 3600,
    ) {
    }

    /**
     * Configured attempt budget per window.
     *
     * @return int
     */
    public function getBudget(): int
    {
        return $this->budget;
    }

    /**
     * Configured window length in seconds.
     *
     * @return int
     */
    public function getWindowSeconds(): int
    {
        return $this->windowSeconds;
    }

    /**
     * Allow.
     *
     * @param string $ipHash
     * @return bool
     */
    public function allow(string $ipHash): bool
    {
        // NOTE(task-19): load/save is not atomic; concurrent requests with the same
        // ipHash can each observe current<budget and both increment, admitting
        // O(parallelism) extra attempts per window. Migrate to Redis INCR+EXPIRE
        // during hardening. TTL refreshes on each counted request (sliding window).
        $key = self::CACHE_PREFIX . $ipHash;
        $current = (int) $this->cache->load($key);

        if ($current >= $this->budget) {
            return false;
        }

        $this->cache->save(
            (string) ($current + 1),
            $key,
            [self::CACHE_TAG],
            $this->windowSeconds,
        );
        return true;
    }
}
