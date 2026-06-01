<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Api\Email;

/**
 * Resolves the URL placed behind the "Withdraw from contract" CTA in
 * order-confirmation and shipment-notification emails.
 *
 * The base module ships a default implementation that returns the lookup-form URL
 * (`/withdraw-contract/`) — the customer enters their order # + email and is
 * then routed into the withdrawal SPA. This is the Art. 11a(1) "as easy as
 * conclusion" floor (CJEU treats lookup forms as legally sufficient).
 *
 * Pro tier `MageMe_EUWithdrawalMagicLink` overrides this preference via
 * `<preference for="...WithdrawalLinkResolverInterface" type="...
 * MagicLinkWithdrawalLinkResolver"/>` to upgrade the URL to a tokenised
 * one-click variant (`/withdraw-contract?t=TOKEN`) — same CTA placement,
 * better UX.
 */
interface WithdrawalLinkResolverInterface
{
    /**
     * Resolve the withdrawal-CTA URL for the given order entity id.
     *
     * @param int $orderEntityId
     * @param ?int $storeId Order's store id; falls back to current store when null
     * @return string Absolute URL (scheme + host + path), with or without `?t=TOKEN` query
     */
    public function resolveForOrder(int $orderEntityId, ?int $storeId = null): string;
}
