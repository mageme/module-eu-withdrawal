<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Rule\Preset;

use MageMe\EUWithdrawal\Api\Data\EligibilityDecisionInterface;
use MageMe\EUWithdrawal\Api\Data\EligibilityRequestInterface;
use MageMe\EUWithdrawal\Api\RuleInterface;
use MageMe\EUWithdrawal\Model\Rule\AbstractRule;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

abstract class AbstractPreset extends AbstractRule
{
    public const PRIORITY = 50;

    /**
     * Constructor.
     *
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        protected readonly ScopeConfigInterface $scopeConfig,
    ) {
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
        return RuleInterface::SCOPE_ITEM;
    }

    /**
     * Applies.
     *
     * @param EligibilityRequestInterface $request
     * @return bool
     */
    public function applies(EligibilityRequestInterface $request): bool
    {
        $enabled = $this->scopeConfig->getValue(
            $this->getConfigPath(),
            ScopeInterface::SCOPE_STORE,
            $request->getStoreId(),
        );
        return (string) $enabled !== '0';
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
        return $this->doEvaluate($request, $current);
    }

    /**
     * Get config path.
     *
     * @return string
     */
    abstract protected function getConfigPath(): string;

    /**
     * Do evaluate.
     *
     * @param EligibilityRequestInterface $request
     * @param EligibilityDecisionInterface $current
     * @return EligibilityDecisionInterface
     */
    abstract protected function doEvaluate(
        EligibilityRequestInterface $request,
        EligibilityDecisionInterface $current,
    ): EligibilityDecisionInterface;
}
