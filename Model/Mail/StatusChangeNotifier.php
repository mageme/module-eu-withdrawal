<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Mail;

use MageMe\EUWithdrawal\Api\Data\RequestInterface;
use MageMe\EUWithdrawal\Block\Email\ItemsTableFactory;
use MageMe\EUWithdrawal\Block\Email\LayoutFactory;
use MageMe\EUWithdrawal\Model\Frontend\RouteResolver;
use MageMe\EUWithdrawal\Model\Receipt\ReceiptBuilder;
use Magento\Framework\App\Area;
use Magento\Framework\Locale\ResolverInterface as LocaleResolver;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\TranslateInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Store\Model\App\Emulation;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Sends a customer-facing email when the withdrawal request transitions to a
 * customer-visible state (approved / denied / cancelled). Synchronous: failure
 * is logged and swallowed (no DLQ, no retry — these are UX courtesy, not the
 * Art. 11a(4) durable-medium receipt which has its own queued pipeline).
 *
 * The discriminator between admin-cancel and customer-self-cancel is the
 * `admin_id` value in the StatusMachine context: customer-side `Cancel`
 * controller passes `'admin_id' => 'customer-self'` (sentinel); any other
 * value (typically the admin user numeric id) is treated as merchant-side.
 */
class StatusChangeNotifier
{
    public const ACTOR_CUSTOMER_SELF = 'customer-self';

    /**
     * Constructor.
     *
     * @param TransportBuilder $transportBuilder
     * @param LayoutFactory $layoutFactory
     * @param ItemsTableFactory $itemsTableFactory
     * @param ReceiptBuilder $receiptBuilder
     * @param OrderRepositoryInterface $orderRepository
     * @param StoreManagerInterface $storeManager
     * @param Emulation $emulation
     * @param EmailDataResolver $emailData
     * @param EmailConfig $emailConfig
     * @param LoggerInterface $logger
     * @param LocaleResolver $localeResolver
     * @param TranslateInterface $translate
     * @param RouteResolver $routeResolver
     * @param CustomerViewUrlResolver $customerViewUrl
     */
    public function __construct(
        private readonly TransportBuilder $transportBuilder,
        private readonly LayoutFactory $layoutFactory,
        private readonly ItemsTableFactory $itemsTableFactory,
        private readonly ReceiptBuilder $receiptBuilder,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly StoreManagerInterface $storeManager,
        private readonly Emulation $emulation,
        private readonly EmailDataResolver $emailData,
        private readonly EmailConfig $emailConfig,
        private readonly LoggerInterface $logger,
        private readonly LocaleResolver $localeResolver,
        private readonly TranslateInterface $translate,
        private readonly RouteResolver $routeResolver,
        private readonly CustomerViewUrlResolver $customerViewUrl,
    ) {
    }

    /**
     * @param array<string, mixed> $context  StatusMachine event payload
     */
    public function sendForTransition(
        RequestInterface $request,
        string $from,
        string $to,
        array $context,
    ): void {
        $type = $this->resolveType($to, $context);
        if ($type === null) {
            return;
        }
        if ((string) $request->getCustomerEmail() === '') {
            return;
        }

        $storeId = $this->resolveStoreId($request);

        if (!$this->emailConfig->isEnabled($type, $storeId)) {
            return;
        }
        $template = $this->emailConfig->getTemplate($type, $storeId);
        if ($template === '') {
            return;
        }

        $this->emulation->startEnvironmentEmulation($storeId, Area::AREA_FRONTEND, true);
        $customerLocale = (string) $request->getLocale();
        if ($customerLocale !== '') {
            $this->localeResolver->setLocale($customerLocale);
            $this->translate->setLocale($customerLocale);
            $this->translate->loadData(Area::AREA_FRONTEND, true);
        }
        try {
            $vars = $this->buildVars($request, $context, $storeId, $type);

            $this->transportBuilder
                ->setTemplateIdentifier($template)
                ->setTemplateOptions([
                    'area'  => Area::AREA_FRONTEND,
                    'store' => $storeId,
                ])
                ->setTemplateVars($vars)
                ->setFromByScope('support', $storeId)
                ->addTo((string) $request->getCustomerEmail());

            foreach ($this->emailConfig->getBccList($type, $storeId) as $bcc) {
                $this->transportBuilder->addBcc($bcc);
            }

            $this->transportBuilder->getTransport()->sendMessage();
        } catch (\Throwable $e) {
            $this->logger->warning(
                'EUWithdrawal status-change notification failed: ' . $e->getMessage(),
                [
                    'request_id' => $request->getRequestId(),
                    'to'         => $to,
                    'template'   => $template,
                ],
            );
        } finally {
            $this->emulation->stopEnvironmentEmulation();
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    private function resolveType(string $to, array $context): ?string
    {
        if ($to === RequestInterface::STATUS_APPROVED) {
            return EmailConfig::TYPE_APPROVED;
        }
        if ($to === RequestInterface::STATUS_DENIED) {
            return EmailConfig::TYPE_DENIED;
        }
        if ($to === RequestInterface::STATUS_CANCELLED) {
            $actor = (string) ($context['admin_id'] ?? '');
            return $actor === self::ACTOR_CUSTOMER_SELF
                ? EmailConfig::TYPE_CANCELLED_SELF
                : EmailConfig::TYPE_CANCELLED_ADMIN;
        }
        return null;
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function buildVars(RequestInterface $request, array $context, int $storeId, string $type): array
    {
        $layout = $this->layoutFactory->create();
        $layout->setData('store_id', $storeId);
        $layout->setData(
            'disclaimer',
            (string) __('This email is a notification about your withdrawal request.'),
        );

        $dto = null;
        try {
            $dto = $this->receiptBuilder->build((int) $request->getRequestId());
        } catch (\Throwable) {
            // Receipt may be unavailable for some rows; degrade gracefully.
        }

        $store = $this->storeManager->getStore($storeId);
        $baseUrl = rtrim($store->getBaseUrl(), '/');

        $itemsTable = $this->itemsTableFactory->create();
        $itemsTable->setData('store_id', $storeId);
        $itemsTable->setData('current_status', (string) $request->getStatus());
        $itemsHtml = $itemsTable->renderForRequest((int) $request->getRequestId());

        $orderId = (int) $request->getOrderId();
        $orderCurrency = null;
        try {
            if ($orderId > 0) {
                $orderCurrency = (string) $this->orderRepository->get($orderId)->getOrderCurrencyCode();
            }
        } catch (\Throwable) {
            // ignore — formatPrice falls back to store currency
        }

        // CTA target depends on email type:
        //  - approved          → order page (registered) / withdrawal entry (guest)
        //  - cancelled_admin   → /withdraw-contract/ ("Submit a new request")
        //  - cancelled_self    → /sales/order/history/ ("Back to my orders")
        //  - other             → order page (closest thing to "your request")
        $viewUrl = match ($type) {
            EmailConfig::TYPE_CANCELLED_ADMIN => $this->routeResolver->rewriteCanonical(
                $baseUrl . '/' . RouteResolver::CANONICAL_FRONT_NAME . '/',
                $storeId,
            ),
            EmailConfig::TYPE_CANCELLED_SELF  => ((int) $request->getCustomerId()) > 0
                ? $baseUrl . '/sales/order/history/'
                : $this->customerViewUrl->resolveForCustomer(
                    $orderId,
                    $request->getCustomerId(),
                    $storeId,
                    $baseUrl,
                ),
            default => $this->customerViewUrl->resolveForCustomer(
                $orderId,
                $request->getCustomerId(),
                $storeId,
                $baseUrl,
            ),
        };

        $refundTotal = $dto !== null ? (string) ($dto->refund['total'] ?? '0.00') : '0.00';

        $requestIncrementId = (string) ($request->getIncrementId() ?? sprintf('%09d', (int) $request->getRequestId()));
        $subjectText = match ($type) {
            EmailConfig::TYPE_APPROVED        => (string) __('Your refund is on its way — Withdrawal #%1', $requestIncrementId),
            EmailConfig::TYPE_DENIED          => (string) __('Update on your withdrawal request — Withdrawal #%1', $requestIncrementId),
            EmailConfig::TYPE_CANCELLED_ADMIN => (string) __('Your withdrawal was cancelled — Withdrawal #%1', $requestIncrementId),
            EmailConfig::TYPE_CANCELLED_SELF  => (string) __('Cancellation confirmed — Withdrawal #%1', $requestIncrementId),
            default                           => '',
        };

        return [
            'subject_text'             => $subjectText,
            'email_header_html'        => $layout->renderHeader(),
            'email_footer_html'        => $layout->renderFooter(),
            'items_html'               => $itemsHtml,
            'consumer_name'            => (string) $request->getCustomerName(),
            'customer_email'           => (string) $request->getCustomerEmail(),
            'request_increment_id'     => (string) ($request->getIncrementId() ?? sprintf('%09d', (int) $request->getRequestId())),
            'order_increment_id'       => $dto !== null ? (string) ($dto->order['increment_id'] ?? '') : '',
            'refund_total'             => $refundTotal,
            'refund_total_formatted'   => $this->emailData->formatPrice($refundTotal, $storeId, $orderCurrency),
            'refund_method'            => $this->emailData->getRefundMethod($orderId),
            'event_date_formatted'     => $this->emailData->formatDate(gmdate('Y-m-d H:i:s'), $storeId),
            'submitted_at_formatted'   => $this->emailData->formatDate((string) $request->getCreatedAt(), $storeId),
            'estimated_arrival'        => (string) __('5–7 business days'),
            // StatusMachine renames the `denial_reason` context key to
            // `legal_basis` when building the audit event payload — accept both.
            'denial_reason'            => (string) ($context['denial_reason'] ?? $context['legal_basis'] ?? ''),
            'admin_note'               => (string) ($context['note'] ?? ''),
            'view_url'                 => $viewUrl,
            'support_email'            => $layout->getSupportEmail(),
            'store_name'               => $layout->getStoreName(),
            'store_url'                => $store->getBaseUrl(),
            'items'                    => $dto !== null ? $dto->items : [],
        ];
    }

    /**
     * Resolve store id.
     *
     * @param RequestInterface $request
     * @return int
     */
    private function resolveStoreId(RequestInterface $request): int
    {
        $orderId = (int) $request->getOrderId();
        if ($orderId > 0) {
            try {
                return (int) $this->orderRepository->get($orderId)->getStoreId();
            } catch (\Throwable) {
                // fall through
            }
        }
        return (int) ($this->storeManager->getDefaultStoreView()?->getId() ?? 0);
    }
}
