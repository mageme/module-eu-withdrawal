<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Waiver;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Select;

class WaiverEventWriter
{
    public const TABLE = 'mm_eu_withdrawal_waiver_event';

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
     * Insert the event unless an idempotent match already exists.
     *
     * @param array<string,mixed> $event
     * @return bool true if inserted, false if idempotent no-op
     */
    public function write(array $event): bool
    {
        $conn = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE);
        $conn->beginTransaction();
        try {
            $select = $conn->select()->from($table, ['event_id']);
            $this->applyIdentityKey($select, $event);
            $select->forUpdate(true)->limit(1);
            $existing = $conn->fetchOne($select);
            if ($existing !== false) {
                $conn->commit();
                return false;
            }
            $conn->insert($table, $this->buildRow($event));
            $conn->commit();
            return true;
        } catch (\Throwable $t) {
            $conn->rollBack();
            throw $t;
        }
    }

    /**
     * Insert the event, or update the existing matching row in place.
     *
     * @param array<string,mixed> $event
     * @return bool true if inserted, false if an existing row was updated
     */
    public function upsert(array $event): bool
    {
        $conn = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE);
        $conn->beginTransaction();
        try {
            $select = $conn->select()->from($table, ['event_id']);
            $this->applyIdentityKey($select, $event);
            $select->forUpdate(true)->limit(1);
            $existing = $conn->fetchOne($select);
            if ($existing !== false) {
                $conn->update($table, $this->buildRow($event), ['event_id = ?' => (int) $existing]);
                $conn->commit();
                return false;
            }
            $conn->insert($table, $this->buildRow($event));
            $conn->commit();
            return true;
        } catch (\Throwable $t) {
            $conn->rollBack();
            throw $t;
        }
    }

    /**
     * @param Select $select
     * @param array<string,mixed> $event
     * @return void
     */
    private function applyIdentityKey(Select $select, array $event): void
    {
        $select->where('event_type = ?', (string) $event['event_type']);
        if ((int) ($event['order_id'] ?? 0) === 0 && !empty($event['quote_item_id'])) {
            $select->where('quote_item_id = ?', (int) $event['quote_item_id'])
                   ->where('order_id = ?', 0);
        } elseif (!empty($event['order_item_id'])) {
            $select->where('order_item_id = ?', (int) $event['order_item_id']);
        } else {
            throw new \InvalidArgumentException('Either quote_item_id or order_item_id required');
        }
    }

    /**
     * @param array<string,mixed> $event
     * @return array<string,mixed>
     */
    private function buildRow(array $event): array
    {
        return [
            'order_id' => (int) ($event['order_id'] ?? 0),
            'quote_item_id' => $event['quote_item_id'] ?? null,
            'order_item_id' => $event['order_item_id'] ?? null,
            'event_type' => (string) $event['event_type'],
            'consent_value' => (int) ($event['consent_value'] ?? 0),
            'waiver_text_snapshot' => $event['waiver_text_snapshot'] ?? null,
            'waiver_text_hash' => $event['waiver_text_hash'] ?? null,
            'locale' => $event['locale'] ?? null,
            'jurisdiction' => $event['jurisdiction'] ?? null,
            'product_sku' => $event['product_sku'] ?? null,
            'customer_email' => $event['customer_email'] ?? null,
            'performance_trigger' => $event['performance_trigger'] ?? null,
            'confirmation_sent_at' => $event['confirmation_sent_at'] ?? null,
            'ip' => $event['ip'] ?? null,
            'user_agent' => isset($event['user_agent'])
                ? mb_substr((string) $event['user_agent'], 0, 512)
                : null,
        ];
    }
}
