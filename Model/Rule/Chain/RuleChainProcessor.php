<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Rule\Chain;

use MageMe\EUWithdrawal\Api\Data\EligibilityDecisionInterface;
use MageMe\EUWithdrawal\Api\Data\EligibilityRequestInterface;
use MageMe\EUWithdrawal\Api\RuleInterface;

class RuleChainProcessor
{
    /** @var RuleInterface[] */
    private readonly array $rules;

    /**
     * @param RuleInterface[] $rules
     */
    public function __construct(array $rules)
    {
        usort($rules, static fn (RuleInterface $a, RuleInterface $b): int => $a->getPriority() <=> $b->getPriority());
        $this->rules = $rules;
    }

    /**
     * Process.
     *
     * @param EligibilityRequestInterface $request
     * @param EligibilityDecisionInterface $decision
     * @param string $scope
     * @return EligibilityDecisionInterface
     */
    public function process(
        EligibilityRequestInterface $request,
        EligibilityDecisionInterface $decision,
        string $scope,
    ): EligibilityDecisionInterface {
        foreach ($this->rules as $rule) {
            if ($rule->getScope() !== $scope) {
                continue;
            }
            if (!$rule->applies($request)) {
                continue;
            }
            $decision = $rule->evaluate($request, $decision);
            if ($decision->isFinal()) {
                return $decision;
            }
        }
        return $decision;
    }
}
