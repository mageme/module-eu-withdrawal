<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Rule;

use MageMe\EUWithdrawal\Api\Data\EligibilityRequestInterface;
use MageMe\EUWithdrawal\Api\RuleInterface;

abstract class AbstractRule implements RuleInterface
{
    /**
     * Get scope.
     *
     * @return string
     */
    public function getScope(): string
    {
        return self::SCOPE_ITEM;
    }

    /**
     * Applies.
     *
     * @param EligibilityRequestInterface $request
     * @return bool
     */
    public function applies(EligibilityRequestInterface $request): bool
    {
        return true;
    }
}
