<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Frontend;

use MageMe\EUWithdrawal\Api\Data\EligibilityDecisionInterface;
use MageMe\EUWithdrawal\Api\Data\RequestInterface;
use MageMe\EUWithdrawal\Api\RequestRepositoryInterface;
use MageMe\EUWithdrawal\Model\EligibilityEngine;
use MageMe\EUWithdrawal\Model\EligibilityRequestBuilder;
use MageMe\EUWithdrawal\Model\Frontend\Dto\PerOrderEligibility;
use MageMe\EUWithdrawal\Model\Item\OrderPartialStateCalculator;
use MageMe\EUWithdrawal\Model\Item\RemainingItemState;
use MageMe\EUWithdrawal\Model\Rule\GeoScopeRule;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;

class OrderEligibilityResolver
{
    /** @var array<int, PerOrderEligibility> */
    private array $perRequestCache = [];

    /**
     * Constructor.
     *
     * @param EligibilityEngine $engine
     * @param EligibilityRequestBuilder $requestBuilder
     * @param RequestRepositoryInterface $requestRepository
     * @param OrderRepositoryInterface $orderRepository
     * @param SearchCriteriaBuilder $searchBuilder
     * @param OrderPartialStateCalculator $partialCalculator
     */
    public function __construct(
        private readonly EligibilityEngine $engine,
        private readonly EligibilityRequestBuilder $requestBuilder,
        private readonly RequestRepositoryInterface $requestRepository,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly SearchCriteriaBuilder $searchBuilder,
        private readonly OrderPartialStateCalculator $partialCalculator,
    ) {
    }

    /**
     * Resolve.
     *
     * @param int $orderId
     * @return PerOrderEligibility
     */
    public function resolve(int $orderId): PerOrderEligibility
    {
        if (isset($this->perRequestCache[$orderId])) {
            return $this->perRequestCache[$orderId];
        }
        $order = $this->orderRepository->get($orderId);
        return $this->perRequestCache[$orderId] = $this->compute($order);
    }

    /**
     * Is eligible for order.
     *
     * @param OrderInterface $order
     * @return bool
     */
    public function isEligibleForOrder(OrderInterface $order): bool
    {
        return $this->resolve((int) $order->getEntityId())->eligible;
    }

    /**
     * Compute.
     *
     * @param OrderInterface $order
     * @return PerOrderEligibility
     */
    private function compute(OrderInterface $order): PerOrderEligibility
    {
        $existing = $this->findActiveRequest((int) $order->getEntityId());
        $existingId            = $existing !== null ? (int) $existing->getRequestId() : null;
        $existingStatus        = $existing !== null ? (string) $existing->getStatus() : null;
        $existingIncrementId   = $existing?->getIncrementId();

        // Run the full eligibility chain (order + item scopes) regardless of
        // whether an active request exists. This lets the storefront section
        // surface the deadline and remaining-capacity state for the
        // partial-withdrawal flow even mid-flight (decision A2 in the
        // 2026-04-28 storefront-withdrawals-section spec).
        $result   = $this->engine->evaluate($this->requestBuilder->build($order));
        $decision = $result->getOrderDecision();

        if (!$decision->isEligible() && $decision->isFinal()) {
            return new PerOrderEligibility(
                eligible: false,
                deadlineIsoUtc: null,
                ineligibleReason: $this->mapIneligibleReason($decision),
                existingRequestId: $existingId,
                existingRequestStatus: $existingStatus,
                existingRequestIncrementId: $existingIncrementId,
                hasRemainingCapacity: false,
            );
        }

        $deadlineIso = $this->formatIsoUtc($decision->getPeriodEnd());
        $hasCapacity = $this->hasRemainingCapacity($order, $result);

        $reason = null;
        if (!$hasCapacity) {
            $mapped = $this->mapIneligibleReason($decision);
            // Capacity consumed by an existing pending/approved request
            // surfaces here as no-remaining-qty even though the decision is eligible.
            // That's "already requested", not "Art. 16 excluded / no eligible items".
            if ($existingId !== null && $mapped === PerOrderEligibility::REASON_NO_ELIGIBLE_ITEMS) {
                $reason = PerOrderEligibility::REASON_ALREADY_IN_PROGRESS;
            } else {
                $reason = $mapped;
            }
        }

        return new PerOrderEligibility(
            eligible: $hasCapacity,
            deadlineIsoUtc: $deadlineIso,
            ineligibleReason: $reason,
            existingRequestId: $existingId,
            existingRequestStatus: $existingStatus,
            existingRequestIncrementId: $existingIncrementId,
            hasRemainingCapacity: $hasCapacity,
        );
    }

    /**
     * Has remaining capacity.
     *
     * @param OrderInterface $order
     * @param \MageMe\EUWithdrawal\Api\Data\EligibilityResultInterface $result
     * @return bool
     */
    private function hasRemainingCapacity(OrderInterface $order, \MageMe\EUWithdrawal\Api\Data\EligibilityResultInterface $result): bool
    {
        try {
            $states = $this->partialCalculator->calculate($order, $result, null);
        } catch (\Throwable) {
            return false;
        }
        foreach ($states as $state) {
            if ($state->eligibility === RemainingItemState::ELIGIBILITY_ELIGIBLE
                && $state->remainingQty > 0
            ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Find active request.
     *
     * @param int $orderId
     * @return ?RequestInterface
     */
    private function findActiveRequest(int $orderId): ?RequestInterface
    {
        $criteria = $this->searchBuilder
            ->addFilter(RequestInterface::ORDER_ID, $orderId)
            ->addFilter(RequestInterface::STATUS, [
                RequestInterface::STATUS_PENDING,
                RequestInterface::STATUS_APPROVED,
            ], 'in')
            ->create();
        $rows = $this->requestRepository->getList($criteria)->getItems();
        if ($rows === []) {
            return null;
        }
        $first = reset($rows);
        return $first instanceof RequestInterface ? $first : null;
    }

    /**
     * Map ineligible reason.
     *
     * @param EligibilityDecisionInterface $decision
     * @return string
     */
    private function mapIneligibleReason(EligibilityDecisionInterface $decision): string
    {
        // Period-related reasons (period_expired / not_shipped_yet) set exclusionBasis='Art. 9(2)',
        // so they must be matched before the generic exclusionBasis check;
        // Model/Rule/Preset/*Preset.php emit 'art_16_*' reasons with 'Art. 16(*)' basis strings.
        // Geo-scope denials also carry a basis ('merchant_geo_scope') and must match on reason first.
        if ($decision->getReason() === GeoScopeRule::REASON) {
            return PerOrderEligibility::REASON_OUT_OF_REGION;
        }
        if ($decision->getReason() === 'period_expired') {
            return PerOrderEligibility::REASON_PERIOD_EXPIRED;
        }
        if ($decision->getReason() === 'not_shipped_yet') {
            return PerOrderEligibility::REASON_NOT_SHIPPED_YET;
        }
        if ($decision->getExclusionBasis() !== null) {
            return PerOrderEligibility::REASON_ART_16_EXCLUDED;
        }
        return PerOrderEligibility::REASON_NO_ELIGIBLE_ITEMS;
    }

    /**
     * Format iso utc.
     *
     * @param ?\DateTimeImmutable $dt
     * @return ?string
     */
    private function formatIsoUtc(?\DateTimeImmutable $dt): ?string
    {
        if ($dt === null) {
            return null;
        }
        return $dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
