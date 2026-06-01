<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Email;

use MageMe\EUWithdrawal\Api\Email\WithdrawalLinkResolverInterface;
use MageMe\EUWithdrawal\Model\Frontend\RouteResolver;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Default free-tier resolver — returns the storefront lookup-form URL
 * (`/withdraw-contract/`). Customer arrives at the lookup form, types their
 * order # + email, and is routed into the withdrawal SPA from there.
 *
 * Overridden by `MageMe_EUWithdrawalMagicLink` Pro module via DI <preference>
 * to a tokenised one-click variant.
 */
class LookupWithdrawalLinkResolver implements WithdrawalLinkResolverInterface
{
    /**
     * Constructor.
     *
     * @param StoreManagerInterface $storeManager
     * @param RouteResolver $routeResolver
     */
    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly RouteResolver $routeResolver,
    ) {
    }

    /**
     * Resolve for order.
     *
     * @param int $orderEntityId
     * @param ?int $storeId
     * @return string
     */
    public function resolveForOrder(int $orderEntityId, ?int $storeId = null): string
    {
        $base = rtrim((string) $this->storeManager->getStore($storeId)->getBaseUrl(), '/');
        return $this->routeResolver->rewriteCanonical(
            $base . '/' . RouteResolver::CANONICAL_FRONT_NAME . '/',
            $storeId,
        );
    }
}
