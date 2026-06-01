<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Waiver;

use MageMe\EUWithdrawal\Model\ResourceModel\WaiverEvent\CollectionFactory as WaiverEventCollectionFactory;

class WaiverEventReader
{
    public const TABLE = 'mm_eu_withdrawal_waiver_event';

    public const EVT_CONSENT  = 'digital_consent_express';
    public const EVT_LOSS     = 'digital_loss_acknowledged';
    public const EVT_AFFIRM   = 'digital_consent_ui_affirmed';
    public const EVT_CONFIRM  = 'confirmation_sent';
    public const EVT_PERF     = 'performance_started';

    /**
     * Constructor.
     *
     * @param WaiverEventCollectionFactory $collectionFactory
     */
    public function __construct(private readonly WaiverEventCollectionFactory $collectionFactory)
    {
    }

    /**
     * Has both consents.
     *
     * @param int $orderId
     * @param int $orderItemId
     * @return bool
     */
    public function hasBothConsents(int $orderId, int $orderItemId): bool
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('order_id', $orderId)
            ->addFieldToFilter('order_item_id', $orderItemId)
            ->addFieldToFilter('consent_value', 1)
            ->addFieldToFilter('event_type', ['in' => [self::EVT_CONSENT, self::EVT_LOSS]]);
        $types = array_unique($collection->getColumnValues('event_type'));
        return in_array(self::EVT_CONSENT, $types, true) && in_array(self::EVT_LOSS, $types, true);
    }

    /**
     * Has confirmation sent.
     *
     * @param int $orderId
     * @param int $orderItemId
     * @return bool
     */
    public function hasConfirmationSent(int $orderId, int $orderItemId): bool
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('order_id', $orderId)
            ->addFieldToFilter('order_item_id', $orderItemId)
            ->addFieldToFilter('event_type', self::EVT_CONFIRM)
            ->setPageSize(1);
        return (bool) $collection->getFirstItem()->getData('confirmation_sent_at');
    }

    /**
     * Has performance started.
     *
     * @param int $orderId
     * @param int $orderItemId
     * @return bool
     */
    public function hasPerformanceStarted(int $orderId, int $orderItemId): bool
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('order_id', $orderId)
            ->addFieldToFilter('order_item_id', $orderItemId)
            ->addFieldToFilter('event_type', self::EVT_PERF)
            ->setPageSize(1);
        return (bool) $collection->getFirstItem()->getData('created_at');
    }

    /** @return array<int, list<array<string,mixed>>> keyed by order_item_id */
    public function findEventsForOrder(int $orderId): array
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('order_id', $orderId)->setOrder('created_at', 'ASC');
        $grouped = [];
        foreach ($collection->getItems() as $event) {
            $row = $event->getData();
            $itemId = (int) ($row['order_item_id'] ?? 0);
            $grouped[$itemId][] = $row;
        }
        return $grouped;
    }

    /** @return list<array<string,mixed>> */
    public function findQuoteEvents(int $quoteItemId): array
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('quote_item_id', $quoteItemId)
            ->addFieldToFilter('order_id', 0)
            ->setOrder('created_at', 'ASC');
        // array_values: getItems() is keyed by entity id; callers expect a 0-based list.
        return array_values(array_map(static fn ($event): array => $event->getData(), $collection->getItems()));
    }
}
