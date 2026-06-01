<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Controller;

use MageMe\EUWithdrawal\Model\Frontend\RouteResolver;
use Magento\Framework\App\ActionFactory;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\RouterInterface;

/**
 * Maps the merchant's vanity prefix (admin config
 * `mageme_eu_withdrawal/general/frontend_route`) onto the canonical
 * `withdraw-contract` route registered in `etc/frontend/routes.xml`.
 *
 * Skips silently when the configured value is the canonical name (default)
 * — the standard router handles that case. When the prefix has been
 * customised, this router rewrites the request's pathInfo from
 * `/<vanity>/...` to `/withdraw-contract/...` and returns null so the
 * standard router resolves the rewritten path. The canonical path stays
 * routable too, so magic links sent before the prefix change keep working.
 */
class Router implements RouterInterface
{
    /**
     * Constructor.
     *
     * @param ActionFactory $actionFactory
     * @param RouteResolver $routeResolver
     */
    public function __construct(
        private readonly ActionFactory $actionFactory,
        private readonly RouteResolver $routeResolver,
    ) {
    }

    public function match(RequestInterface $request): ?ActionInterface
    {
        if (!$this->routeResolver->isCustomised()) {
            return null;
        }
        $vanity = $this->routeResolver->getConfiguredFrontName();
        $path = trim((string) $request->getPathInfo(), '/');
        if ($path === '') {
            return null;
        }
        $segments = explode('/', $path, 2);
        if ($segments[0] !== $vanity) {
            return null;
        }
        $rewritten = RouteResolver::CANONICAL_FRONT_NAME
            . (isset($segments[1]) ? '/' . $segments[1] : '');
        $request->setPathInfo('/' . $rewritten);
        return null;
    }
}
