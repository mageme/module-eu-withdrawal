<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Api\Data\Precontract;

interface SnapshotInterface
{
    public const SNAPSHOT_ID             = 'snapshot_id';
    public const LOCALE                  = 'locale';
    public const VERSION                 = 'version';
    public const CONTENT_HASH            = 'content_hash';
    public const ANNEX_IA_TEXT           = 'annex_ia_text';
    public const ANNEX_IB_TEXT           = 'annex_ib_text';
    public const PERIOD_DAYS             = 'period_days';
    public const MERCHANT_NAME           = 'merchant_name';
    public const MERCHANT_ADDRESS        = 'merchant_address';
    public const MERCHANT_PHONE          = 'merchant_phone';
    public const MERCHANT_EMAIL          = 'merchant_email';
    public const MERCHANT_RETURN_ADDRESS = 'merchant_return_address';
    public const PUBLISHED_AT            = 'published_at';
    public const DEPRECATED_AT           = 'deprecated_at';

    /** @return int|null */
    public function getId(): ?int;

    /** @return string */
    public function getLocale(): string;

    /** @return string */
    public function getVersion(): string;

    /** @return string */
    public function getContentHash(): string;

    /** @return string */
    public function getAnnexIaText(): string;

    /** @return string */
    public function getAnnexIbText(): string;

    /** @return int */
    public function getPeriodDays(): int;

    /** @return string */
    public function getMerchantName(): string;

    /** @return string */
    public function getMerchantAddress(): string;

    /** @return string|null */
    public function getMerchantPhone(): ?string;

    /** @return string */
    public function getMerchantEmail(): string;

    /** @return string */
    public function getMerchantReturnAddress(): string;

    /** @return string */
    public function getPublishedAt(): string;

    /** @return string|null */
    public function getDeprecatedAt(): ?string;
}
