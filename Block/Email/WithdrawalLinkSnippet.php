<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Block\Email;

use MageMe\EUWithdrawal\Model\Frontend\FooterLinkLabelResolver;
use Magento\Framework\Phrase;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;

/**
 * Renders the "Right of withdrawal" CTA card injected into order /
 * shipment emails. Surface — heading, reminder text, exclusions note, and
 * a bulletproof button that survives every major email client.
 *
 * The URL behind the button comes from the consumer-of-the-snippet via
 * `setData('withdrawal_link_url', ...)` — observer side. Free injects the
 * lookup-form URL, MagicLink Pro injects a tokenised one-click URL.
 */
class WithdrawalLinkSnippet extends Template
{
    /**
     * Constructor.
     *
     * @param Context $context
     * @param FooterLinkLabelResolver $labelResolver
     * @param array $data
     */
    public function __construct(
        Context $context,
        private readonly FooterLinkLabelResolver $labelResolver,
        array $data = [],
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Get button label.
     *
     * @return string
     */
    public function getButtonLabel(): string
    {
        $locale = $this->getData('locale');
        return $this->labelResolver->step1Label(is_string($locale) && $locale !== '' ? $locale : null);
    }

    /**
     * Get withdrawal link url.
     *
     * @return ?string
     */
    public function getWithdrawalLinkUrl(): ?string
    {
        $url = $this->getData('withdrawal_link_url');
        return is_string($url) && $url !== '' ? $url : null;
    }

    /**
     * Get heading.
     *
     * @return Phrase
     */
    public function getHeading(): Phrase
    {
        return __('Right of withdrawal');
    }

    /**
     * Get reminder text.
     *
     * Phrasing follows Art. 9(2)(b) CRD: for sales of goods the 14-day
     * window starts when the consumer acquires physical possession. The
     * "from the day you receive" phrasing avoids the common merchant
     * mistake of measuring from order placement.
     *
     * @return Phrase
     */
    public function getReminderText(): Phrase
    {
        return __(
            'You have 14 days from the day you receive your order to withdraw without giving any reason — full refund to your original payment method.',
        );
    }

    /**
     * Get exclusions note.
     *
     * Surfaces the Art. 16 CRD presets so the customer is not promised an
     * unconditional right; the next-step UI shows exactly which items are
     * eligible (per-item exclusion reason from `EligibilityEngine`).
     *
     * @return Phrase
     */
    public function getExclusionsNote(): Phrase
    {
        return __(
            'Some items may be excluded by law (perishable goods, custom-made items, sealed hygiene or audio/video products). We\'ll show you exactly what\'s eligible on the next page.',
        );
    }

    /**
     * Get helper text.
     *
     * @return Phrase
     */
    public function getHelperText(): Phrase
    {
        return __('Direct link — no login or account password required.');
    }

    /**
     * @return string
     */
    protected function _toHtml()
    {
        try {
            $html = parent::_toHtml();
            if ($html !== '') {
                return $html;
            }
        } catch (\Throwable $e) {
            $this->_logger->error(
                'WithdrawalLinkSnippet phtml render failed, using fallback: ' . $e->getMessage(),
                ['exception' => $e],
            );
        }
        return $this->renderFallback();
    }

    /**
     * @return string
     */
    private function renderFallback(): string
    {
        $url = $this->getWithdrawalLinkUrl();
        if ($url === null) {
            return '';
        }
        $heading  = $this->escapeHtml($this->getHeading());
        $reminder = $this->escapeHtml($this->getReminderText());
        $label    = $this->escapeHtml($this->getButtonLabel());
        $href     = $this->escapeUrl($url);

        return <<<HTML
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"
       style="margin:24px 0 0;font-family:'Open Sans','Helvetica Neue',Helvetica,Arial,sans-serif;border-collapse:collapse;">
    <tr>
        <td style="background:#ffffff;border:1px solid #e4e7ec;border-left:4px solid #1d4ed8;border-radius:8px;padding:20px;">
            <div style="margin:0 0 6px;font-size:16px;font-weight:600;color:#101828;line-height:1.3;">{$heading}</div>
            <div style="margin:0 0 14px;font-size:13px;color:#475569;line-height:1.5;">{$reminder}</div>
            <table role="presentation" cellspacing="0" cellpadding="0" border="0" style="border-collapse:separate;line-height:100%;">
                <tr>
                    <td align="center" valign="middle" bgcolor="#1d4ed8" style="border-radius:6px;background:#1d4ed8;mso-padding-alt:10px 18px;">
                        <a href="{$href}" style="display:inline-block;background:#1d4ed8;color:#ffffff !important;text-decoration:none !important;padding:10px 18px;border-radius:6px;font-weight:600;font-size:14px;line-height:1;font-family:'Open Sans','Helvetica Neue',Helvetica,Arial,sans-serif;mso-padding-alt:0;">
                            <span style="color:#ffffff !important;text-decoration:none;">{$label}</span>
                        </a>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
HTML;
    }
}
