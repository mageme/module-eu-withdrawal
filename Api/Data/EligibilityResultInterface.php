<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Api\Data;

interface EligibilityResultInterface
{
    /**
     * Get order decision.
     *
     * @return EligibilityDecisionInterface
     */
    public function getOrderDecision(): EligibilityDecisionInterface;

    /**
     * @return array<int, EligibilityDecisionInterface> keyed by order_item_id
     */
    public function getItemDecisions(): array;
}
