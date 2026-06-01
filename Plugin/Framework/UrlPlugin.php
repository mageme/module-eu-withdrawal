<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Plugin\Framework;

use MageMe\EUWithdrawal\Model\Frontend\RouteResolver;
use Magento\Framework\Url;

/**
 * Outgoing-URL companion to `Controller\Router`. When the merchant has set
 * a vanity prefix in admin config, every URL emitted via
 * `Magento\Framework\Url::getUrl()` for the canonical `withdraw-contract`
 * front_name has its first non-store path segment swapped for the vanity
 * value. The canonical name stays the only thing registered in
 * `routes.xml`, so internal `getUrl('withdraw-contract/...')` calls stay
 * stable across the codebase — the rewrite happens at the very last step.
 *
 * Frontend area only (registered in `etc/frontend/di.xml`).
 */
class UrlPlugin
{
    /**
     * Constructor.
     *
     * @param RouteResolver $routeResolver
     */
    public function __construct(
        private readonly RouteResolver $routeResolver,
    ) {
    }

    /**
     * After get url.
     *
     * @param Url $subject
     * @param mixed $result
     * @param mixed $routePath
     * @param mixed $routeParams
     */
    public function afterGetUrl(Url $subject, $result, $routePath = null, $routeParams = null)
    {
        if (!is_string($result)) {
            return $result;
        }
        return $this->routeResolver->rewriteCanonical($result);
    }
}
