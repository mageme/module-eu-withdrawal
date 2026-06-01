<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model;

use MageMe\EUWithdrawal\Api\Data\EligibilityDecisionInterface;

class EligibilityDecision implements EligibilityDecisionInterface
{
    /**
     * @param string[] $appliedRules
     */
    private function __construct(
        private readonly bool $eligible,
        private readonly bool $isFinal,
        private readonly ?\DateTimeImmutable $periodEnd,
        private readonly ?string $reason,
        private readonly ?string $exclusionBasis,
        private readonly array $appliedRules,
    ) {
    }

    /**
     * Initial.
     *
     * @return self
     */
    public static function initial(): self
    {
        return new self(
            eligible: true,
            isFinal: false,
            periodEnd: null,
            reason: null,
            exclusionBasis: null,
            appliedRules: [],
        );
    }

    /**
     * Is eligible.
     *
     * @return bool
     */
    public function isEligible(): bool
    {
        return $this->eligible;
    }

    /**
     * Is final.
     *
     * @return bool
     */
    public function isFinal(): bool
    {
        return $this->isFinal;
    }

    /**
     * Get period end.
     *
     * @return ?\DateTimeImmutable
     */
    public function getPeriodEnd(): ?\DateTimeImmutable
    {
        return $this->periodEnd;
    }

    /**
     * Get reason.
     *
     * @return ?string
     */
    public function getReason(): ?string
    {
        return $this->reason;
    }

    /**
     * Get exclusion basis.
     *
     * @return ?string
     */
    public function getExclusionBasis(): ?string
    {
        return $this->exclusionBasis;
    }

    /**
     * Get applied rules.
     *
     * @return array
     */
    public function getAppliedRules(): array
    {
        return $this->appliedRules;
    }

    /**
     * With period end.
     *
     * @param \DateTimeImmutable $end
     * @return EligibilityDecisionInterface
     */
    public function withPeriodEnd(\DateTimeImmutable $end): EligibilityDecisionInterface
    {
        return new self(
            eligible: $this->eligible,
            isFinal: $this->isFinal,
            periodEnd: $end,
            reason: $this->reason,
            exclusionBasis: $this->exclusionBasis,
            appliedRules: $this->appliedRules,
        );
    }

    /**
     * With deny.
     *
     * @param string $reason
     * @param string $exclusionBasis
     * @return EligibilityDecisionInterface
     */
    public function withDeny(string $reason, string $exclusionBasis): EligibilityDecisionInterface
    {
        return new self(
            eligible: false,
            isFinal: true,
            periodEnd: $this->periodEnd,
            reason: $reason,
            exclusionBasis: $exclusionBasis,
            appliedRules: $this->appliedRules,
        );
    }

    /**
     * With finalize.
     *
     * @param string $reason
     * @return EligibilityDecisionInterface
     */
    public function withFinalize(string $reason): EligibilityDecisionInterface
    {
        return new self(
            eligible: $this->eligible,
            isFinal: true,
            periodEnd: $this->periodEnd,
            reason: $reason,
            exclusionBasis: $this->exclusionBasis,
            appliedRules: $this->appliedRules,
        );
    }

    /**
     * With applied.
     *
     * @param string $ruleCode
     * @return EligibilityDecisionInterface
     */
    public function withApplied(string $ruleCode): EligibilityDecisionInterface
    {
        $rules = $this->appliedRules;
        $rules[] = $ruleCode;
        return new self(
            eligible: $this->eligible,
            isFinal: $this->isFinal,
            periodEnd: $this->periodEnd,
            reason: $this->reason,
            exclusionBasis: $this->exclusionBasis,
            appliedRules: $rules,
        );
    }

    /**
     * To array.
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            self::ELIGIBLE => $this->eligible,
            self::IS_FINAL => $this->isFinal,
            self::PERIOD_END => $this->periodEnd?->format(\DateTimeInterface::ATOM),
            self::REASON => $this->reason,
            self::EXCLUSION_BASIS => $this->exclusionBasis,
            self::APPLIED_RULES => $this->appliedRules,
        ];
    }
}
