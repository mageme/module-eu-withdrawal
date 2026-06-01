<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Api;

use MageMe\EUWithdrawal\Api\Data\EligibilityDecisionInterface;
use MageMe\EUWithdrawal\Api\Data\EligibilityRequestInterface;

interface RuleInterface
{
    public const SCOPE_ORDER = 'order';
    public const SCOPE_ITEM = 'item';

    /**
     * Stable rule code recorded in applied_rules[] (e.g. "period_rule", "preset_perishable").
     */
    public function getCode(): string;

    /**
     * Execution order. Lower runs first. Phase-02 allocation:
     * - PeriodRule = 10
     * - Art. 16 presets = 50
     */
    public function getPriority(): int;

    /**
     * Determines the pass in which the rule runs:
     * - SCOPE_ORDER — once per request, before item pass.
     * - SCOPE_ITEM — once per order-item, after order pass.
     */
    public function getScope(): string;

    /**
     * Whether the rule should run for this request/current config.
     * Config gates check preset toggles here; returning false skips the rule
     * entirely (not recorded in applied_rules).
     */
    public function applies(EligibilityRequestInterface $request): bool;

    /**
     * Evaluate the rule against the current decision. Returns a new decision
     * (may be identity-equal if the rule doesn't change anything, but rules
     * that fire SHOULD call withApplied($this->getCode()) so the audit trail
     * records them).
     */
    public function evaluate(
        EligibilityRequestInterface $request,
        EligibilityDecisionInterface $current,
    ): EligibilityDecisionInterface;
}
