<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Frontend\Dto;

class PerOrderEligibility
{
    public const REASON_PERIOD_EXPIRED      = 'period_expired';
    public const REASON_ART_16_EXCLUDED     = 'art_16_excluded';
    public const REASON_ALREADY_IN_PROGRESS = 'already_in_progress';
    public const REASON_NO_ELIGIBLE_ITEMS   = 'no_eligible_items';
    public const REASON_NOT_SHIPPED_YET     = 'not_shipped_yet';
    public const REASON_OUT_OF_REGION       = 'out_of_region';
    public const REASON_OUT_OF_GROUP        = 'out_of_group';
    public const REASON_DELIVERY_DATE_UNRECORDED = 'delivery_date_unrecorded';
    public const REASON_STATUS_EXCLUDED = 'status_excluded';
    public const REASON_ORDER_CANCELED = 'order_canceled';

    /**
     * Constructor.
     *
     * @param bool $eligible
     * @param ?string $deadlineIsoUtc
     * @param ?string $ineligibleReason
     * @param ?int $existingRequestId
     * @param ?string $existingRequestStatus
     * @param ?string $existingRequestIncrementId
     * @param bool $hasRemainingCapacity
     */
    public function __construct(
        public readonly bool $eligible,
        public readonly ?string $deadlineIsoUtc,
        public readonly ?string $ineligibleReason,
        public readonly ?int $existingRequestId,
        public readonly ?string $existingRequestStatus,
        public readonly ?string $existingRequestIncrementId = null,
        // True when at least one order item still has uncommitted qty (i.e.
        // a partial withdrawal is still possible). Always false when the
        // order itself fails the period / Art. 16 / shipping checks. The
        // storefront `WithdrawalsSection` block uses this to decide whether
        // to render the "Start withdrawal" button when an active request
        // already exists for the order.
        public readonly bool $hasRemainingCapacity = false,
    ) {
    }
}
