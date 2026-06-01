<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model;

use MageMe\EUWithdrawal\Api\Data\EligibilityDecisionInterface;
use MageMe\EUWithdrawal\Api\Data\EligibilityResultInterface;

class EligibilityResult implements EligibilityResultInterface
{
    /**
     * @param array<int, EligibilityDecisionInterface> $itemDecisions
     */
    public function __construct(
        private readonly EligibilityDecisionInterface $orderDecision,
        private readonly array $itemDecisions,
    ) {
    }

    /**
     * Get order decision.
     *
     * @return EligibilityDecisionInterface
     */
    public function getOrderDecision(): EligibilityDecisionInterface
    {
        return $this->orderDecision;
    }

    /**
     * Get item decisions.
     *
     * @return array
     */
    public function getItemDecisions(): array
    {
        return $this->itemDecisions;
    }
}
