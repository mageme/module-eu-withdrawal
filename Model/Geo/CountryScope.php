<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Geo;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Merchant-controlled storefront scoping: restricts where the self-service
 * withdrawal flow is offered. It does not decide where EU consumer law applies.
 */
class CountryScope
{
    public const XML_ENABLED   = 'mageme_eu_withdrawal/scope/country_scope_enabled';
    public const XML_COUNTRIES = 'mageme_eu_withdrawal/scope/country_scope_countries';

    /**
     * Constructor.
     *
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
    ) {
    }

    /**
     * Whether the restriction is active for the store.
     *
     * @param int $storeId
     * @return bool
     */
    public function isActive(int $storeId): bool
    {
        if (!$this->scopeConfig->isSetFlag(self::XML_ENABLED, ScopeInterface::SCOPE_STORE, $storeId)) {
            return false;
        }
        return $this->countries($storeId) !== [];
    }

    /**
     * Whether the order's billing or shipping country is in scope.
     *
     * @param OrderInterface $order
     * @return bool
     */
    public function orderInScope(OrderInterface $order): bool
    {
        $shipping = method_exists($order, 'getShippingAddress')
            ? $order->getShippingAddress()?->getCountryId()
            : null;
        return $this->inScope(
            (int) $order->getStoreId(),
            $order->getBillingAddress()?->getCountryId(),
            $shipping,
        );
    }

    /**
     * Whether the quote's billing or shipping country is in scope.
     *
     * @param CartInterface $quote
     * @return bool
     */
    public function quoteInScope(CartInterface $quote): bool
    {
        $shipping = method_exists($quote, 'getShippingAddress')
            ? $quote->getShippingAddress()?->getCountryId()
            : null;
        return $this->inScope(
            (int) $quote->getStoreId(),
            $quote->getBillingAddress()?->getCountryId(),
            $shipping,
        );
    }

    /**
     * Match either country against the configured list; fail open.
     *
     * @param int $storeId
     * @param ?string $billing
     * @param ?string $shipping
     * @return bool
     */
    private function inScope(int $storeId, ?string $billing, ?string $shipping): bool
    {
        if (!$this->isActive($storeId)) {
            return true;
        }
        $billing = strtoupper(trim((string) $billing));
        $shipping = strtoupper(trim((string) $shipping));
        if ($billing === '' && $shipping === '') {
            return true;
        }
        $countries = $this->countries($storeId);
        return ($billing !== '' && in_array($billing, $countries, true))
            || ($shipping !== '' && in_array($shipping, $countries, true));
    }

    /**
     * Configured country codes.
     *
     * @param int $storeId
     * @return string[]
     */
    private function countries(int $storeId): array
    {
        $raw = (string) $this->scopeConfig->getValue(self::XML_COUNTRIES, ScopeInterface::SCOPE_STORE, $storeId);
        return array_values(array_filter(array_map(
            static fn (string $c): string => strtoupper(trim($c)),
            explode(',', $raw),
        )));
    }
}
