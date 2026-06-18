<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Mail;

use MageMe\EUWithdrawal\Api\Email\WithdrawalLinkResolverInterface;

/**
 * Resolves the "view" CTA URL placed in lifecycle emails (submitted / approved).
 *
 * Registered customers are sent to their order page. Guests have no account, so
 * the order-view and account pages both bounce them to a login they cannot pass;
 * they are instead routed to the withdrawal entry point — the lookup form by
 * default, or the one-click magic link when the Pro `MageMe_EUWithdrawalMagicLink`
 * add-on rebinds `WithdrawalLinkResolverInterface`.
 */
class CustomerViewUrlResolver
{
    /**
     * Constructor.
     *
     * @param WithdrawalLinkResolverInterface $withdrawalLinkResolver
     */
    public function __construct(
        private readonly WithdrawalLinkResolverInterface $withdrawalLinkResolver,
    ) {
    }

    /**
     * @param int $orderEntityId Order this email relates to (0 when unknown)
     * @param ?int $customerId Account id on the request; null/0 means a guest order
     * @param ?int $storeId Order store id (for the withdrawal-link resolver)
     * @param string $baseUrl Store base URL
     */
    public function resolveForCustomer(
        int $orderEntityId,
        ?int $customerId,
        ?int $storeId,
        string $baseUrl,
    ): string {
        $base = rtrim($baseUrl, '/');
        if ($orderEntityId <= 0) {
            return $base . '/customer/account/';
        }
        if (($customerId ?? 0) <= 0) {
            return $this->withdrawalLinkResolver->resolveForOrder($orderEntityId, $storeId);
        }
        return $base . '/sales/order/view/order_id/' . $orderEntityId . '/';
    }
}
