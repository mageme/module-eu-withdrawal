<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Rule\Preset;

use MageMe\EUWithdrawal\Api\Data\EligibilityDecisionInterface;
use MageMe\EUWithdrawal\Api\Data\EligibilityRequestInterface;
use MageMe\EUWithdrawal\Model\Item\PersonalisedOptionDetector;
use MageMe\EUWithdrawal\Model\Item\ProductFlagReader;
use Magento\Framework\App\Config\ScopeConfigInterface;

class CustomMadePreset extends AbstractProductFlagPreset
{
    public const CODE = 'preset_custom';
    public const CONFIG_PATH = 'mageme_eu_withdrawal/eligibility/preset_custom';
    public const ATTRIBUTE = 'is_custom_made';

    /**
     * Constructor.
     *
     * @param ScopeConfigInterface $scopeConfig
     * @param ProductFlagReader $flagReader
     * @param PersonalisedOptionDetector $optionDetector
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        ProductFlagReader $flagReader,
        private readonly PersonalisedOptionDetector $optionDetector,
    ) {
        parent::__construct($scopeConfig, $flagReader);
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
        if ($this->isFlagSet($request, self::ATTRIBUTE)) {
            return $decision->withDeny('art_16_c_custom_made', 'Art. 16(c)');
        }
        if ($this->optionDetector->isPersonalised($request->getCurrentItem(), $request->getCurrentProduct())) {
            return $decision->withDeny('art_16_c_custom_made', 'Art. 16(c)');
        }
        return $decision;
    }
}
