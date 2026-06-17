<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Rule;

use MageMe\EUWithdrawal\Api\Data\EligibilityDecisionInterface;
use MageMe\EUWithdrawal\Api\Data\EligibilityRequestInterface;
use MageMe\EUWithdrawal\Model\Geo\CountryScope;

class GeoScopeRule extends AbstractRule
{
    public const CODE = 'geo_scope_rule';
    public const PRIORITY = 5;
    public const REASON = 'geo_out_of_scope';
    public const BASIS = 'merchant_geo_scope';

    /**
     * Constructor.
     *
     * @param CountryScope $countryScope
     */
    public function __construct(
        private readonly CountryScope $countryScope,
    ) {
    }

    /**
     * Get code.
     *
     * @return string
     */
    public function getCode(): string
    {
        return self::CODE;
    }

    /**
     * Get priority.
     *
     * @return int
     */
    public function getPriority(): int
    {
        return self::PRIORITY;
    }

    /**
     * Get scope.
     *
     * @return string
     */
    public function getScope(): string
    {
        return self::SCOPE_ORDER;
    }

    /**
     * Evaluate.
     *
     * @param EligibilityRequestInterface $request
     * @param EligibilityDecisionInterface $current
     * @return EligibilityDecisionInterface
     */
    public function evaluate(
        EligibilityRequestInterface $request,
        EligibilityDecisionInterface $current,
    ): EligibilityDecisionInterface {
        if ($this->countryScope->orderInScope($request->getOrder())) {
            return $current;
        }
        return $current->withApplied(self::CODE)->withDeny(self::REASON, self::BASIS);
    }
}
