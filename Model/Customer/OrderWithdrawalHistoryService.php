<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Customer;

use MageMe\EUWithdrawal\Api\Data\ItemInterface;
use MageMe\EUWithdrawal\Api\Data\RequestInterface;
use MageMe\EUWithdrawal\Model\ResourceModel\Item\CollectionFactory as ItemCollectionFactory;
use MageMe\EUWithdrawal\Model\ResourceModel\Request\CollectionFactory as RequestCollectionFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\OrderRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Returns the caller's withdrawal requests for a single order, enriched
 * with per-item names (from sales_order_item) and a cancellable flag.
 *
 * Ownership check happens inside this service: logged-in visitors see
 * only requests on orders they own; magic-link visitors see only the
 * order the token is bound to. Ownership failure returns `[]` — no
 * error surfaced (matches the module's anti-enumeration posture).
 */
class OrderWithdrawalHistoryService
{
    private const TABLE_REQUEST = 'mm_eu_withdrawal_request';
    private const TABLE_ITEM    = 'mm_eu_withdrawal_item';
    private const TABLE_ORDER_ITEM = 'sales_order_item';

    public const CANCELLABLE_STATUSES = [
        RequestInterface::STATUS_PENDING,
    ];

    /**
     * Constructor.
     *
     * @param RequestCollectionFactory $requestCollectionFactory
     * @param ItemCollectionFactory $itemCollectionFactory
     * @param OrderRepositoryInterface $orderRepository
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly RequestCollectionFactory $requestCollectionFactory,
        private readonly ItemCollectionFactory $itemCollectionFactory,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return WithdrawalRequestView[]
     */
    public function listForOrder(int $orderEntityId, CustomerIdentity $who): array
    {
        if ($orderEntityId <= 0) {
            return [];
        }

        try {
            $order = $this->orderRepository->get($orderEntityId);
        } catch (NoSuchEntityException) {
            return [];
        }
        if (!$who->canSeeOrder($orderEntityId, $order)) {
            return [];
        }

        try {
            $requestCollection = $this->requestCollectionFactory->create();
            $requestCollection->addFieldToFilter(RequestInterface::ORDER_ID, $orderEntityId)
                ->setOrder(RequestInterface::SUBMITTED_AT, 'DESC');
            $requestRows = array_values(array_map(
                static fn ($r): array => $r->getData(),
                $requestCollection->getItems(),
            ));
            if ($requestRows === []) {
                return [];
            }

            $requestIds = array_map(
                static fn (array $r) => (int) $r[RequestInterface::REQUEST_ID],
                $requestRows,
            );

            // Item names live on sales_order_item; the LEFT JOIN stays in the
            // collection's underlying select (no ORM relation exists for it).
            $itemCollection = $this->itemCollectionFactory->create();
            $itemCollection->addFieldToFilter(ItemInterface::REQUEST_ID, ['in' => $requestIds]);
            $itemCollection->getSelect()
                ->joinLeft(
                    ['oi' => $itemCollection->getTable(self::TABLE_ORDER_ITEM)],
                    'oi.item_id = main_table.order_item_id',
                    ['name' => 'oi.name'],
                )
                ->order('main_table.order_item_id ASC');
            $itemRows = array_values(array_map(
                static fn ($r): array => $r->getData(),
                $itemCollection->getItems(),
            ));
        } catch (\Throwable $e) {
            $this->logger->warning(
                'EUWithdrawal history query failed: ' . $e->getMessage(),
                ['order_id' => $orderEntityId],
            );
            return [];
        }

        $itemsByRequest = [];
        $rawTotalsByRequest = [];
        foreach ($itemRows as $ir) {
            $requestId = (int) $ir[ItemInterface::REQUEST_ID];
            $rawRefund = (float) $ir[ItemInterface::REFUND_AMOUNT];
            $rawTotalsByRequest[$requestId] = ($rawTotalsByRequest[$requestId] ?? 0.0) + $rawRefund;
            // Output keys below are the customer-facing view-model contract consumed by
            // templates — intentionally left as string literals (not ORM data-keys).
            $itemsByRequest[$requestId][] = [
                'order_item_id' => (int) $ir[ItemInterface::ORDER_ITEM_ID],
                'sku'           => (string) $ir[ItemInterface::SKU],
                // LEFT JOIN may miss when sales_order_item is gone (item deleted
                // after the withdrawal was recorded). Fall back to the SKU we
                // snapshotted on the request — the name is display-only.
                // 'name' is the joined sales_order_item alias, not an Item column.
                'name'          => (string) ($ir['name'] ?? $ir[ItemInterface::SKU]),
                'qty'           => (int) (float) $ir[ItemInterface::QTY_WITHDRAW],
                'refund_amount' => number_format($rawRefund, 2, '.', ''),
                'eligibility'   => (string) $ir[ItemInterface::ELIGIBILITY],
                'reason_code'   => $ir[ItemInterface::REASON_CODE] !== null ? (string) $ir[ItemInterface::REASON_CODE] : null,
                'reason_text'   => $ir[ItemInterface::REASON_TEXT] !== null ? (string) $ir[ItemInterface::REASON_TEXT] : null,
            ];
        }

        $currency = (string) $order->getOrderCurrencyCode();
        $views = [];
        foreach ($requestRows as $row) {
            $requestId = (int) $row[RequestInterface::REQUEST_ID];
            $items     = $itemsByRequest[$requestId] ?? [];
            // Sum raw floats from DB once — avoids parsing already-formatted
            // strings and keeps the total locale-agnostic. Items refund is
            // gross (subtotal + line tax baked in by RequestCreator); shipping
            // refund (gross since 0.12.2 — includes shipping VAT) lives on
            // the request row and must be added so the customer-facing
            // "Refund $X" subtitle matches what RefundCalculator + the
            // verify-page show.
            $refundTotal = ($rawTotalsByRequest[$requestId] ?? 0.0)
                + (float) ($row[RequestInterface::SHIPPING_REFUND] ?? 0.0);
            $views[] = new WithdrawalRequestView(
                requestId:   $requestId,
                incrementId: (string) ($row[RequestInterface::INCREMENT_ID] ?? sprintf('%09d', $requestId)),
                status:      (string) $row[RequestInterface::STATUS],
                submittedAt: (string) $row[RequestInterface::SUBMITTED_AT],
                items:       $items,
                refundTotal: number_format($refundTotal, 2, '.', ''),
                currency:    $currency,
                cancellable: in_array((string) $row[RequestInterface::STATUS], self::CANCELLABLE_STATUSES, true),
                statusChangeNote:       ($row[RequestInterface::STATUS_CHANGE_NOTE] ?? null) !== null ? (string) $row[RequestInterface::STATUS_CHANGE_NOTE] : null,
                statusChangeLegalBasis: ($row[RequestInterface::STATUS_CHANGE_LEGAL_BASIS] ?? null) !== null ? (string) $row[RequestInterface::STATUS_CHANGE_LEGAL_BASIS] : null,
                statusChangeActor:      ($row[RequestInterface::STATUS_CHANGE_ACTOR] ?? null) !== null ? (string) $row[RequestInterface::STATUS_CHANGE_ACTOR] : null,
            );
        }
        return $views;
    }
}
