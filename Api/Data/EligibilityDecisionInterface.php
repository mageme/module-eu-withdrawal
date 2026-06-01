<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Api\Data;

interface EligibilityDecisionInterface
{
    /** toArray() keys. */
    public const ELIGIBLE        = 'eligible';
    public const IS_FINAL        = 'is_final';
    public const PERIOD_END      = 'period_end';
    public const REASON          = 'reason';
    public const EXCLUSION_BASIS = 'exclusion_basis';
    public const APPLIED_RULES   = 'applied_rules';

    /**
     * Is eligible.
     *
     * @return bool
     */
    public function isEligible(): bool;

    /**
     * Is final.
     *
     * @return bool
     */
    public function isFinal(): bool;

    /**
     * Get period end.
     *
     * @return ?\DateTimeImmutable
     */
    public function getPeriodEnd(): ?\DateTimeImmutable;

    /**
     * Get reason.
     *
     * @return ?string
     */
    public function getReason(): ?string;

    /**
     * Get exclusion basis.
     *
     * @return ?string
     */
    public function getExclusionBasis(): ?string;

    /**
     * @return string[]
     */
    public function getAppliedRules(): array;

    /**
     * With period end.
     *
     * @param \DateTimeImmutable $end
     * @return self
     */
    public function withPeriodEnd(\DateTimeImmutable $end): self;

    /**
     * With deny.
     *
     * @param string $reason
     * @param string $exclusionBasis
     * @return self
     */
    public function withDeny(string $reason, string $exclusionBasis): self;

    /**
     * With finalize.
     *
     * @param string $reason
     * @return self
     */
    public function withFinalize(string $reason): self;

    /**
     * With applied.
     *
     * @param string $ruleCode
     * @return self
     */
    public function withApplied(string $ruleCode): self;

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
