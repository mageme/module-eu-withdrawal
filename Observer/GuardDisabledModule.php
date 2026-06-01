<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Observer;

use MageMe\EUWithdrawal\Model\Frontend\RouteResolver;
use MageMe\EUWithdrawal\Model\ModuleConfig;
use Magento\Framework\App\ActionFlag;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\UrlInterface;

/**
 * Storefront-only `controller_action_predispatch` guard. When the master
 * "Enable Module" flag is off and the request targets the module's
 * customer-facing route (`withdraw-contract/*`), redirect to the storefront
 * home and stop further dispatch. Admin requests under
 * `mageme_eu_withdrawal/*` are intentionally NOT gated — merchants must
 * keep access to existing data while the module is in dormant state.
 *
 * Wired in `etc/frontend/events.xml` so the observer runs only on the
 * frontend area (admin predispatch events fire under a different handle).
 */
class GuardDisabledModule implements ObserverInterface
{
    /**
     * Constructor.
     *
     * @param ModuleConfig $moduleConfig
     * @param RouteResolver $routeResolver
     * @param UrlInterface $urlBuilder
     * @param ResponseInterface $response
     * @param ActionFlag $actionFlag
     */
    public function __construct(
        private readonly ModuleConfig $moduleConfig,
        private readonly RouteResolver $routeResolver,
        private readonly UrlInterface $urlBuilder,
        private readonly ResponseInterface $response,
        private readonly ActionFlag $actionFlag,
    ) {
    }

    /**
     * Execute.
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        if ($this->moduleConfig->isEnabled()) {
            return;
        }

        /** @var \Magento\Framework\App\RequestInterface|null $request */
        $request = $observer->getEvent()->getRequest();
        if ($request === null) {
            return;
        }
        $frontName = (string) $request->getFrontName();
        $allowed = [
            $this->routeResolver->getCanonicalFrontName(),
            $this->routeResolver->getConfiguredFrontName(),
        ];
        if (!in_array($frontName, $allowed, true)) {
            return;
        }

        $this->actionFlag->set('', \Magento\Framework\App\ActionInterface::FLAG_NO_DISPATCH, true);
        $this->response->setRedirect($this->urlBuilder->getUrl(''));
    }
}
