<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Block\Checkout;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context as BlockContext;
use Magento\Store\Model\ScopeInterface;

class WaiverStep extends Template
{
    public const XML_ENABLED = 'mageme_eu_withdrawal/digital_waiver/enabled';

    /**
     * Constructor.
     *
     * @param BlockContext $context
     * @param ScopeConfigInterface $config
     * @param UrlInterface $url
     * @param array $data
     */
    public function __construct(
        BlockContext $context,
        private readonly ScopeConfigInterface $config,
        private readonly UrlInterface $url,
        array $data = [],
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Is enabled.
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        return (bool) $this->config->getValue(self::XML_ENABLED, ScopeInterface::SCOPE_STORE);
    }

    /**
     * Get context url.
     *
     * @return string
     */
    public function getContextUrl(): string
    {
        return $this->url->getUrl('withdraw-contract/waiver/context');
    }

    /**
     * Get save url.
     *
     * @return string
     */
    public function getSaveUrl(): string
    {
        return $this->url->getUrl('withdraw-contract/waiver/save');
    }
}
