<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Precontract;

use MageMe\EUWithdrawal\Api\Data\Precontract\SnapshotInterface;

/**
 * In-memory snapshot value-object used by the free tier (no DB persistence).
 *
 * The Pro `MageMe_EUWithdrawalAnnexI` add-on replaces this with a
 * `Model\Precontract\Snapshot` (extends `AbstractModel`, persisted to
 * `mm_eu_withdrawal_tc_snapshot`) returned by `DBSnapshotResolver`. Free
 * regenerates the rendered text on every render — so `getId()` is always
 * null and `getDeprecatedAt()` is always null. `getVersion()` is derived
 * from the content hash so two renders that yield identical text return
 * identical version labels.
 */
class LiveSnapshot implements SnapshotInterface
{
    /**
     * Constructor.
     *
     * @param string $locale Resolved locale (after fallback chain).
     * @param string $contentHash SHA-256 of `annex_ia_text . annex_ib_text`.
     * @param string $annexIaText Rendered Annex I(A), sections joined by "\n\n".
     * @param string $annexIbText Rendered Annex I(B) model withdrawal form.
     * @param int $periodDays
     * @param string $merchantName
     * @param string $merchantAddress
     * @param string|null $merchantPhone
     * @param string $merchantEmail
     * @param string $merchantReturnAddress
     * @param string $publishedAt GMT timestamp `Y-m-d H:i:s` set at construction.
     */
    public function __construct(
        private readonly string $locale,
        private readonly string $contentHash,
        private readonly string $annexIaText,
        private readonly string $annexIbText,
        private readonly int $periodDays,
        private readonly string $merchantName,
        private readonly string $merchantAddress,
        private readonly ?string $merchantPhone,
        private readonly string $merchantEmail,
        private readonly string $merchantReturnAddress,
        private readonly string $publishedAt,
    ) {
    }

    /**
     * Get id.
     *
     * @return int|null Always null — The base module has no persisted snapshot row.
     */
    public function getId(): ?int
    {
        return null;
    }

    /**
     * Get locale.
     *
     * @return string
     */
    public function getLocale(): string
    {
        return $this->locale;
    }

    /**
     * Get version.
     *
     * Derives a stable version label from the content hash (first 7 hex
     * chars). The base module has no version progression — identical content always
     * yields the same label.
     *
     * @return string
     */
    public function getVersion(): string
    {
        return '1.0+' . substr($this->contentHash, 0, 7);
    }

    /**
     * Get content hash.
     *
     * @return string
     */
    public function getContentHash(): string
    {
        return $this->contentHash;
    }

    /**
     * Get annex IA text.
     *
     * @return string
     */
    public function getAnnexIaText(): string
    {
        return $this->annexIaText;
    }

    /**
     * Get annex IB text.
     *
     * @return string
     */
    public function getAnnexIbText(): string
    {
        return $this->annexIbText;
    }

    /**
     * Get period days.
     *
     * @return int
     */
    public function getPeriodDays(): int
    {
        return $this->periodDays;
    }

    /**
     * Get merchant name.
     *
     * @return string
     */
    public function getMerchantName(): string
    {
        return $this->merchantName;
    }

    /**
     * Get merchant address.
     *
     * @return string
     */
    public function getMerchantAddress(): string
    {
        return $this->merchantAddress;
    }

    /**
     * Get merchant phone.
     *
     * @return string|null
     */
    public function getMerchantPhone(): ?string
    {
        return $this->merchantPhone;
    }

    /**
     * Get merchant email.
     *
     * @return string
     */
    public function getMerchantEmail(): string
    {
        return $this->merchantEmail;
    }

    /**
     * Get merchant return address.
     *
     * @return string
     */
    public function getMerchantReturnAddress(): string
    {
        return $this->merchantReturnAddress;
    }

    /**
     * Get published at.
     *
     * @return string
     */
    public function getPublishedAt(): string
    {
        return $this->publishedAt;
    }

    /**
     * Get deprecated at.
     *
     * @return string|null Always null — Free snapshots are never deprecated.
     */
    public function getDeprecatedAt(): ?string
    {
        return null;
    }
}
