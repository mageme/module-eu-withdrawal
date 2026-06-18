<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Observer;

use MageMe\EUWithdrawal\Api\Data\RequestInterface;
use MageMe\EUWithdrawal\Api\RequestRepositoryInterface;
use MageMe\EUWithdrawal\Model\Mail\StatusChangeNotifier;
use MageMe\EUWithdrawal\Model\ModuleConfig;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Psr\Log\LoggerInterface;

/**
 * Listens on `mageme_eu_withdrawal_audit_admin_status_changed` and records the
 * outcome on the related sales order as a status-history comment, so the store
 * team sees approve / deny / cancel directly in the order timeline (the request
 * itself lives in its own Sales → Withdrawals grid).
 *
 * The order status is left untouched — only a comment is added. No-throw: any
 * failure is logged and swallowed so it can never break the StatusMachine
 * transaction that emitted the event.
 */
class AddOrderCommentOnStatusChange implements ObserverInterface
{
    /**
     * Constructor.
     *
     * @param RequestRepositoryInterface $requestRepository
     * @param OrderRepositoryInterface $orderRepository
     * @param ModuleConfig $moduleConfig
     * @param PriceCurrencyInterface $priceCurrency
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly RequestRepositoryInterface $requestRepository,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly ModuleConfig $moduleConfig,
        private readonly PriceCurrencyInterface $priceCurrency,
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
        if (!$this->moduleConfig->isEnabled()) {
            return;
        }
        try {
            $data = $observer->getEvent()->getData();
            $requestId = (int) ($data['request_id'] ?? 0);
            if ($requestId === 0) {
                return;
            }
            $request = $this->requestRepository->get($requestId);
            $orderId = (int) $request->getOrderId();
            if ($orderId <= 0) {
                return;
            }
            $order = $this->orderRepository->get($orderId);
            if (!$order instanceof Order) {
                return;
            }
            $comment = $this->buildComment($request, $order, (string) ($data['to'] ?? ''), $data);
            if ($comment === null) {
                return;
            }
            $order->addCommentToStatusHistory($comment);
            $this->orderRepository->save($order);
        } catch (\Throwable $e) {
            $this->logger->warning(
                'EUWithdrawal: failed to add order comment on status change: ' . $e->getMessage(),
                ['event' => $observer->getEvent()?->getName()],
            );
        }
    }

    /**
     * @param array<string, mixed> $data event payload
     */
    private function buildComment(RequestInterface $request, Order $order, string $to, array $data): ?string
    {
        $reference = (string) ($request->getIncrementId() ?? ('#' . (int) $request->getRequestId()));

        return match ($to) {
            RequestInterface::STATUS_APPROVED  => $this->approvedComment($request, $order, $reference),
            RequestInterface::STATUS_DENIED    => $this->deniedComment($request, $reference),
            RequestInterface::STATUS_CANCELLED => $this->cancelledComment($reference, $data),
            default                            => null,
        };
    }

    private function approvedComment(RequestInterface $request, Order $order, string $reference): string
    {
        $refund = (float) $request->getProRataRefund()
            + (float) $request->getShippingRefund()
            + (float) $request->getOrderAdjustmentRefund();

        if ($refund > 0.0) {
            $formatted = $this->priceCurrency->format(
                $refund,
                false,
                2,
                null,
                $order->getOrderCurrencyCode(),
            );
            return (string) __('EU withdrawal request %1 was approved. Refund: %2.', $reference, $formatted);
        }

        return (string) __('EU withdrawal request %1 was approved.', $reference);
    }

    private function deniedComment(RequestInterface $request, string $reference): string
    {
        $reason = trim((string) ($request->getStatusChangeLegalBasis() ?? $request->getStatusChangeNote() ?? ''));
        if ($reason !== '') {
            return (string) __('EU withdrawal request %1 was denied. Reason: %2', $reference, $reason);
        }
        return (string) __('EU withdrawal request %1 was denied.', $reference);
    }

    /**
     * @param array<string, mixed> $data event payload
     */
    private function cancelledComment(string $reference, array $data): string
    {
        $actor = (string) ($data['admin_id'] ?? '');
        return $actor === StatusChangeNotifier::ACTOR_CUSTOMER_SELF
            ? (string) __('EU withdrawal request %1 was cancelled by the customer.', $reference)
            : (string) __('EU withdrawal request %1 was cancelled by an administrator.', $reference);
    }
}
