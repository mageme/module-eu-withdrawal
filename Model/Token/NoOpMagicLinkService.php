<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Token;

use MageMe\EUWithdrawal\Api\Token\MagicLinkServiceInterface;

/**
 * free-tier magic-link service: no-op everywhere.
 *
 * The base module has no `mm_eu_withdrawal_magic_link` table — that is owned by
 * the Pro `MageMe_EUWithdrawalMagicLink` add-on. module-only guests
 * exercise their Art. 11a(1) right via the lookup form
 * (`Controller\Withdraw\Lookup`); session-verified state binds the
 * order entity to the request without any tokens.
 *
 * The Pro module overrides this binding via `etc/di.xml` `<preference>`
 * to its own `MagicLinkService` (DB-backed, configurable lifetime,
 * 30-day default, multi-use).
 */
class NoOpMagicLinkService implements MagicLinkServiceInterface
{
    /**
     * Resolve order.
     *
     * @param string $plainToken
     * @return int|null Always null — The base module has no token table.
     */
    public function resolveOrder(string $plainToken): ?int
    {
        return null;
    }

    /**
     * Issue or reuse for order.
     *
     * @param int $orderEntityId
     * @return string Always empty string — The base module has no token issuer.
     */
    public function issueOrReuseForOrder(int $orderEntityId): string
    {
        return '';
    }
}
