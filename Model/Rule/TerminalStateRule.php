<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Rule;

use MageMe\EUWithdrawal\Api\Data\EligibilityDecisionInterface;
use MageMe\EUWithdrawal\Api\Data\EligibilityRequestInterface;
use Magento\Sales\Model\Order;

class TerminalStateRule extends AbstractRule
{
    public const CODE = 'terminal_state_rule';
    public const PRIORITY = 2;
    public const REASON_ORDER_CANCELED = 'order_canceled';
    public const BASIS_ORDER_CANCELED = 'order_terminal_state';

    public function getCode(): string
    {
        return self::CODE;
    }

    public function getPriority(): int
    {
        return self::PRIORITY;
    }

    public function getScope(): string
    {
        return self::SCOPE_ORDER;
    }

    public function evaluate(
        EligibilityRequestInterface $request,
        EligibilityDecisionInterface $current,
    ): EligibilityDecisionInterface {
        if ((string) $request->getOrder()->getState() === Order::STATE_CANCELED) {
            return $current->withApplied(self::CODE)
                ->withDeny(self::REASON_ORDER_CANCELED, self::BASIS_ORDER_CANCELED);
        }
        return $current;
    }
}
