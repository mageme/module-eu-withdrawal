<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model;

use MageMe\EUWithdrawal\Api\Data\EligibilityDecisionInterface;
use MageMe\EUWithdrawal\Api\Data\EligibilityResultInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Sales\Api\Data\OrderItemInterface;

class EligibilitySnapshot
{
    public const TABLE_ITEM = 'mm_eu_withdrawal_item';

    private const BASIS_TO_ELIGIBILITY = [
        'Art. 16(c)' => 'excluded_art16c',
        'Art. 16(d)' => 'excluded_art16d',
        'Art. 16(e)' => 'excluded_art16e',
        'Art. 16(i)' => 'excluded_art16i',
        'Art. 16(m)' => 'excluded_art16m',
        'Art. 9(2)'  => 'excluded_art9_2',
    ];

    /**
     * Constructor.
     *
     * @param ResourceConnection $resource
     */
    public function __construct(
        private readonly ResourceConnection $resource,
    ) {
    }

    /**
     * Persist the eligibility verdict per item onto the already-created
     * mm_eu_withdrawal_item rows. Does NOT touch `content_hash` on the
     * request row — that column is owned by the receipt-integrity flow
     * (RequestCreator writes it, ReceiptSendConsumer verifies it).
     *
     * @param OrderItemInterface[] $orderItems
     */
    public function persist(
        int $requestId,
        int $orderId,
        EligibilityResultInterface $result,
        array $orderItems,
        \DateTimeImmutable $submittedAt,
    ): void {
        $connection = $this->resource->getConnection();

        foreach ($orderItems as $item) {
            $itemId = (int) $item->getItemId();
            $itemDecision = $result->getItemDecisions()[$itemId] ?? $result->getOrderDecision();

            $connection->update(
                $this->resource->getTableName(self::TABLE_ITEM),
                [
                    'eligibility' => self::eligibilityFor($itemDecision),
                    'exclusion_basis' => $itemDecision->getExclusionBasis(),
                ],
                ['request_id = ?' => $requestId, 'order_item_id = ?' => $itemId],
            );
        }
    }

    /**
     * Canonical mapping of a decision to the `eligibility` column value
     * (eligible | excluded_art16* | excluded_other). Single source of truth,
     * shared with the request-creation item writer so the column is consistent
     * across the row lifecycle.
     *
     * @param ?EligibilityDecisionInterface $decision
     * @return string
     */
    public static function eligibilityFor(?EligibilityDecisionInterface $decision): string
    {
        if ($decision === null || $decision->isEligible()) {
            return 'eligible';
        }
        return self::BASIS_TO_ELIGIBILITY[$decision->getExclusionBasis()] ?? 'excluded_other';
    }
}
