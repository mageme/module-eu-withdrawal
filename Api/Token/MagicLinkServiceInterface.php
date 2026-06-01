<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Api\Token;

/**
 * Magic-link token contract.
 *
 * base module side interface used by the customer-identity factory, the lookup
 * controller, and the frontend form blocks. Two implementations:
 *
 *  - Default: `Model\Token\NoOpMagicLinkService` returns null /
 *    empty string for everything. module-only installs satisfy Art.
 *    11a(1) "as easy as conclusion" via the lookup form (CJEU
 *    DHL/Amazon precedent treats lookup forms as legally sufficient).
 *  - Pro `MageMe_EUWithdrawalMagicLink`: the DB-backed
 *    `MagicLinkService` issues configurable-lifetime tokens (30-day
 *    default, multi-use), persists them in `mm_eu_withdrawal_magic_link`, and
 *    inserts a `?t=TOKEN` CTA into the order/shipment confirmation
 *    emails for one-click guest access.
 *
 * The Pro implementation also emits `mageme_eu_withdrawal_audit_token_issued`
 * and `mageme_eu_withdrawal_audit_token_used` events for the audit
 * trail; these are no-ops in the NoOp implementation.
 */
interface MagicLinkServiceInterface
{
    /**
     * Resolve a plain token to an order entity id.
     *
     * Returns null when the token is invalid, expired, used, or
     * revoked — also when no token-issuing implementation is bound
     * (Free default).
     *
     * @param string $plainToken
     * @return int|null
     */
    public function resolveOrder(string $plainToken): ?int;

    /**
     * Issue a fresh token for the supplied order, or return an
     * already-active token if one exists.
     *
     * Returns an empty string in the free tier (no-op) — callers
     * must treat empty as "no token issued, fall back to
     * session-only verification".
     *
     * @param int $orderEntityId
     * @return string Plain token (hex), or empty string in the base module.
     */
    public function issueOrReuseForOrder(int $orderEntityId): string;
}
