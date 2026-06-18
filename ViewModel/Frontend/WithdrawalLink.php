<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\ViewModel\Frontend;

use MageMe\EUWithdrawal\Model\Frontend\FooterLinkLabelResolver;
use MageMe\EUWithdrawal\Model\ModuleConfig;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;

/**
 * Markup-free accessor for the withdrawal link, for use in any template or
 * container: read isEnabled()/getUrl()/getLabel() and render whatever HTML the
 * theme needs. Gated by the module master switch only — placement is the
 * caller's choice (the footer-specific toggle lives on the FooterLink block).
 */
class WithdrawalLink implements ArgumentInterface
{
    /**
     * Constructor.
     *
     * @param UrlInterface $url
     * @param FooterLinkLabelResolver $labelResolver
     * @param ModuleConfig $moduleConfig
     */
    public function __construct(
        private readonly UrlInterface $url,
        private readonly FooterLinkLabelResolver $labelResolver,
        private readonly ModuleConfig $moduleConfig,
    ) {
    }

    /**
     * Whether the withdrawal flow is available (module master switch).
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->moduleConfig->isEnabled();
    }

    /**
     * Storefront URL of the withdrawal landing page.
     *
     * @return string
     */
    public function getUrl(): string
    {
        return $this->url->getUrl('withdraw-contract');
    }

    /**
     * Link label (configured Step 1 button label).
     *
     * @return string
     */
    public function getLabel(): string
    {
        return $this->labelResolver->step1Label();
    }
}
