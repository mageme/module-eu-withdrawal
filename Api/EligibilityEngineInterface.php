<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Api;

use MageMe\EUWithdrawal\Api\Data\EligibilityRequestInterface;
use MageMe\EUWithdrawal\Api\Data\EligibilityResultInterface;

interface EligibilityEngineInterface
{
    /**
     * Evaluate.
     *
     * @param EligibilityRequestInterface $request
     * @return EligibilityResultInterface
     */
    public function evaluate(EligibilityRequestInterface $request): EligibilityResultInterface;
}
