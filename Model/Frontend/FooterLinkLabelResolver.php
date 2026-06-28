<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Frontend;

use MageMe\EUWithdrawal\Model\Locale\LocaleFallbackResolver;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Locale\ResolverInterface;
use Magento\Framework\Phrase;
use Magento\Store\Model\ScopeInterface;

class FooterLinkLabelResolver
{
    public const XML_OVERRIDE_STEP1 = 'mageme_eu_withdrawal/frontend/buttons/button_label_step1';
    public const XML_OVERRIDE_STEP2 = 'mageme_eu_withdrawal/frontend/buttons/button_label_step2';

    /**
     * Constructor.
     *
     * @param ButtonLabelsConfigReader $reader
     * @param ResolverInterface $localeResolver
     * @param LocaleFallbackResolver $fallbackResolver
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        private readonly ButtonLabelsConfigReader $reader,
        private readonly ResolverInterface $localeResolver,
        private readonly LocaleFallbackResolver $fallbackResolver,
        private readonly ScopeConfigInterface $scopeConfig,
    ) {
    }

    /**
     * Step1 label.
     *
     * @param ?string $locale
     * @return string
     */
    public function step1Label(?string $locale = null): string
    {
        return $this->override(self::XML_OVERRIDE_STEP1) ?? $this->resolve($locale)['step1'];
    }

    /**
     * Step2 label.
     *
     * @param ?string $locale
     * @return string
     */
    public function step2Label(?string $locale = null): string
    {
        return $this->override(self::XML_OVERRIDE_STEP2) ?? $this->resolve($locale)['step2'];
    }

    /**
     * Sidebar label.
     *
     * @param ?string $locale
     * @return string
     */
    public function sidebarLabel(?string $locale = null): string
    {
        return $this->resolve($locale)['sidebar'];
    }

    /**
     * Wraps the raw whitelist string in a Phrase WITHOUT calling __(). The
     * Art. 11a(1) CRD button labels are legally frozen per locale; routing
     * them through Magento's translation layer would let a stray i18n CSV
     * mutate the legally required wording. SortLink::label requires a Phrase,
     * so we construct one with zero arguments (no translate hook fires).
     */
    public function sidebarLabelPhrase(?string $locale = null): Phrase
    {
        return new Phrase($this->sidebarLabel($locale));
    }

    /**
     * Counsel-signed admin override for a step label. Empty (the default)
     * means "use the built-in, per-locale whitelist label". A non-empty value
     * is returned verbatim — the merchant owns its legal correctness.
     *
     * @param string $path
     * @return ?string
     */
    private function override(string $path): ?string
    {
        $value = trim((string) $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE));
        return $value !== '' ? $value : null;
    }

    /** @return array{step1: string, step2: string, sidebar: string, _fallback: bool} */
    private function resolve(?string $locale): array
    {
        $code = $locale ?? $this->localeResolver->getLocale();
        $all = $this->reader->read();
        foreach ($this->fallbackResolver->resolve($code) as $candidate) {
            if (isset($all[$candidate])) {
                return $all[$candidate];
            }
        }
        return $all[$this->reader->getFallbackCode()];
    }
}
