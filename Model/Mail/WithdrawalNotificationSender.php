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
use MageMe\EUWithdrawal\Model\Receipt\ReceiptBuilder;
use Magento\Framework\App\Area;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Locale\ResolverInterface as LocaleResolver;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\TranslateInterface;
use Magento\Store\Model\App\Emulation;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Sends the synchronous "your withdrawal has been recorded" notification
 * email at the end of the customer-initiated submit flow. Uses the shared
 * branded `Block\Email\Layout` partials so the result matches the rest of
 * the lifecycle email family (approved / denied / cancelled / receipt).
 *
 * Fail-soft on MailException (logs warning, does not propagate) — the
 * customer still gets the later receipt email via the queue consumer.
 */
class WithdrawalNotificationSender
{
    private const CONTACT_ADDRESS_PATH = 'trans_email/ident_general/email';

    /**
     * Constructor.
     *
     * @param TransportBuilder $transportBuilder
     * @param ScopeConfigInterface $scopeConfig
     * @param LayoutFactory $layoutFactory
     * @param ItemsTableFactory $itemsTableFactory
     * @param StoreManagerInterface $storeManager
     * @param EmailDataResolver $emailData
     * @param \MageMe\EUWithdrawal\Api\RequestRepositoryInterface $requestRepository
     * @param ReceiptBuilder $receiptBuilder
     * @param EmailConfig $emailConfig
     * @param LoggerInterface $logger
     * @param Emulation $emulation
     * @param LocaleResolver $localeResolver
     * @param TranslateInterface $translate
     * @param CustomerViewUrlResolver $customerViewUrl
     */
    public function __construct(
        private readonly TransportBuilder $transportBuilder,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly LayoutFactory $layoutFactory,
        private readonly ItemsTableFactory $itemsTableFactory,
        private readonly StoreManagerInterface $storeManager,
        private readonly EmailDataResolver $emailData,
        private readonly \MageMe\EUWithdrawal\Api\RequestRepositoryInterface $requestRepository,
        private readonly ReceiptBuilder $receiptBuilder,
        private readonly EmailConfig $emailConfig,
        private readonly LoggerInterface $logger,
        private readonly Emulation $emulation,
        private readonly LocaleResolver $localeResolver,
        private readonly TranslateInterface $translate,
        private readonly CustomerViewUrlResolver $customerViewUrl,
    ) {
    }

    /**
     * Send.
     *
     * @param string $toEmail
     * @param string $consumerName
     * @param string $orderIncrementId
     * @param string $withdrawalIncrementId
     * @param string $locale
     * @param ?int $storeId
     * @return void
     */
    public function send(
        string $toEmail,
        string $consumerName,
        string $orderIncrementId,
        string $withdrawalIncrementId,
        string $locale = 'en_US',
        ?int $storeId = null,
    ): void {
        try {
            $resolvedStoreId = $storeId
                ?? (int) ($this->storeManager->getDefaultStoreView()?->getId() ?? 0);

            if (!$this->emailConfig->isEnabled(EmailConfig::TYPE_SUBMITTED, $resolvedStoreId)) {
                return;
            }
            $template = $this->emailConfig->getTemplate(EmailConfig::TYPE_SUBMITTED, $resolvedStoreId);
            if ($template === '') {
                return;
            }
            $this->emulation->startEnvironmentEmulation($resolvedStoreId, Area::AREA_FRONTEND, true);
            if ($locale !== '') {
                $this->localeResolver->setLocale($locale);
                $this->translate->setLocale($locale);
                $this->translate->loadData(Area::AREA_FRONTEND, true);
            }
            $contactEmail = (string) $this->scopeConfig->getValue(
                self::CONTACT_ADDRESS_PATH,
                ScopeInterface::SCOPE_STORE,
                $resolvedStoreId,
            );

            $layout = $this->layoutFactory->create();
            $layout->setData('store_id', $resolvedStoreId);
            $layout->setData(
                'disclaimer',
                (string) __('This is a confirmation that your withdrawal request has been recorded.'),
            );
            $store = $this->storeManager->getStore($resolvedStoreId);

            // Recover the integer request_id from the zero-padded increment
            // ('000000035' → 35) so the items table block can load rows.
            $requestId = (int) $withdrawalIncrementId;
            $itemsHtml = '';
            $submittedAt = '';
            $refundMethod = (string) __('Original payment method');
            $requestIncrementId = $withdrawalIncrementId;
            $refundTotalFormatted = '';
            $orderEntityId = 0;
            $customerId = null;
            if ($requestId > 0) {
                $itemsTable = $this->itemsTableFactory->create();
                $itemsTable->setData('store_id', $resolvedStoreId);
                $itemsTable->setData('current_status', RequestInterface::STATUS_PENDING);
                $itemsHtml = $itemsTable->renderForRequest($requestId);

                try {
                    $req = $this->requestRepository->get($requestId);
                    $submittedAt = $this->emailData->formatDate((string) $req->getCreatedAt(), $resolvedStoreId);
                    $orderEntityId = (int) $req->getOrderId();
                    $customerId = $req->getCustomerId();
                    $refundMethod = $this->emailData->getRefundMethod($orderEntityId);
                    $requestIncrementId = (string) ($req->getIncrementId() ?? $withdrawalIncrementId);
                } catch (\Throwable) {
                    // degrade gracefully — fields stay default
                }

                try {
                    $dto = $this->receiptBuilder->build($requestId);
                    $refundTotalFormatted = $this->emailData->formatPrice(
                        (string) ($dto->refund['total'] ?? '0.00'),
                        $resolvedStoreId,
                    );
                } catch (\Throwable) {
                    // refund total stays empty — template shows "—" fallback
                }
            }

            $vars = [
                'subject_text'            => (string) __('Your withdrawal request has been submitted — Order #%1', $orderIncrementId),
                'order_increment_id'      => $orderIncrementId,
                'withdrawal_increment_id' => $withdrawalIncrementId,
                'consumer_name'           => $consumerName,
                'contact_email'           => $contactEmail,
                'email_header_html'       => $layout->renderHeader(),
                'email_footer_html'       => $layout->renderFooter(),
                'items_html'              => $itemsHtml,
                'support_email'           => $layout->getSupportEmail(),
                'store_name'              => $layout->getStoreName(),
                'store_url'               => $store->getBaseUrl(),
                'view_url'                => $this->customerViewUrl->resolveForCustomer(
                    $orderEntityId,
                    $customerId,
                    $resolvedStoreId,
                    $store->getBaseUrl(),
                ),
                'submitted_at_formatted'  => $submittedAt,
                'refund_method'           => $refundMethod,
                'request_increment_id'    => $requestIncrementId,
                'refund_total_formatted'  => $refundTotalFormatted,
            ];

            $this->transportBuilder
                ->setTemplateIdentifier($template)
                ->setTemplateOptions([
                    'area'  => Area::AREA_FRONTEND,
                    'store' => $resolvedStoreId,
                ])
                ->setTemplateVars($vars)
                ->setFromByScope('support', $resolvedStoreId)
                ->addTo($toEmail);

            foreach ($this->emailConfig->getBccList(EmailConfig::TYPE_SUBMITTED, $resolvedStoreId) as $bcc) {
                $this->transportBuilder->addBcc($bcc);
            }

            $this->transportBuilder->getTransport()->sendMessage();
        } catch (\Throwable $e) {
            $this->logger->warning(
                'EUWithdrawal notification send failed: ' . $e->getMessage(),
                ['email' => $toEmail, 'order_increment_id' => $orderIncrementId],
            );
        } finally {
            $this->emulation->stopEnvironmentEmulation();
        }
    }
}
