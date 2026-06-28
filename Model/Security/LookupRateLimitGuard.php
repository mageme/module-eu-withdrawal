<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Security;

use MageMe\EUWithdrawal\Model\Config;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;

/**
 * Per-IP rate limit for the guest order-lookup endpoint. Caps brute-force
 * enumeration of the (order #, email) oracle on top of the constant-time
 * response padding. Keyed in its own `|lookup|` bucket so it never shares the
 * stricter request-creation counter; throttled hits are audited via the same
 * anti-enumeration event the create pipeline emits.
 */
class LookupRateLimitGuard
{
    /**
     * Constructor.
     *
     * @param RateLimiter $rateLimiter
     * @param RemoteAddress $remoteAddress
     * @param Config $config
     * @param EventManager $eventManager
     */
    public function __construct(
        private readonly RateLimiter $rateLimiter,
        private readonly RemoteAddress $remoteAddress,
        private readonly Config $config,
        private readonly EventManager $eventManager,
    ) {
    }

    /**
     * Whether the current client IP is still within its lookup attempt budget.
     *
     * @return bool
     */
    public function allow(): bool
    {
        $ip = (string) $this->remoteAddress->getRemoteAddress();
        $ipHash = hash('sha256', $ip . '|lookup|' . $this->config->getIpHashSalt());

        if ($this->rateLimiter->allow($ipHash)) {
            return true;
        }

        $this->eventManager->dispatch(
            'mageme_eu_withdrawal_anti_enumeration_throttled',
            [
                'endpoint'       => 'withdrawal_lookup',
                'ip'             => $ipHash,
                'attempts'       => $this->rateLimiter->getBudget(),
                'window_seconds' => $this->rateLimiter->getWindowSeconds(),
            ],
        );
        return false;
    }
}
