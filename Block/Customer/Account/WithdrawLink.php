<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Block\Customer\Account;

use MageMe\EUWithdrawal\Model\Frontend\FooterLinkLabelResolver;
use MageMe\EUWithdrawal\Model\ModuleConfig;
use Magento\Customer\Block\Account\SortLink;
use Magento\Framework\App\DefaultPathInterface;
use Magento\Framework\View\Element\Template\Context;

class WithdrawLink extends SortLink
{
    /**
     * Constructor.
     *
     * @param Context $context
     * @param DefaultPathInterface $defaultPath
     * @param FooterLinkLabelResolver $labelResolver
     * @param ModuleConfig $moduleConfig
     * @param array $data
     */
    public function __construct(
        Context $context,
        DefaultPathInterface $defaultPath,
        private readonly FooterLinkLabelResolver $labelResolver,
        private readonly ModuleConfig $moduleConfig,
        array $data = [],
    ) {
        parent::__construct($context, $defaultPath, $data);
    }

    /**
     * Get label.
     *
     * @return string
     */
    public function getLabel(): string
    {
        return $this->labelResolver->sidebarLabel();
    }

    /**
     * To html.
     *
     * @return string
     */
    protected function _toHtml(): string
    {
        if (!$this->moduleConfig->isEnabled()) {
            return '';
        }
        return parent::_toHtml();
    }
}
