<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Precontract;

use MageMe\EUWithdrawal\Api\Data\Precontract\SnapshotInterface;
use MageMe\EUWithdrawal\Api\Data\Precontract\SnapshotResolverInterface;
use MageMe\EUWithdrawal\Model\Locale\LocaleFallbackResolver;

/**
 * free-tier resolver: renders the snapshot in memory on every call and
 * returns a `LiveSnapshot` value-object. No DB persistence, no
 * deprecation chain.
 *
 * The Pro `MageMe_EUWithdrawalAnnexI` add-on overrides this binding via
 * `etc/di.xml` `<preference>` to its own `DBSnapshotResolver`, which
 * persists each unique content hash and supersedes prior rows for that
 * locale.
 *
 * Both implementations satisfy Art. 6(1)(h) display: the consumer sees
 * Annex I(A) at checkout. Only the Pro path satisfies Art. 10(1)
 * extended-period defence with documentary evidence.
 */
class LiveSnapshotResolver implements SnapshotResolverInterface
{
    /**
     * Constructor.
     *
     * @param MerchantVarsResolver $merchantVarsResolver
     * @param AnnexIRenderer $renderer
     * @param LocaleFallbackResolver $fallbackResolver
     */
    public function __construct(
        private readonly MerchantVarsResolver $merchantVarsResolver,
        private readonly AnnexIRenderer $renderer,
        private readonly LocaleFallbackResolver $fallbackResolver,
    ) {
    }

    /**
     * Get or create for current.
     *
     * Picks the canonical locale via `LocaleFallbackResolver` (first chain
     * entry, normalising empty / malformed input to en_US), renders Annex
     * I(A) + I(B) with merchant variables substituted, computes the
     * content hash, and returns a fresh value-object. Subsequent calls
     * with the same locale return a new instance with the same hash —
     * The base module has no caching layer.
     *
     * @param string $locale
     * @return SnapshotInterface
     */
    public function getOrCreateForCurrent(string $locale): SnapshotInterface
    {
        $chain = $this->fallbackResolver->resolve($locale);
        $locale = $chain[0];

        $merchantVars = $this->merchantVarsResolver->resolve($locale);
        $rendered = $this->renderer->render($locale, $merchantVars);
        $contentHash = hash('sha256', $rendered['ia'] . $rendered['ib']);

        return new LiveSnapshot(
            locale: $locale,
            contentHash: $contentHash,
            annexIaText: $rendered['ia'],
            annexIbText: $rendered['ib'],
            periodDays: (int) ($merchantVars['period_days'] ?? 14),
            merchantName: (string) ($merchantVars['merchant_name'] ?? ''),
            merchantAddress: (string) ($merchantVars['merchant_address'] ?? ''),
            merchantPhone: $merchantVars['merchant_phone'] ?? null,
            merchantEmail: (string) ($merchantVars['merchant_email'] ?? ''),
            merchantReturnAddress: (string) ($merchantVars['merchant_return_address'] ?? ''),
            publishedAt: gmdate('Y-m-d H:i:s'),
        );
    }
}
