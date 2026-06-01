<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Block\Withdraw;

use Magento\Framework\View\Element\Template;
use Magento\ReCaptchaUi\Block\ReCaptcha;

class FindOrderForm extends Template
{
    /**
     * Render the storefront reCAPTCHA widget for the guest lookup form.
     *
     * Active only when an admin has chosen a captcha type for the
     * `mageme_eu_withdrawal_lookup` key under Stores → Configuration →
     * Security → Google reCAPTCHA Storefront. The matching predispatch
     * observer (`Observer\ReCaptchaLookupObserver`) validates the token
     * server-side. Returns an empty string on any layout/render failure
     * so a misconfigured captcha never blocks the lookup form from
     * rendering.
     *
     * @return string
     */
    public function renderReCaptcha(): string
    {
        try {
            $block = $this->getLayout()->createBlock(
                ReCaptcha::class,
                'mageme_eu_withdrawal_recaptcha_lookup',
                [
                    'data' => [
                        'recaptcha_for' => 'mageme_eu_withdrawal_lookup',
                        'jsLayout' => [
                            'components' => [
                                'recaptcha' => [
                                    'component' => 'Magento_ReCaptchaFrontendUi/js/reCaptcha',
                                ],
                            ],
                        ],
                    ],
                ],
            );
            $block->setTemplate('Magento_ReCaptchaFrontendUi::recaptcha.phtml');
            return $block->toHtml();
        } catch (\Exception $e) {
            return '';
        }
    }

    /**
     * Get form action.
     *
     * @return string
     */
    public function getFormAction(): string
    {
        // Step 1a posts to a dedicated lookup endpoint that verifies the
        // email+order_id pair with anti-enumeration timing before letting the
        // guest reach step 2. Raw `/index?order_id=X` would skip the check.
        return $this->getUrl('withdraw-contract/withdraw/lookup');
    }

    /**
     * Is lookup failed.
     *
     * @return bool
     */
    public function isLookupFailed(): bool
    {
        return (string) $this->getRequest()->getParam('lookup', '') === 'fail';
    }

    /**
     * Get support url.
     *
     * @return string
     */
    public function getSupportUrl(): string
    {
        return $this->getUrl('contact');
    }
}
