<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Cron;

use MageMe\EUWithdrawal\Model\Queue\ReceiptSendPublisher;
use Magento\Framework\App\ResourceConnection;

class ReceiptRetryRunner
{
    public const TABLE_REQUEST = 'mm_eu_withdrawal_request';
    private const LIMIT = 50;
    private const LEASE_SECONDS = 300;

    /**
     * Constructor.
     *
     * @param ResourceConnection $resource
     * @param ReceiptSendPublisher $publisher
     */
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly ReceiptSendPublisher $publisher,
    ) {
    }

    /**
     * Execute.
     *
     * @return void
     */
    public function execute(): void
    {
        $conn = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE_REQUEST);
        $now = gmdate('Y-m-d H:i:s');
        $leaseUntil = gmdate('Y-m-d H:i:s', time() + self::LEASE_SECONDS);

        $conn->beginTransaction();
        try {
            $ids = array_map('intval', (array) $conn->fetchCol(
                $conn->select()
                    ->from($table, ['request_id'])
                    // `sending` rows past their lease belong to a worker that died
                    // mid-send; reclaim them alongside due `failed_retry` rows.
                    ->where('receipt_status IN (?)', ['failed_retry', 'sending'])
                    ->where('receipt_next_send_at <= ?', $now)
                    ->limit(self::LIMIT)
                    ->forUpdate(true)
            ));
            if ($ids !== []) {
                // Reset any reclaimed `sending` row to `failed_retry` so the
                // consumer can claim it again, and push next_send_at forward
                // (lease) so the every-minute cron can't re-select and double-send
                // before the consumer processes it.
                $conn->update(
                    $table,
                    ['receipt_status' => 'failed_retry', 'receipt_next_send_at' => $leaseUntil],
                    ['request_id IN (?)' => $ids],
                );
            }
            $conn->commit();
        } catch (\Throwable $t) {
            $conn->rollBack();
            throw $t;
        }
        foreach ($ids as $id) {
            $this->publisher->publish((int) $id);
        }
    }
}
