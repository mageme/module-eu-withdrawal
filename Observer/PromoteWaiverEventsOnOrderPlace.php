<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Observer;

use MageMe\EUWithdrawal\Model\ModuleConfig;
use MageMe\EUWithdrawal\Model\Waiver\WaiverEventReader;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Psr\Log\LoggerInterface;

class PromoteWaiverEventsOnOrderPlace implements ObserverInterface
{
    public const TABLE = 'mm_eu_withdrawal_waiver_event';

    /**
     * Constructor.
     *
     * @param ResourceConnection $resource
     * @param ManagerInterface $eventManager
     * @param WaiverEventReader $reader
     * @param ModuleConfig $moduleConfig
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly ManagerInterface $eventManager,
        private readonly WaiverEventReader $reader,
        private readonly ModuleConfig $moduleConfig,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Execute.
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        /** @var OrderInterface $order */
        $order = $observer->getEvent()->getOrder();
        if (!$order || !$order->getEntityId()) {
            return;
        }
        if (!$this->moduleConfig->isEnabled((int) $order->getStoreId())) {
            return;
        }

        try {
            $conn = $this->resource->getConnection();
            $table = $this->resource->getTableName(self::TABLE);

            $conn->beginTransaction();
            try {
                foreach ($order->getAllVisibleItems() as $item) {
                    $quoteItemId = (int) $item->getQuoteItemId();
                    if ($quoteItemId <= 0) {
                        continue;
                    }
                    $conn->update(
                        $table,
                        [
                            'order_id' => (int) $order->getEntityId(),
                            'order_item_id' => (int) $item->getItemId(),
                            'customer_email' => (string) $order->getCustomerEmail(),
                            'product_sku' => (string) $item->getSku(),
                        ],
                        [
                            'order_id = ?' => 0,
                            'quote_item_id = ?' => $quoteItemId,
                        ],
                    );
                }
                $conn->commit();
            } catch (\Throwable $t) {
                $conn->rollBack();
                throw $t;
            }

            $orderId = (int) $order->getEntityId();
            $grouped = $this->reader->findEventsForOrder($orderId);
            $itemsForAudit = [];
            foreach ($grouped as $itemId => $eventRows) {
                if ($itemId <= 0) {
                    continue;
                }
                $first = $eventRows[0] ?? null;
                if ($first === null) {
                    continue;
                }
                $hash = $first['waiver_text_hash'] ?? null;
                if ($hash === null || $hash === '') {
                    continue;
                }
                $itemsForAudit[] = [
                    'sku'               => (string) ($first['product_sku'] ?? ''),
                    'waiver_text_hash'  => (string) $hash,
                    'locale'            => (string) ($first['locale'] ?? ''),
                    'jurisdiction'      => (string) ($first['jurisdiction'] ?? ''),
                ];
            }
            if (count($itemsForAudit) > 0) {
                $this->eventManager->dispatch('mageme_eu_withdrawal_audit_waiver_collected', [
                    'order_id' => $orderId,
                    'items'    => $itemsForAudit,
                    'count'    => count($itemsForAudit),
                ]);
            }
        } catch (\Throwable $t) {
            $this->logger->error(
                'MageMe_EUWithdrawal: waiver-event promotion failed at order place for order '
                . (int) $order->getEntityId() . '; order placement is unaffected.',
                ['exception' => $t],
            );
        }
    }
}
