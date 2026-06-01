<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model;

use Magento\Framework\App\DeploymentConfig;

class Config
{
    public const XML_PATH_IP_HASH_SALT = 'eu_withdrawal/ip_hash_salt';

    /**
     * Constructor.
     *
     * @param DeploymentConfig $deploymentConfig
     */
    public function __construct(
        private readonly DeploymentConfig $deploymentConfig,
    ) {
    }

    /**
     * Get ip hash salt.
     *
     * @return string
     */
    public function getIpHashSalt(): string
    {
        $value = $this->deploymentConfig->get(self::XML_PATH_IP_HASH_SALT);
        if (is_string($value) && $value !== '') {
            return $value;
        }
        // No explicit salt configured: derive a per-install secret from the
        // deployment crypt key so IP hashes stay unreversible from a DB dump
        // and never share one hardcoded salt across installs.
        $cryptKey = $this->deploymentConfig->get('crypt/key');
        if (is_string($cryptKey) && $cryptKey !== '') {
            return hash('sha256', 'eu_withdrawal_ip_hash_salt|' . $cryptKey);
        }
        return 'mageme-eu-withdrawal-default-salt-change-me';
    }
}
