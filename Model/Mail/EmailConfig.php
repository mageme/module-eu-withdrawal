<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Mail;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Resolves admin-configurable settings for each customer-facing email
 * (template ID, enabled flag, BCC list). Backed by `mageme_eu_withdrawal/
 * notifications/<type>/{enabled,template,bcc_merchant}` for the 6
 * transactional emails, and `mageme_eu_withdrawal/digital_waiver/email/
 * {enabled,template,bcc_merchant,identity}` for the digital-waiver
 * confirmation. The constants below keep the email-type identifiers
 * stable so callers don't drift from the XML paths.
 *
 * Defaults shipped in `etc/config.xml` point at the bundled template IDs
 * registered in `etc/email_templates.xml`. Merchants can override per
 * store-view using Magento's standard `Marketing → Email Templates` flow
 * — pick a template there and assign it via this admin field.
 */
class EmailConfig
{
    public const TYPE_SUBMITTED            = 'submitted';
    public const TYPE_APPROVED             = 'approved';
    public const TYPE_DENIED               = 'denied';
    public const TYPE_CANCELLED_ADMIN      = 'cancelled_admin';
    public const TYPE_CANCELLED_SELF       = 'cancelled_self';
    public const TYPE_RECEIPT              = 'receipt';
    public const TYPE_WAIVER_CONFIRMATION  = 'waiver_confirmation';
    public const TYPE_ADMIN_NEW_REQUEST    = 'new_request';

    /** @var string[] admin-notification types (live under PATH_ADMIN, not PATH_DEFAULT) */
    private const ADMIN_TYPES = [self::TYPE_ADMIN_NEW_REQUEST];

    private const PATH_DEFAULT = 'mageme_eu_withdrawal/notifications/%s/%s';
    private const PATH_WAIVER_CONFIRMATION = 'mageme_eu_withdrawal/digital_waiver/email/%s';
    private const PATH_ADMIN = 'mageme_eu_withdrawal/admin_notifications/%s/%s';

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
     * Resolve the config path for the given email type and field. The
     * waiver_confirmation email lives under `digital_waiver/email/*` after
     * the settings rebuild; all other types stay under `notifications/<type>/*`.
     *
     * @param string $type
     * @param string $field
     * @return string
     */
    private function path(string $type, string $field): string
    {
        if ($type === self::TYPE_WAIVER_CONFIRMATION) {
            return sprintf(self::PATH_WAIVER_CONFIRMATION, $field);
        }
        if (in_array($type, self::ADMIN_TYPES, true)) {
            return sprintf(self::PATH_ADMIN, $type, $field);
        }
        return sprintf(self::PATH_DEFAULT, $type, $field);
    }

    /**
     * Is enabled.
     *
     * @param string $type
     * @param ?int $storeId
     * @return bool
     */
    public function isEnabled(string $type, ?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            $this->path($type, 'enabled'),
            ScopeInterface::SCOPE_STORE,
            $storeId,
        );
    }

    /**
     * Get template.
     *
     * @param string $type
     * @param ?int $storeId
     * @return string
     */
    public function getTemplate(string $type, ?int $storeId = null): string
    {
        return (string) $this->scopeConfig->getValue(
            $this->path($type, 'template'),
            ScopeInterface::SCOPE_STORE,
            $storeId,
        );
    }

    /**
     * Get bcc csv.
     *
     * @param string $type
     * @param ?int $storeId
     * @return string
     */
    public function getBccCsv(string $type, ?int $storeId = null): string
    {
        return trim((string) $this->scopeConfig->getValue(
            $this->path($type, 'bcc_merchant'),
            ScopeInterface::SCOPE_STORE,
            $storeId,
        ));
    }

    /**
     * @return string[]  zero or more validated email addresses
     */
    public function getBccList(string $type, ?int $storeId = null): array
    {
        $csv = $this->getBccCsv($type, $storeId);
        if ($csv === '') {
            return [];
        }
        $out = [];
        foreach ((array) preg_split('/[\s,;]+/', $csv) as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_EMAIL)) {
                $out[] = $candidate;
            }
        }
        return $out;
    }

    /**
     * @return string[]  zero or more validated email addresses
     */
    public function getRecipientList(string $type, ?int $storeId = null): array
    {
        $raw = trim((string) $this->scopeConfig->getValue(
            $this->path($type, 'recipients'),
            ScopeInterface::SCOPE_STORE,
            $storeId,
        ));
        if ($raw === '') {
            return [];
        }
        $out = [];
        foreach ((array) preg_split('/[\s,;]+/', $raw) as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_EMAIL)) {
                $out[] = $candidate;
            }
        }
        return $out;
    }
}
