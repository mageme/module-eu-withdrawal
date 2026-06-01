<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Queue;

use Magento\Framework\App\ResourceConnection;

class WaiverConfirmationStateRepository
{
    public const TABLE = 'mm_eu_withdrawal_waiver_confirmation_state';

    /**
     * Constructor.
     *
     * @param ResourceConnection $resource
     */
    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    /**
     * Get by order id.
     *
     * @param int $orderId
     * @return ?WaiverConfirmationState
     */
    public function getByOrderId(int $orderId): ?WaiverConfirmationState
    {
        $conn = $this->resource->getConnection();
        $select = $conn->select()
            ->from($this->resource->getTableName(self::TABLE))
            ->where('order_id = ?', $orderId)
            ->limit(1);
        $row = $conn->fetchRow($select);
        return $row ? WaiverConfirmationState::fromRow($row) : null;
    }

    /**
     * Mark sent.
     *
     * @param int $orderId
     * @return void
     */
    public function markSent(int $orderId): void
    {
        $this->upsert($orderId, [
            'status' => WaiverConfirmationState::STATUS_SENT,
            'next_send_at' => null,
            'last_error' => null,
        ]);
    }

    /**
     * Mark retry.
     *
     * @param int $orderId
     * @param int $attempts
     * @param string $nextSendAt
     * @param string $lastError
     * @return void
     */
    public function markRetry(int $orderId, int $attempts, string $nextSendAt, string $lastError): void
    {
        $this->upsert($orderId, [
            'status' => WaiverConfirmationState::STATUS_RETRY,
            'attempts' => $attempts,
            'next_send_at' => $nextSendAt,
            'last_error' => substr($lastError, 0, 500),
        ]);
    }

    /**
     * Mark permanent.
     *
     * @param int $orderId
     * @param string $lastError
     * @return void
     */
    public function markPermanent(int $orderId, string $lastError): void
    {
        $this->upsert($orderId, [
            'status' => WaiverConfirmationState::STATUS_PERMANENT,
            'next_send_at' => null,
            'last_error' => substr($lastError, 0, 500),
        ]);
    }

    /**
     * Mark pending.
     *
     * @param int $orderId
     * @return void
     */
    public function markPending(int $orderId): void
    {
        $this->upsert($orderId, [
            'status' => WaiverConfirmationState::STATUS_PENDING,
            'next_send_at' => null,
            'last_error' => null,
        ]);
    }

    /**
     * Atomically claim due retry rows for republishing. Selects `failed_retry`
     * rows whose `next_send_at` has passed (locked FOR UPDATE), pushes their
     * `next_send_at` forward by a short lease, and returns their order ids. The
     * lease prevents the every-minute retry cron from re-selecting (and thus
     * double-publishing) the same rows before the consumer processes them; the
     * consumer resets `next_send_at` on success/failure.
     *
     * @param int $limit
     * @param int $leaseSeconds
     * @return int[]
     */
    public function claimDueForRetry(int $limit, int $leaseSeconds): array
    {
        $conn = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE);
        $now = gmdate('Y-m-d H:i:s');
        $leaseUntil = gmdate('Y-m-d H:i:s', time() + $leaseSeconds);

        $conn->beginTransaction();
        try {
            $orderIds = array_map('intval', (array) $conn->fetchCol(
                $conn->select()
                    ->from($table, ['order_id'])
                    ->where('status = ?', WaiverConfirmationState::STATUS_RETRY)
                    ->where('next_send_at IS NOT NULL')
                    ->where('next_send_at <= ?', $now)
                    ->limit($limit)
                    ->forUpdate(true)
            ));
            if ($orderIds !== []) {
                $conn->update($table, ['next_send_at' => $leaseUntil], ['order_id IN (?)' => $orderIds]);
            }
            $conn->commit();
        } catch (\Throwable $t) {
            $conn->rollBack();
            throw $t;
        }
        return $orderIds;
    }

    /** @param array<string,mixed> $data */
    private function upsert(int $orderId, array $data): void
    {
        $conn = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE);
        $existingId = $conn->fetchOne(
            $conn->select()->from($table, ['state_id'])->where('order_id = ?', $orderId)->limit(1)
        );
        if ($existingId !== false) {
            $conn->update($table, $data, ['state_id = ?' => (int) $existingId]);
            return;
        }
        $conn->insert($table, array_merge(['order_id' => $orderId, 'attempts' => 0], $data));
    }
}
