<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Notification;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Notification\MessageInterface;
use Magento\Framework\UrlInterface;

/**
 * Admin notification banner shown while MageMe_EUWithdrawal is installed but
 * disabled (`mageme_eu_withdrawal/general/enabled = 0`). Serves as the Gap #13
 * compliance-gate replacement after the 6-step setup wizard was removed from
 * Free in spec #1 (2026-04-24).
 *
 * Disappears automatically once the merchant flips the flag to 1 — condition
 * is re-evaluated every admin page load.
 */
class NotConfigured implements MessageInterface
{
    public const CONFIG_PATH_ENABLED = 'mageme_eu_withdrawal/general/enabled';
    public const IDENTITY_KEY = 'MM_EU_WITHDRAWAL_NOT_CONFIGURED_v1';

    /**
     * Constructor.
     *
     * @param ScopeConfigInterface $config
     * @param UrlInterface $urlBuilder
     */
    public function __construct(
        private readonly ScopeConfigInterface $config,
        private readonly UrlInterface $urlBuilder,
    ) {
    }

    /**
     * Get identity.
     *
     * @return string
     */
    public function getIdentity(): string
    {
        return hash('sha256', self::IDENTITY_KEY);
    }

    /**
     * Is displayed.
     *
     * @return bool
     */
    public function isDisplayed(): bool
    {
        return !$this->config->isSetFlag(self::CONFIG_PATH_ENABLED);
    }

    /**
     * Get text.
     *
     * @return string
     */
    public function getText(): string
    {
        $configUrl = $this->urlBuilder->getUrl(
            'adminhtml/system_config/edit',
            ['section' => 'mageme_eu_withdrawal']
        );

        return (string) __(
            'MageMe EU Withdrawal is installed but not enabled. '
            . 'Review the compliance checklist and configure the module before enabling: '
            . '<a href="%1">Stores → Configuration → MageMe Extensions → EU Withdrawal</a>.',
            $configUrl
        );
    }

    /**
     * Get severity.
     *
     * @return int
     */
    public function getSeverity(): int
    {
        return self::SEVERITY_MAJOR;
    }
}
