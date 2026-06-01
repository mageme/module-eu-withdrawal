<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Block\Checkout;

use MageMe\EUWithdrawal\Api\Data\Precontract\SnapshotInterface;
use MageMe\EUWithdrawal\Api\Data\Precontract\SnapshotResolverInterface;
use MageMe\EUWithdrawal\Model\ModuleConfig;
use MageMe\EUWithdrawal\Model\Precontract\Exception\MissingMerchantVarsException;
use Magento\Framework\Locale\ResolverInterface as LocaleResolver;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Server-side data provider for the customer-facing checkout precontract
 * block. Resolves the snapshot once at page render and exposes its
 * rendered text + version to the KO uiComponent via getJsLayout-style
 * config (passed through LayoutProcessorPlugin).
 */
class PrecontractInfo extends Template
{
    private ?SnapshotInterface $cachedSnapshot = null;

    /**
     * Constructor.
     *
     * @param Context $context
     * @param ModuleConfig $moduleConfig
     * @param SnapshotResolverInterface $snapshotResolver
     * @param StoreManagerInterface $storeManager
     * @param LocaleResolver $localeResolver
     * @param LoggerInterface $logger
     * @param array $data
     */
    public function __construct(
        Context $context,
        private readonly ModuleConfig $moduleConfig,
        private readonly SnapshotResolverInterface $snapshotResolver,
        private readonly StoreManagerInterface $storeManager,
        private readonly LocaleResolver $localeResolver,
        private readonly LoggerInterface $logger,
        array $data = [],
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Whether the block should render.
     *
     * @return bool
     */
    public function isAvailable(): bool
    {
        if (!$this->moduleConfig->isEnabled()) {
            return false;
        }
        return (bool) $this->_scopeConfig->getValue(
            'mageme_eu_withdrawal/precontract/enabled',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
        ) && (bool) $this->_scopeConfig->getValue(
            'mageme_eu_withdrawal/precontract/display_in_checkout',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
        );
    }

    /**
     * Get the resolved snapshot, or null if unresolvable.
     *
     * @return SnapshotInterface|null
     */
    public function getSnapshot(): ?SnapshotInterface
    {
        if ($this->cachedSnapshot !== null) {
            return $this->cachedSnapshot;
        }
        try {
            // Use LocaleResolver, not Store::getCurrentLocaleCode() — the latter
            // returns empty string in storefront contexts where the locale isn't
            // explicitly bound to the Store object (the actual locale lives in
            // the LocaleResolver, which reads general/locale/code per request).
            $locale = (string) $this->localeResolver->getLocale();
            return $this->cachedSnapshot = $this->snapshotResolver->getOrCreateForCurrent($locale);
        } catch (MissingMerchantVarsException $e) {
            $this->logger->warning(
                'Pre-contract block hidden: merchant vars missing — ' . $e->getMessage()
            );
            return null;
        } catch (\Throwable $e) {
            $this->logger->error('Pre-contract resolver failed: ' . $e->getMessage(), ['exception' => $e]);
            return null;
        }
    }

    /**
     * Get log endpoint URL.
     *
     * @return string
     */
    public function getLogEndpointUrl(): string
    {
        return $this->getUrl('mageme_eu_withdrawal/precontract/logdisplay');
    }

    /**
     * Get download Annex I(B) URL.
     *
     * @return string
     */
    public function getDownloadAnnexIbUrl(): string
    {
        return $this->getUrl('mageme_eu_withdrawal/precontract/downloadannexib');
    }

    /**
     * Get start-return URL.
     *
     * Front page of the customer-facing withdrawal flow. Customers usually
     * exercise their right via this SPA rather than the legally-required
     * Annex I(B) text form.
     *
     * @return string
     */
    public function getStartReturnUrl(): string
    {
        return $this->getUrl('mageme_eu_withdrawal');
    }

    /**
     * Get the snapshot publish date formatted with the storefront locale
     * (e.g. en_US: "Apr 29, 2026"; de_DE: "29. Apr. 2026"; fr_FR: "29 avr. 2026").
     *
     * @return string
     */
    public function getFormattedPublishedAt(): string
    {
        $snapshot = $this->getSnapshot();
        if ($snapshot === null) {
            return '';
        }
        $date = (string) $snapshot->getPublishedAt();
        if ($date === '') {
            return '';
        }
        return $this->formatDate($date, \IntlDateFormatter::MEDIUM);
    }
}
