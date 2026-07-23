<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Mail;

use MageMe\EUWithdrawal\Api\Data\RequestInterface;
use MageMe\EUWithdrawal\Block\Email\LayoutFactory;
use Magento\Framework\App\Area;
use Magento\Framework\Locale\ResolverInterface as LocaleResolver;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\TranslateInterface;
use Magento\Sales\Api\CreditmemoRepositoryInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Store\Model\App\Emulation;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Sends the customer-facing "your refund has been initiated" email after the
 * auto-approval engine issues a withdrawal request's refund credit memo. Uses
 * the shared branded Block\Email\Layout partials so it matches the rest of the
 * lifecycle email family (submitted / approved / denied / cancelled / receipt).
 *
 * The offline-vs-online refund flag selects the wording: an offline memo is
 * "recorded and will be paid separately"; an online memo is "issued to your
 * original payment method". Runs under store emulation with the customer's
 * frozen locale so a CRON-triggered send is translated for the recipient, not
 * for the cron's ambient locale.
 *
 * Fail-soft: any error is logged and swallowed — a failed courtesy email must
 * never break the auto-approval engine or roll back the memo it already issued.
 */
class RefundInitiatedSender
{
    /**
     * Constructor.
     *
     * @param TransportBuilder $transportBuilder
     * @param LayoutFactory $layoutFactory
     * @param OrderRepositoryInterface $orderRepository
     * @param CreditmemoRepositoryInterface $creditmemoRepository
     * @param StoreManagerInterface $storeManager
     * @param Emulation $emulation
     * @param EmailDataResolver $emailData
     * @param EmailConfig $emailConfig
     * @param CustomerViewUrlResolver $customerViewUrl
     * @param LocaleResolver $localeResolver
     * @param TranslateInterface $translate
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly TransportBuilder $transportBuilder,
        private readonly LayoutFactory $layoutFactory,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly CreditmemoRepositoryInterface $creditmemoRepository,
        private readonly StoreManagerInterface $storeManager,
        private readonly Emulation $emulation,
        private readonly EmailDataResolver $emailData,
        private readonly EmailConfig $emailConfig,
        private readonly CustomerViewUrlResolver $customerViewUrl,
        private readonly LocaleResolver $localeResolver,
        private readonly TranslateInterface $translate,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Send the refund-initiated email for a freshly issued credit memo.
     *
     * @param RequestInterface $request
     * @param int $creditmemoId
     * @param bool $online
     * @return void
     */
    public function send(RequestInterface $request, int $creditmemoId, bool $online): void
    {
        $toEmail = (string) $request->getCustomerEmail();
        if ($toEmail === '') {
            return;
        }

        $storeId = $this->resolveStoreId($request);
        if (!$this->emailConfig->isEnabled(EmailConfig::TYPE_AUTO_REFUND_INITIATED, $storeId)) {
            return;
        }
        $template = $this->emailConfig->getTemplate(EmailConfig::TYPE_AUTO_REFUND_INITIATED, $storeId);
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
            $vars = $this->buildVars($request, $creditmemoId, $online, $storeId);
            $identity = $this->emailConfig->getIdentity(EmailConfig::TYPE_AUTO_REFUND_INITIATED, $storeId);

            $this->transportBuilder
                ->setTemplateIdentifier($template)
                ->setTemplateOptions([
                    'area'  => Area::AREA_FRONTEND,
                    'store' => $storeId,
                ])
                ->setTemplateVars($vars)
                ->setFromByScope($identity, $storeId)
                ->addTo($toEmail);

            foreach ($this->emailConfig->getBccList(EmailConfig::TYPE_AUTO_REFUND_INITIATED, $storeId) as $bcc) {
                $this->transportBuilder->addBcc($bcc);
            }

            $this->transportBuilder->getTransport()->sendMessage();
        } catch (\Throwable $e) {
            $this->logger->warning(
                'EUWithdrawal auto-refund notification failed: ' . $e->getMessage(),
                ['request_id' => $request->getRequestId(), 'creditmemo_id' => $creditmemoId],
            );
        } finally {
            $this->emulation->stopEnvironmentEmulation();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildVars(RequestInterface $request, int $creditmemoId, bool $online, int $storeId): array
    {
        $layout = $this->layoutFactory->create();
        $layout->setData('store_id', $storeId);
        $layout->setData(
            'disclaimer',
            (string) __('This email confirms that a refund has been initiated for your withdrawal request.'),
        );

        $store = $this->storeManager->getStore($storeId);
        $baseUrl = $store->getBaseUrl();

        $orderId = (int) $request->getOrderId();
        $orderIncrementId = '';
        $orderCurrency = null;
        try {
            $order = $this->orderRepository->get($orderId);
            $orderIncrementId = (string) $order->getIncrementId();
            $orderCurrency = (string) $order->getOrderCurrencyCode();
        } catch (\Throwable) {
            // degrade gracefully — order fields stay empty, price falls back to store currency
        }

        $memo = $this->creditmemoRepository->get($creditmemoId);
        $memoIncrementId = (string) $memo->getIncrementId();
        $refundAmount = (string) ($memo->getGrandTotal() ?? '0.00');

        return [
            'subject_text'             => (string) __('Your refund has been initiated — Credit memo #%1', $memoIncrementId),
            'consumer_name'            => (string) $request->getCustomerName(),
            'creditmemo_increment_id'  => $memoIncrementId,
            'order_increment_id'       => $orderIncrementId,
            'refund_amount_formatted'  => $this->emailData->formatPrice($refundAmount, $storeId, $orderCurrency),
            'refund_online'            => $online,
            'view_url'                 => $this->customerViewUrl->resolveForCustomer(
                $orderId,
                $request->getCustomerId(),
                $storeId,
                $baseUrl,
            ),
            'support_email'            => $layout->getSupportEmail(),
            'store_name'               => $layout->getStoreName(),
            'store_url'                => $baseUrl,
            'email_header_html'        => $layout->renderHeader(),
            'email_footer_html'        => $layout->renderFooter(),
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
        $storeId = (int) $request->getStoreId();
        if ($storeId > 0) {
            return $storeId;
        }
        $orderId = (int) $request->getOrderId();
        if ($orderId > 0) {
            try {
                return (int) $this->orderRepository->get($orderId)->getStoreId();
            } catch (\Throwable) {
                // fall through to default
            }
        }
        return (int) ($this->storeManager->getDefaultStoreView()?->getId() ?? 0);
    }
}
