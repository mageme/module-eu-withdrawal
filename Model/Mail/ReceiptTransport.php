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
use Magento\Framework\App\Area;
use Magento\Framework\Locale\ResolverInterface as LocaleResolver;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\TranslateInterface;
use Magento\Store\Model\App\Emulation;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;

class ReceiptTransport
{
    private const TEMPLATE_FALLBACK = 'mageme_eu_withdrawal_notifications_receipt_template';

    /**
     * Constructor.
     *
     * @param TransportBuilder $transportBuilder
     * @param LayoutFactory $layoutFactory
     * @param ItemsTableFactory $itemsTableFactory
     * @param StoreManagerInterface $storeManager
     * @param EmailDataResolver $emailData
     * @param \MageMe\EUWithdrawal\Api\RequestRepositoryInterface $requestRepository
     * @param EmailConfig $emailConfig
     * @param Emulation $emulation
     * @param LocaleResolver $localeResolver
     * @param TranslateInterface $translate
     */
    public function __construct(
        private readonly TransportBuilder $transportBuilder,
        private readonly LayoutFactory $layoutFactory,
        private readonly ItemsTableFactory $itemsTableFactory,
        private readonly StoreManagerInterface $storeManager,
        private readonly EmailDataResolver $emailData,
        private readonly \MageMe\EUWithdrawal\Api\RequestRepositoryInterface $requestRepository,
        private readonly EmailConfig $emailConfig,
        private readonly Emulation $emulation,
        private readonly LocaleResolver $localeResolver,
        private readonly TranslateInterface $translate,
    ) {
    }

    /**
     * @param array{
     *     order_increment_id: string,
     *     consumer_name: string,
     *     refund_total: string,
     *     verify_url: string,
     *     content_hash: string
     * } $vars  Receipt-pipeline-specific vars (legal core); branding vars are
     *          merged in from the shared Layout block.
     */
    public function send(
        string $toEmail,
        string $bccCsv,
        array $vars,
        string $locale = 'en_US',
        int $storeId = Store::DEFAULT_STORE_ID,
        ?int $requestId = null,
    ): void {
        $template = $this->emailConfig->getTemplate(EmailConfig::TYPE_RECEIPT, $storeId);
        if ($template === '') {
            $template = self::TEMPLATE_FALLBACK;
        }

        $this->emulation->startEnvironmentEmulation($storeId, Area::AREA_FRONTEND, true);
        if ($locale !== '') {
            $this->localeResolver->setLocale($locale);
            $this->translate->setLocale($locale);
            $this->translate->loadData(Area::AREA_FRONTEND, true);
        }

        try {
        $layout = $this->layoutFactory->create();
        $layout->setData('store_id', $storeId);
        $layout->setData(
            'disclaimer',
            (string) __('This is your durable-medium receipt for the withdrawal (Art. 11a(4) Directive 2011/83/EU).'),
        );

        $store = $this->storeManager->getStore($storeId);
        $vars['subject_text']      = (string) __('Withdrawal receipt — Order #%1', (string) ($vars['order_increment_id'] ?? ''));
        $vars['email_header_html'] = $layout->renderHeader();
        $vars['email_footer_html'] = $layout->renderFooter();
        $vars['support_email']     = $layout->getSupportEmail();
        $vars['store_name']        = $layout->getStoreName();
        $vars['store_url']         = $store->getBaseUrl();

        if ($requestId !== null) {
            $itemsTable = $this->itemsTableFactory->create();
            $itemsTable->setData('store_id', $storeId);
            $itemsTable->setData('current_status', RequestInterface::STATUS_PENDING);
            $vars['items_html'] = $itemsTable->renderForRequest($requestId);

            try {
                $req = $this->requestRepository->get($requestId);
                $vars['submitted_at_formatted'] = $this->emailData->formatDateTimeUtc((string) $req->getConfirmedAt());
                $vars['refund_method']          = $this->emailData->getRefundMethod((int) $req->getOrderId());
                $vars['refund_total_formatted'] = $this->emailData->formatPrice(
                    (string) ($vars['refund_total'] ?? '0.00'),
                    $storeId,
                );
            } catch (\Throwable) {
                $vars['submitted_at_formatted'] = '';
                $vars['refund_method']          = (string) __('Original payment method');
                $vars['refund_total_formatted'] = (string) ($vars['refund_total'] ?? '');
            }
        } else {
            $vars['items_html']             = '';
            $vars['submitted_at_formatted'] = '';
            $vars['refund_method']          = (string) __('Original payment method');
            $vars['refund_total_formatted'] = (string) ($vars['refund_total'] ?? '');
        }

        // The HTML email itself satisfies the durable-medium requirement of
        // Art. 11a(4) (the customer's inbox preserves it). PDF attachment
        // lives in the Pro tier (`MageMe_EUWithdrawalPro`).
        $this->transportBuilder
            ->setTemplateIdentifier($template)
            ->setTemplateOptions([
                'area'  => Area::AREA_FRONTEND,
                'store' => $storeId,
            ])
            ->setTemplateVars($vars)
            ->setFromByScope('support', $storeId)
            ->addTo($toEmail);

        foreach ($this->parseBcc($bccCsv) as $bcc) {
            $this->transportBuilder->addBcc($bcc);
        }

        $this->transportBuilder->getTransport()->sendMessage();
        } finally {
            $this->emulation->stopEnvironmentEmulation();
        }
    }

    /**
     * @return string[]
     */
    private function parseBcc(string $csv): array
    {
        if ($csv === '') {
            return [];
        }
        return array_values(array_filter(array_map('trim', explode(',', $csv)), static fn($s) => $s !== ''));
    }
}
