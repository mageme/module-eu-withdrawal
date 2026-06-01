<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Rule\Preset;

use MageMe\EUWithdrawal\Api\Data\EligibilityDecisionInterface;
use MageMe\EUWithdrawal\Api\Data\EligibilityRequestInterface;

class PerishablePreset extends AbstractPreset
{
    public const CODE = 'preset_perishable';
    public const CONFIG_PATH = 'mageme_eu_withdrawal/eligibility/preset_perishable';
    public const ATTRIBUTE = 'is_perishable';

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
     * Get config path.
     *
     * @return string
     */
    protected function getConfigPath(): string
    {
        return self::CONFIG_PATH;
    }

    /**
     * Do evaluate.
     *
     * @param EligibilityRequestInterface $request
     * @param EligibilityDecisionInterface $current
     * @return EligibilityDecisionInterface
     */
    protected function doEvaluate(
        EligibilityRequestInterface $request,
        EligibilityDecisionInterface $current,
    ): EligibilityDecisionInterface {
        $decision = $current->withApplied(self::CODE);
        $product = $request->getCurrentProduct();
        if ($product === null) {
            return $decision;
        }
        $attr = $product->getCustomAttribute(self::ATTRIBUTE);
        if ($attr !== null && (int) $attr->getValue() === 1) {
            return $decision->withDeny('art_16_d_perishable', 'Art. 16(d)');
        }
        return $decision;
    }
}
