<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */

declare(strict_types=1);

namespace MageMe\EUWithdrawal\Observer;

use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\UrlInterface;
use Magento\ReCaptchaUi\Model\IsCaptchaEnabledInterface;
use Magento\ReCaptchaUi\Model\RequestHandlerInterface;

/**
 * Validates the reCAPTCHA token submitted with the step 1a guest lookup form
 * (`/<vanity>/withdraw/lookup`). Active only when an admin has selected a
 * captcha type for the `mageme_eu_withdrawal_lookup` key under
 * Stores → Configuration → Security → Google reCAPTCHA Storefront. On
 * failure `RequestHandlerInterface::execute()` redirects the guest back to
 * the configured fail URL — the bare front_name `/<vanity>/` — which renders
 * step 1a again with the standard banner.
 */
class ReCaptchaLookupObserver implements ObserverInterface
{
    private const RECAPTCHA_KEY = 'mageme_eu_withdrawal_lookup';

    /**
     * @param UrlInterface $url
     * @param IsCaptchaEnabledInterface $isCaptchaEnabled
     * @param RequestHandlerInterface $requestHandler
     * @param HttpResponse $response
     */
    public function __construct(
        private readonly UrlInterface $url,
        private readonly IsCaptchaEnabledInterface $isCaptchaEnabled,
        private readonly RequestHandlerInterface $requestHandler,
        private readonly HttpResponse $response,
    ) {
    }

    /**
     * Execute.
     *
     * @param Observer $observer
     * @return void
     * @throws LocalizedException
     */
    public function execute(Observer $observer): void
    {
        if (!$this->isCaptchaEnabled->isCaptchaEnabledFor(self::RECAPTCHA_KEY)) {
            return;
        }

        // `controller_action_predispatch_*` only exposes `request` on the event
        // payload — `response` is omitted in modern Magento. Going through
        // `$observer->getControllerAction()->getResponse()` is also a non-starter
        // because Lookup implements only `HttpPostActionInterface` and does not
        // extend `Action`, so its Interceptor has neither `getRequest()` nor
        // `getResponse()`. The current request-scoped HttpResponse is injected.
        $request = $observer->getEvent()->getRequest();
        $redirectOnFailureUrl = $this->url->getUrl('mageme_eu_withdrawal/index/index');

        $this->requestHandler->execute(self::RECAPTCHA_KEY, $request, $this->response, $redirectOnFailureUrl);
    }
}
