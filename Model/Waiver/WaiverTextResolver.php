<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Waiver;

use MageMe\EUWithdrawal\Model\Locale\LocaleFallbackResolver;
use Magento\Framework\App\Config\ScopeConfigInterface;

class WaiverTextResolver
{
    public const CFG_OVERRIDE_BASE = 'mageme_eu_withdrawal/digital_waiver/waiver_texts';

    /**
     * Constructor.
     *
     * @param WaiverTextLoaderInterface $loader
     * @param ScopeConfigInterface $scopeConfig
     * @param LocaleFallbackResolver $fallbackResolver
     */
    public function __construct(
        private readonly WaiverTextLoaderInterface $loader,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly LocaleFallbackResolver $fallbackResolver,
    ) {
    }

    /** @return array{consent:string,acknowledgment:string} */
    public function resolve(string $locale, string $jurisdiction): array
    {
        $override = $this->adminOverride($locale);
        $xml = null;
        foreach ($this->fallbackResolver->resolve($locale) as $candidate) {
            $xml = $this->loader->load($candidate, $jurisdiction)
                ?? $this->loader->load($candidate, '__eu_generic__');
            if ($xml !== null) {
                break;
            }
        }
        $consent = $override['consent'] !== '' ? $override['consent'] : ($xml['consent'] ?? '');
        $ack = $override['acknowledgment'] !== '' ? $override['acknowledgment'] : ($xml['acknowledgment'] ?? '');
        if ($consent === '' || $ack === '') {
            throw new \RuntimeException(sprintf('No waiver text for locale=%s jurisdiction=%s', $locale, $jurisdiction));
        }
        return ['consent' => $consent, 'acknowledgment' => $ack];
    }

    /** @return array{consent:string,acknowledgment:string} */
    private function adminOverride(string $locale): array
    {
        $path = self::CFG_OVERRIDE_BASE . '/' . $locale;
        return [
            'consent' => trim((string) $this->scopeConfig->getValue($path . '/consent_text', 'store', null)),
            'acknowledgment' => trim((string) $this->scopeConfig->getValue($path . '/acknowledgment_text', 'store', null)),
        ];
    }
}
