<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Block\Html;

use MageMe\EUWithdrawal\Model\Frontend\FooterLinkLabelResolver;
use MageMe\EUWithdrawal\Model\ModuleConfig;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;

class FooterLink extends Template
{
    /**
     * Constructor.
     *
     * @param Context $context
     * @param FooterLinkLabelResolver $labelResolver
     * @param ModuleConfig $moduleConfig
     * @param array $data
     */
    public function __construct(
        Context $context,
        private readonly FooterLinkLabelResolver $labelResolver,
        private readonly ModuleConfig $moduleConfig,
        array $data = [],
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Get withdraw url.
     *
     * @return string
     */
    public function getWithdrawUrl(): string
    {
        return $this->getUrl('withdraw-contract');
    }

    /**
     * Get label.
     *
     * @return string
     */
    public function getLabel(): string
    {
        return $this->labelResolver->step1Label();
    }

    /**
     * Whether the storefront footer link should render.
     *
     * @return bool
     */
    public function isVisible(): bool
    {
        return $this->moduleConfig->isEnabled() && $this->moduleConfig->isFooterLinkEnabled();
    }

    /**
     * To html.
     *
     * @return string
     */
    protected function _toHtml(): string
    {
        if (!$this->isVisible()) {
            return '';
        }
        return parent::_toHtml();
    }
}
