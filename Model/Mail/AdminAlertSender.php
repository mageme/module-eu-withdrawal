<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Mail;

use MageMe\EUWithdrawal\Api\Data\RequestInterface;
use MageMe\EUWithdrawal\Api\ItemRepositoryInterface;
use Magento\Framework\App\Area;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Store\Model\Store;
use Psr\Log\LoggerInterface;

/**
 * Sends merchant-facing admin alert emails. v1 supports a single type
 * (`EmailConfig::TYPE_ADMIN_NEW_REQUEST`); the method signature takes the
 * type as a parameter so future admin-notification types reuse the
 * channel without per-type sender classes.
 *
 * One message per recipient (recipients never see each other's addresses),
 * in the system default locale. Per-recipient locale rendering is intentionally
 * out of scope because programmatic admin emails outside an admin session
 * cannot reliably override `Magento\Framework\Phrase`'s cached renderer.
 *
 * Fail-soft: any exception (order load, SMTP) is caught and logged at
 * WARNING; the customer-facing transaction must not roll back over an
 * admin-side delivery problem.
 */
class AdminAlertSender
{
    public function __construct(
        private readonly EmailConfig $emailConfig,
        private readonly TransportBuilder $transportBuilder,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly PriceCurrencyInterface $priceCurrency,
        private readonly ItemRepositoryInterface $itemRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function send(string $type, RequestInterface $request): void
    {
        if (!$this->emailConfig->isEnabled($type)) {
            return;
        }

        $recipients = $this->emailConfig->getRecipientList($type);
        if ($recipients === []) {
            return;
        }

        try {
            $order = $this->orderRepository->get($request->getOrderId());
            $vars  = $this->buildVars($request, $order);
        } catch (\Throwable $e) {
            $this->logWarning($type, $request, $e->getMessage());
            return;
        }

        $templateId = $this->emailConfig->getTemplate($type)
            ?: 'mageme_eu_withdrawal_admin_notifications_new_request_template';

        try {
            foreach ($recipients as $address) {
                $this->transportBuilder
                    ->setTemplateIdentifier($templateId)
                    ->setTemplateOptions([
                        'area'  => Area::AREA_ADMINHTML,
                        'store' => Store::DEFAULT_STORE_ID,
                    ])
                    ->setTemplateVars($vars)
                    ->setFromByScope('general', Store::DEFAULT_STORE_ID)
                    ->addTo($address);
                $this->transportBuilder->getTransport()->sendMessage();
            }
        } catch (\Throwable $e) {
            $this->logWarning($type, $request, $e->getMessage());
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildVars(RequestInterface $request, \Magento\Sales\Api\Data\OrderInterface $order): array
    {
        $itemsTotal = 0.0;
        foreach ($this->itemRepository->getByRequest((int) $request->getRequestId()) as $item) {
            $itemsTotal += (float) $item->getRefundAmount();
        }
        $shipping = (float) $request->getShippingRefund();
        $total    = $itemsTotal + $shipping;

        $refundFormatted = (string) $this->priceCurrency->format(
            $total,
            false,
            PriceCurrencyInterface::DEFAULT_PRECISION,
            (int) $order->getStoreId(),
            (string) $order->getOrderCurrencyCode(),
        );

        return [
            'request_increment_id'   => (string) ($request->getIncrementId() ?? '(no-increment)'),
            'order_increment_id'     => (string) $order->getIncrementId(),
            'customer_email'         => (string) ($request->getCustomerEmail() ?? ''),
            'refund_total_formatted' => $refundFormatted,
        ];
    }

    private function logWarning(string $type, RequestInterface $request, string $message): void
    {
        $this->logger->warning(sprintf(
            'AdminAlertSender failed for type=%s request_id=%d: %s',
            $type,
            $request->getRequestId(),
            $message,
        ));
    }
}
