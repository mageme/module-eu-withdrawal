<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model;

use MageMe\EUWithdrawal\Api\Data\EligibilityDecisionInterface;
use MageMe\EUWithdrawal\Api\Data\EligibilityRequestInterface;
use MageMe\EUWithdrawal\Api\Data\EligibilityResultInterface;
use MageMe\EUWithdrawal\Api\EligibilityEngineInterface;
use MageMe\EUWithdrawal\Api\RuleInterface;
use MageMe\EUWithdrawal\Model\Item\ReturnableItemsResolver;
use MageMe\EUWithdrawal\Model\Rule\Chain\RuleChainProcessor;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;

class EligibilityEngine implements EligibilityEngineInterface
{
    /**
     * Constructor.
     *
     * @param RuleChainProcessor $chain
     * @param ProductRepositoryInterface $productRepository
     * @param ReturnableItemsResolver $returnableItems
     */
    public function __construct(
        private readonly RuleChainProcessor $chain,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly ReturnableItemsResolver $returnableItems,
    ) {
    }

    /**
     * Evaluate.
     *
     * @param EligibilityRequestInterface $request
     * @return EligibilityResultInterface
     */
    public function evaluate(EligibilityRequestInterface $request): EligibilityResultInterface
    {
        $orderDecision = $this->chain->process(
            $request,
            EligibilityDecision::initial(),
            RuleInterface::SCOPE_ORDER,
        );

        $itemDecisions = [];
        if (!$orderDecision->isFinal()) {
            foreach ($this->returnableItems->resolve($request->getOrder()) as $item) {
                try {
                    $product = $this->productRepository->getById((int) $item->getProductId());
                } catch (NoSuchEntityException) {
                    // Product was deleted after purchase — keep the order renderable;
                    // this item gets no item-scope decision (callers fall back to the
                    // order-scope decision).
                    continue;
                }
                $itemRequest = $request->withItem($item, $product);
                $itemDecisions[(int) $item->getItemId()] = $this->chain->process(
                    $itemRequest,
                    $orderDecision,
                    RuleInterface::SCOPE_ITEM,
                );
            }
        }

        return new EligibilityResult($orderDecision, $itemDecisions);
    }

    /**
     * @param array<int, EligibilityDecisionInterface> $itemDecisions
     */
    public static function summarizeDecision(
        EligibilityDecisionInterface $orderDecision,
        array $itemDecisions,
    ): string {
        if ($orderDecision->isFinal()) {
            return $orderDecision->isEligible() ? 'eligible' : 'ineligible';
        }
        if ($itemDecisions === []) {
            return $orderDecision->isEligible() ? 'eligible' : 'ineligible';
        }
        $anyEligible = false;
        $anyIneligible = false;
        foreach ($itemDecisions as $decision) {
            if ($decision->isEligible()) {
                $anyEligible = true;
            } else {
                $anyIneligible = true;
            }
        }
        if ($anyEligible && $anyIneligible) {
            return 'mixed';
        }
        return $anyEligible ? 'eligible' : 'ineligible';
    }

    /**
     * @param array<int, EligibilityDecisionInterface> $itemDecisions
     * @return string[]
     */
    public static function collectFiredRules(
        EligibilityDecisionInterface $orderDecision,
        array $itemDecisions,
    ): array {
        $rules = $orderDecision->getAppliedRules();
        foreach ($itemDecisions as $decision) {
            foreach ($decision->getAppliedRules() as $rule) {
                $rules[] = $rule;
            }
        }
        return array_values(array_unique($rules));
    }
}
