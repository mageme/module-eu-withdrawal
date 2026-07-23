<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Plugin\Checkout;

use MageMe\EUWithdrawal\Model\Scope\WithdrawalScope;
use MageMe\EUWithdrawal\Service\DigitalContentDetector;
use Magento\Checkout\Block\Checkout\LayoutProcessor as Subject;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\UrlInterface;

class LayoutProcessorPlugin
{
    /**
     * uiComponent name of the waiver step (steps container child).
     */
    private const WAIVER_COMPONENT = 'checkout.steps.eu-withdrawal-waiver-step';

    /**
     * Constructor.
     *
     * @param UrlInterface $url
     * @param CheckoutSession $checkoutSession
     * @param DigitalContentDetector $detector
     * @param WithdrawalScope $withdrawalScope
     */
    public function __construct(
        private readonly UrlInterface $url,
        private readonly CheckoutSession $checkoutSession,
        private readonly DigitalContentDetector $detector,
        private readonly WithdrawalScope $withdrawalScope,
    ) {
    }

    /**
     * After process.
     *
     * @param Subject $subject
     * @param array $jsLayout
     * @return array
     */
    public function afterProcess(Subject $subject, array $jsLayout): array
    {
        $step = &$jsLayout['components']['checkout']['children']['steps']['children']['eu-withdrawal-waiver-step'];
        $step['config'] ??= [];
        // Controllers live under Controller/Withdraw/Waiver/*, so Magento routes
        // them at /withdraw-contract/withdraw_waiver/{action} (nested directory
        // becomes underscore in the URL path).
        $step['config']['contextUrl'] = $this->url->getUrl('withdraw-contract/withdraw_waiver/context');
        $step['config']['saveUrl']    = $this->url->getUrl('withdraw-contract/withdraw_waiver/save');
        // Server-computed flag lets the step register with step-navigator
        // synchronously in initialize(), instead of waiting for the async
        // context fetch: the progress bar reads the already-registered steps to
        // pick the first one on a hash-less checkout load.
        $step['config']['hasDigitalContent'] = $this->hasDigitalContent();

        // The progress bar selects the first checkout step from the steps
        // registered when it initializes; declaring the waiver step as a
        // dependency makes it wait for that step's synchronous registration
        // first, so a hash-less load cannot land past the waiver.
        $deps = &$jsLayout['components']['checkout']['children']['progressBar']['config']['deps'];
        $deps ??= [];
        if (!in_array(self::WAIVER_COMPONENT, $deps, true)) {
            $deps[] = self::WAIVER_COMPONENT;
        }

        return $jsLayout;
    }

    /**
     * Whether the current quote is in withdrawal scope and carries digital
     * content — the same gate the waiver context endpoint applies before it
     * returns any items.
     *
     * @return bool
     */
    private function hasDigitalContent(): bool
    {
        try {
            $quote = $this->checkoutSession->getQuote();
        } catch (\Throwable) {
            return false;
        }
        if (!$this->withdrawalScope->quoteInScope($quote)) {
            return false;
        }

        return $this->detector->filterDigitalItems($quote->getAllVisibleItems()) !== [];
    }
}
