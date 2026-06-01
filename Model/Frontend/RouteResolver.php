<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Frontend;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Resolves the storefront URL prefix used by the customer-facing withdrawal
 * flow. Backed by `mageme_eu_withdrawal/general/frontend_route` (admin form
 * value). The canonical route registered in `etc/frontend/routes.xml` is
 * `withdraw-contract` — that name never changes at runtime. The configured
 * value is treated as a *vanity prefix*: `Controller\Router` rewrites
 * incoming requests on the configured path back to `withdraw-contract`,
 * and `Plugin\Framework\UrlPlugin` rewrites outgoing URLs the other way.
 *
 * Both the canonical name and the configured value are kept routable so
 * magic links sent before the merchant changed the prefix continue to work.
 */
class RouteResolver
{
    public const CANONICAL_FRONT_NAME = 'withdraw-contract';
    public const XML_FRONTEND_ROUTE = 'mageme_eu_withdrawal/general/frontend_route';

    /**
     * Constructor.
     *
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
    ) {
    }

    /**
     * Get canonical front name.
     *
     * @return string
     */
    public function getCanonicalFrontName(): string
    {
        return self::CANONICAL_FRONT_NAME;
    }

    /**
     * Get configured front name.
     *
     * @param ?int $storeId
     * @return string
     */
    public function getConfiguredFrontName(?int $storeId = null): string
    {
        $raw = (string) $this->scopeConfig->getValue(
            self::XML_FRONTEND_ROUTE,
            ScopeInterface::SCOPE_STORE,
            $storeId,
        );
        $sanitised = preg_replace('/[^a-z0-9_-]+/i', '', strtolower(trim($raw))) ?? '';
        return $sanitised !== '' ? $sanitised : self::CANONICAL_FRONT_NAME;
    }

    /**
     * Is customised.
     *
     * @param ?int $storeId
     * @return bool
     */
    public function isCustomised(?int $storeId = null): bool
    {
        return $this->getConfiguredFrontName($storeId) !== self::CANONICAL_FRONT_NAME;
    }

    /**
     * Swap the canonical front_name segment for the configured vanity prefix
     * inside a fully-formed URL. No-op when not customised.
     *
     * @param string $url
     * @param ?int $storeId
     * @return string
     */
    public function rewriteCanonical(string $url, ?int $storeId = null): string
    {
        if ($url === '' || !$this->isCustomised($storeId)) {
            return $url;
        }
        $canonical = preg_quote(self::CANONICAL_FRONT_NAME, '~');
        $vanity = $this->getConfiguredFrontName($storeId);
        return preg_replace(
            '~/' . $canonical . '(?=/|\?|#|$)~',
            '/' . $vanity,
            $url,
            1,
        ) ?? $url;
    }
}
