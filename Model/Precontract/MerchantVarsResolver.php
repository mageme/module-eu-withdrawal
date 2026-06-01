<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Precontract;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Resolves the merchant-vars associative array used by AnnexIRenderer
 * for the current store scope. Pulls from existing Magento config:
 * - general/store_information/{name,phone,street_line1,city,country_id,postcode}
 * - trans_email/ident_support/email
 * - mageme_eu_withdrawal/withdrawal_window/period_days
 * - mageme_eu_withdrawal/precontract/return_address
 */
class MerchantVarsResolver
{
    private const PATH_PERIOD_DAYS    = 'mageme_eu_withdrawal/withdrawal_window/period_days';
    private const PATH_RETURN_ADDRESS = 'mageme_eu_withdrawal/precontract/return_address';
    private const PATH_STORE_NAME     = 'general/store_information/name';
    private const PATH_STORE_STREET   = 'general/store_information/street_line1';
    private const PATH_STORE_CITY     = 'general/store_information/city';
    private const PATH_STORE_POSTCODE = 'general/store_information/postcode';
    private const PATH_STORE_COUNTRY  = 'general/store_information/country_id';
    private const PATH_STORE_PHONE    = 'general/store_information/phone';
    private const PATH_SUPPORT_EMAIL  = 'trans_email/ident_support/email';

    /**
     * Constructor.
     *
     * @param ScopeConfigInterface $scopeConfig
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StoreManagerInterface $storeManager,
    ) {
    }

    /**
     * Resolve.
     *
     * @param string $locale Reserved for future per-locale overrides; currently unused.
     * @return array<string, string>
     */
    public function resolve(string $locale): array
    {
        $scope = ScopeInterface::SCOPE_STORE;
        $storeId = (int) $this->storeManager->getStore()->getId();

        $name     = (string) $this->scopeConfig->getValue(self::PATH_STORE_NAME, $scope, $storeId);
        $street   = (string) $this->scopeConfig->getValue(self::PATH_STORE_STREET, $scope, $storeId);
        $city     = (string) $this->scopeConfig->getValue(self::PATH_STORE_CITY, $scope, $storeId);
        $postcode = (string) $this->scopeConfig->getValue(self::PATH_STORE_POSTCODE, $scope, $storeId);
        $country  = (string) $this->scopeConfig->getValue(self::PATH_STORE_COUNTRY, $scope, $storeId);
        $phone    = (string) $this->scopeConfig->getValue(self::PATH_STORE_PHONE, $scope, $storeId);
        $email    = (string) $this->scopeConfig->getValue(self::PATH_SUPPORT_EMAIL, $scope, $storeId);

        $address = trim($street . ', ' . $city . ' ' . $postcode . ', ' . $country, ', ');

        $returnAddressOverride = trim((string) $this->scopeConfig->getValue(self::PATH_RETURN_ADDRESS, $scope, $storeId));
        $returnAddress = $returnAddressOverride !== '' ? $returnAddressOverride : $address;

        $periodDays = (int) ($this->scopeConfig->getValue(self::PATH_PERIOD_DAYS, $scope, $storeId) ?: 14);

        return [
            'period_days'             => (string) $periodDays,
            'merchant_name'           => $name,
            'merchant_address'        => $address,
            'merchant_phone'          => $phone,
            'merchant_email'          => $email,
            'merchant_return_address' => $returnAddress,
        ];
    }
}
