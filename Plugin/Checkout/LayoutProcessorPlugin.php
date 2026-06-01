<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Plugin\Checkout;

use Magento\Checkout\Block\Checkout\LayoutProcessor as Subject;
use Magento\Framework\UrlInterface;

class LayoutProcessorPlugin
{
    /**
     * Constructor.
     *
     * @param UrlInterface $url
     */
    public function __construct(private readonly UrlInterface $url)
    {
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
        $jsLayout['components']['checkout']['children']['steps']['children']['eu-withdrawal-waiver-step']['config'] ??= [];
        // Controllers live under Controller/Withdraw/Waiver/*, so Magento routes
        // them at /withdraw-contract/withdraw_waiver/{action} (nested directory
        // becomes underscore in the URL path).
        $jsLayout['components']['checkout']['children']['steps']['children']['eu-withdrawal-waiver-step']['config']['contextUrl'] = $this->url->getUrl('withdraw-contract/withdraw_waiver/context');
        $jsLayout['components']['checkout']['children']['steps']['children']['eu-withdrawal-waiver-step']['config']['saveUrl']    = $this->url->getUrl('withdraw-contract/withdraw_waiver/save');
        return $jsLayout;
    }
}
