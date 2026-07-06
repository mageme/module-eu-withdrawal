<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Queue;

class WaiverConfirmationState
{
    public const STATUS_PENDING   = 'pending';
    public const STATUS_SENDING   = 'sending';
    public const STATUS_SENT      = 'sent';
    public const STATUS_RETRY     = 'failed_retry';
    public const STATUS_PERMANENT = 'failed_permanent';

    /**
     * Constructor.
     *
     * @param int $stateId
     * @param int $orderId
     * @param string $status
     * @param int $attempts
     * @param ?string $nextSendAt
     * @param ?string $lastError
     */
    public function __construct(
        public readonly int $stateId,
        public readonly int $orderId,
        public readonly string $status,
        public readonly int $attempts,
        public readonly ?string $nextSendAt,
        public readonly ?string $lastError,
    ) {
    }

    /**
     * From row.
     *
     * @param array $row
     * @return self
     */
    public static function fromRow(array $row): self
    {
        return new self(
            stateId: (int) ($row['state_id'] ?? 0),
            orderId: (int) ($row['order_id'] ?? 0),
            status: (string) ($row['status'] ?? self::STATUS_PENDING),
            attempts: (int) ($row['attempts'] ?? 0),
            nextSendAt: $row['next_send_at'] ?? null,
            lastError: $row['last_error'] ?? null,
        );
    }
}
