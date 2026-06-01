<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Api\Data\Precontract;

/**
 * Resolver contract for the customer-facing pre-contract snapshot.
 *
 * Two implementations bind to this interface:
 *
 *  - Default: `Model\Precontract\LiveSnapshotResolver` renders the
 *    snapshot on the fly per request and returns a value-object
 *    (`LiveSnapshot`). No DB persistence — the rendered text is
 *    re-derivable from the bundled XML + admin merchant vars.
 *  - Pro `MageMe_EUWithdrawalAnnexI`: `Model\Precontract\DBSnapshotResolver`
 *    persists each unique content hash as a row in
 *    `mm_eu_withdrawal_tc_snapshot` and returns the persisted model.
 *    Required for forensic-grade Art. 6(5) immutability evidence.
 *
 * Both impls satisfy Art. 6(1)(h) / Art. 11a(1) display requirements;
 * only Pro satisfies Art. 10(1) extended-period defence with
 * documentary (vs testimonial) proof.
 */
interface SnapshotResolverInterface
{
    /**
     * Resolve the current pre-contract snapshot for the supplied locale.
     *
     * Walks the locale fallback chain to pick a canonical locale, renders
     * Annex I(A) + I(B) with merchant variables substituted, and returns a
     * snapshot object. The Pro implementation persists the snapshot if the
     * content hash is new; the base module implementation always returns a fresh
     * in-memory value object.
     *
     * @param string $locale
     * @return SnapshotInterface
     */
    public function getOrCreateForCurrent(string $locale): SnapshotInterface;
}
