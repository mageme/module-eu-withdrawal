<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Plugin\Sales;

use MageMe\EUWithdrawal\Model\ModuleConfig;
use MageMe\EUWithdrawal\Model\Waiver\WaiverEventReader;
use MageMe\EUWithdrawal\Model\Waiver\WaiverTextResolver;
use MageMe\EUWithdrawal\Model\Waiver\WaiverTextHasher;
use MageMe\EUWithdrawal\Service\DigitalContentDetector;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Api\CartManagementInterface as Subject;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

class OrderPlacementGuard
{
    public const XML_DIGITAL_ENABLED = 'mageme_eu_withdrawal/digital_waiver/enabled';
    public const XML_ENFORCE_ON_API  = 'mageme_eu_withdrawal/digital_waiver/enforce_on_api';

    private const HEADLESS_AREAS = ['webapi_rest', 'graphql'];

    /**
     * Constructor.
     *
     * @param WaiverEventReader $reader
     * @param WaiverTextResolver $resolver
     * @param WaiverTextHasher $hasher
     * @param DigitalContentDetector $detector
     * @param CartRepositoryInterface $cartRepo
     * @param StoreManagerInterface $storeManager
     * @param ModuleConfig $moduleConfig
     * @param ScopeConfigInterface $scopeConfig
     * @param State $appState
     */
    public function __construct(
        private readonly WaiverEventReader $reader,
        private readonly WaiverTextResolver $resolver,
        private readonly WaiverTextHasher $hasher,
        private readonly DigitalContentDetector $detector,
        private readonly CartRepositoryInterface $cartRepo,
        private readonly StoreManagerInterface $storeManager,
        private readonly ModuleConfig $moduleConfig,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly State $appState,
    ) {
    }

    /**
     * Before place order.
     *
     * @param Subject $subject
     * @param int $cartId
     * @param mixed $paymentMethod
     * @return array
     */
    public function beforePlaceOrder(Subject $subject, int $cartId, $paymentMethod = null): array
    {
        $quote = $this->cartRepo->get($cartId);
        $storeId = (int) $quote->getStoreId();

        if (!$this->moduleConfig->isEnabled($storeId)
            || !$this->scopeConfig->isSetFlag(self::XML_DIGITAL_ENABLED, ScopeInterface::SCOPE_STORE, $storeId)
        ) {
            return [$cartId, $paymentMethod];
        }
        if ($this->isHeadless()
            && !$this->scopeConfig->isSetFlag(self::XML_ENFORCE_ON_API, ScopeInterface::SCOPE_STORE, $storeId)
        ) {
            return [$cartId, $paymentMethod];
        }

        $digital = $this->detector->filterDigitalItems($quote->getAllVisibleItems());
        if (empty($digital)) {
            return [$cartId, $paymentMethod];
        }
        $store = $this->storeManager->getStore($storeId);
        $locale = (string) $store->getConfig('general/locale/code');
        $jurisdiction = strtoupper(substr((string) ($quote->getBillingAddress()?->getCountryId() ?? ''), 0, 2));
        $jurisdictionKey = $jurisdiction !== '' ? $jurisdiction : '__eu_generic__';
        $snap = $this->resolver->resolve($locale, $jurisdictionKey);
        $expectedHash = $this->hasher->hash($snap['consent'], $snap['acknowledgment'], $locale, $jurisdictionKey);

        foreach ($digital as $qi) {
            $events = $this->reader->findQuoteEvents((int) $qi->getItemId());
            $types = [];
            foreach ($events as $e) {
                if ((int) ($e['consent_value'] ?? 0) !== 1) {
                    continue;
                }
                if (!hash_equals($expectedHash, (string) ($e['waiver_text_hash'] ?? ''))) {
                    throw new LocalizedException(__('Digital content waiver text has changed — please reload checkout.'));
                }
                $types[] = (string) $e['event_type'];
            }
            if (!in_array(WaiverEventReader::EVT_AFFIRM, $types, true)
                || !in_array(WaiverEventReader::EVT_CONSENT, $types, true)
                || !in_array(WaiverEventReader::EVT_LOSS, $types, true)
            ) {
                throw new LocalizedException(__('Digital content waiver is incomplete for item %1.', (string) $qi->getSku()));
            }
        }
        return [$cartId, $paymentMethod];
    }

    /**
     * Whether the current request is a headless (REST/GraphQL) entry point.
     *
     * @return bool
     */
    private function isHeadless(): bool
    {
        try {
            return in_array($this->appState->getAreaCode(), self::HEADLESS_AREAS, true);
        } catch (\Throwable) {
            return false;
        }
    }
}
