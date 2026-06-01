<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Rule;

use MageMe\EUWithdrawal\Api\Data\EligibilityDecisionInterface;
use MageMe\EUWithdrawal\Api\Data\EligibilityRequestInterface;
use MageMe\EUWithdrawal\Api\RuleInterface;
use MageMe\EUWithdrawal\Exception\InvalidConfigurationException;
use MageMe\EUWithdrawal\Exception\NoDeliveryInfoException;
use MageMe\EUWithdrawal\Model\Config\Source\ContractType;
use MageMe\EUWithdrawal\Api\Period\Art10ExtensionCheckerInterface;
use MageMe\EUWithdrawal\Model\Period\AnchorResolver;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

class PeriodRule extends AbstractRule
{
    public const CODE = 'period_rule';
    public const PRIORITY = 10;
    public const WITHDRAWAL_DAYS = 14;
    public const ART10_EXTENSION_MONTHS = 12;
    public const XML_PERIOD_DAYS = 'mageme_eu_withdrawal/withdrawal_window/period_days';

    /**
     * Constructor.
     *
     * @param AnchorResolver $anchorResolver
     * @param Art10ExtensionCheckerInterface $extensionChecker
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        private readonly AnchorResolver $anchorResolver,
        private readonly Art10ExtensionCheckerInterface $extensionChecker,
        private readonly ScopeConfigInterface $scopeConfig,
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
        if ($request->getContractType() === ContractType::FINANCIAL) {
            return $current->withApplied(self::CODE)->withFinalize('financial_out_of_lite_scope');
        }

        try {
            $anchorDate = $this->anchorResolver->resolve(
                $request->getOrder(),
                $request->getStoreId(),
            );
        } catch (NoDeliveryInfoException) {
            // Per Art. 9(1) CRD, the right to withdraw arises at contract
            // conclusion; the 14-day countdown only starts at delivery for
            // goods (Art. 9(2)(b)). Pre-delivery is a valid open-period
            // state: decision stays eligible, periodEnd stays unset, DB
            // persists period_end_at = NULL.
            return $current->withApplied(self::CODE);
        } catch (InvalidConfigurationException) {
            // Merchant has not yet picked a delivery-confirmation status.
            // Fail open (same as no-delivery-info) so the storefront keeps
            // surfacing the right of withdrawal — it just can't show a
            // concrete deadline until the merchant configures the trigger.
            return $current->withApplied(self::CODE);
        }

        $days = max(
            self::WITHDRAWAL_DAYS,
            (int) $this->scopeConfig->getValue(
                self::XML_PERIOD_DAYS,
                ScopeInterface::SCOPE_STORE,
                $request->getStoreId(),
            ),
        );
        $periodEnd = $anchorDate->modify('+' . $days . ' days');

        if ($this->extensionChecker->shouldExtend($request->getOrder())) {
            $periodEnd = $periodEnd->modify('+' . self::ART10_EXTENSION_MONTHS . ' months');
        }
        $periodEnd = $periodEnd->setTime(23, 59, 59);

        $decision = $current->withPeriodEnd($periodEnd)->withApplied(self::CODE);

        if ($request->getSubmittedAt() > $periodEnd) {
            return $decision->withDeny('period_expired', 'Art. 9(2)');
        }

        return $decision;
    }
}
