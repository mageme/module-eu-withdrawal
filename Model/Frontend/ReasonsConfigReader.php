<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Frontend;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use Magento\Store\Model\ScopeInterface;

/**
 * Reads the admin-configured per-item return-reason list and the "Other"
 * toggle. Backend stores the grid as a serialized array via Magento's
 * ArraySerialized backend model; this reader normalises the rows to a stable
 * `code => label` shape and drops malformed entries.
 *
 * `other` is reserved — when the toggle is on, it is appended automatically
 * by the consumer (frontend block / validators) and must not be configured
 * manually in the grid.
 */
class ReasonsConfigReader
{
    public const XML_PATH_REASONS = 'mageme_eu_withdrawal/frontend/return_reasons/reasons';
    public const XML_PATH_ENABLE_OTHER = 'mageme_eu_withdrawal/frontend/return_reasons/enable_other';
    public const RESERVED_CODE_OTHER = 'other';

    /**
     * Constructor.
     *
     * @param ScopeConfigInterface $scopeConfig
     * @param JsonSerializer $json
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly JsonSerializer $json,
    ) {
    }

    /**
     * Configured reasons in admin-defined order. Keys are codes (lowercase,
     * snake_case, ≤32 chars); values are labels. The reserved `other` code
     * is excluded — call isOtherEnabled() and append separately if needed.
     *
     * @return array<string, string>
     */
    public function getReasons(?int $storeId = null): array
    {
        $raw = $this->scopeConfig->getValue(
            self::XML_PATH_REASONS,
            ScopeInterface::SCOPE_STORE,
            $storeId,
        );
        if ($raw === null || $raw === '') {
            return [];
        }
        $decoded = $this->decode($raw);
        $out = [];
        foreach ($decoded as $row) {
            if (!is_array($row)) {
                continue;
            }
            $code  = isset($row['code']) ? trim((string) $row['code']) : '';
            $label = isset($row['label']) ? trim((string) $row['label']) : '';
            if ($code === '' || $label === '') {
                continue;
            }
            $code = strtolower($code);
            if ($code === self::RESERVED_CODE_OTHER) {
                continue;
            }
            // Sanity-cap to match the DB column (varchar 32).
            if (strlen($code) > 32) {
                continue;
            }
            $out[$code] = $label;
        }
        return $out;
    }

    /**
     * Is other enabled.
     *
     * @param ?int $storeId
     * @return bool
     */
    public function isOtherEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_ENABLE_OTHER,
            ScopeInterface::SCOPE_STORE,
            $storeId,
        );
    }

    /**
     * Whitelist of accepted codes for POST validation. Includes `other` when
     * the toggle is on.
     *
     * @return array<string, true>
     */
    public function getAllowedCodes(?int $storeId = null): array
    {
        $allowed = [];
        foreach (array_keys($this->getReasons($storeId)) as $code) {
            $allowed[$code] = true;
        }
        if ($this->isOtherEnabled($storeId)) {
            $allowed[self::RESERVED_CODE_OTHER] = true;
        }
        return $allowed;
    }

    /**
     * Resolve a code to its admin-configured label, falling back to a
     * humanised version of the code when it isn't in the current config (e.g.
     * old request rows whose preset was later removed by the merchant).
     */
    public function resolveLabel(string $code, ?int $storeId = null): string
    {
        $reasons = $this->getReasons($storeId);
        if (isset($reasons[$code])) {
            return $reasons[$code];
        }
        if ($code === self::RESERVED_CODE_OTHER) {
            return 'Other';
        }
        return ucwords(str_replace('_', ' ', $code));
    }

    /**
     * @param mixed $raw
     * @return array<int|string, mixed>
     */
    private function decode($raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (!is_string($raw)) {
            return [];
        }
        try {
            $decoded = $this->json->unserialize($raw);
        } catch (\Throwable) {
            return [];
        }
        return is_array($decoded) ? $decoded : [];
    }
}
