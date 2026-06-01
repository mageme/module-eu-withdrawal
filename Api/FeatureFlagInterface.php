<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Api;

interface FeatureFlagInterface
{
    public const FLAG_HASH_CHAIN_AUDIT = 'hash_chain_audit';
    public const FLAG_JURISDICTION_PACKS = 'jurisdiction_packs';
    public const FLAG_ADVANCED_RULE_ENGINE = 'advanced_rule_engine';
    public const FLAG_EVIDENCE_PACK_EXPORT = 'evidence_pack_export';
    public const FLAG_GRAPHQL_API = 'graphql_api';
    public const FLAG_CARRIER_INTEGRATIONS = 'carrier_integrations';
    public const FLAG_MULTI_TENANT = 'multi_tenant';
    public const FLAG_DIGITAL_SERVICE_MODE = 'digital_service_mode';
    public const FLAG_B2B_CLASSIFICATION = 'b2b_classification';

    public const TIER_FREE = 'Free';
    public const TIER_PRO = 'Pro';

    /**
     * @return string One of TIER_FREE | TIER_PRO.
     *                Derived from which MageMe_EUWithdrawal* Magento modules are
     *                installed and enabled — not from a runtime license key.
     */
    public function getInstalledTier(): string;

    /**
     * @param string $flag One of the FLAG_* constants.
     * @return bool True if the feature is enabled for the currently installed tier.
     *              In the base module package this always returns false; Pro/Enterprise
     *              add-on packages override the preference to return true for
     *              flags within their tier.
     */
    public function isFeatureEnabled(string $flag): bool;
}
