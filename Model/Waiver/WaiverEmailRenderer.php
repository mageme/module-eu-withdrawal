<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Waiver;

use MageMe\EUWithdrawal\Block\Email\LayoutFactory;
use MageMe\EUWithdrawal\Model\Mail\EmailConfig;
use MageMe\EUWithdrawal\Model\Mail\EmailDataResolver;
use Magento\Framework\App\Area;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Mail\TransportInterface;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Builds the durable-medium waiver-confirmation email (Art. 11a(4) CRD).
 * Wraps the legal-text snapshot in the shared `Block\Email\Layout` so the
 * email matches the rest of the customer-facing family (header, USP strip,
 * social footer). Template ID and BCC are merchant-configurable via
 * `notifications/waiver_confirmation` admin section.
 */
class WaiverEmailRenderer
{
    public const TPL_MERCHANT = 'mageme_eu_withdrawal_waiver_confirmation_merchant_bcc';

    /**
     * Constructor.
     *
     * @param TransportBuilder $transportBuilder
     * @param LayoutFactory $layoutFactory
     * @param EmailConfig $emailConfig
     * @param EmailDataResolver $emailData
     * @param StoreManagerInterface $storeManager
     * @param UrlInterface $urlBuilder
     */
    public function __construct(
        private readonly TransportBuilder $transportBuilder,
        private readonly LayoutFactory $layoutFactory,
        private readonly EmailConfig $emailConfig,
        private readonly EmailDataResolver $emailData,
        private readonly StoreManagerInterface $storeManager,
        private readonly UrlInterface $urlBuilder,
    ) {
    }

    /**
     * Render consumer.
     *
     * @param WaiverConfirmationDto $dto
     * @param int $storeId
     * @return TransportInterface
     */
    public function renderConsumer(WaiverConfirmationDto $dto, int $storeId): TransportInterface
    {
        $template = $this->emailConfig->getTemplate(EmailConfig::TYPE_WAIVER_CONFIRMATION, $storeId);
        if ($template === '') {
            $template = 'mageme_eu_withdrawal_digital_waiver_email_template';
        }
        $vars = $this->buildVars($dto, $storeId);
        $bccList = $this->emailConfig->getBccList(EmailConfig::TYPE_WAIVER_CONFIRMATION, $storeId);
        return $this->build($template, $storeId, $dto->customerEmail, $vars, $bccList);
    }

    /**
     * Render merchant bcc.
     *
     * @param WaiverConfirmationDto $dto
     * @param int $storeId
     * @param string $to
     * @return TransportInterface
     */
    public function renderMerchantBcc(WaiverConfirmationDto $dto, int $storeId, string $to): TransportInterface
    {
        $vars = $this->buildVars($dto, $storeId);
        return $this->build(self::TPL_MERCHANT, $storeId, $to, $vars, []);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildVars(WaiverConfirmationDto $dto, int $storeId): array
    {
        $layout = $this->layoutFactory->create();
        $layout->setData('store_id', $storeId);
        $layout->setData(
            'disclaimer',
            (string) __('This is your durable-medium copy of the waiver you signed at checkout (Art. 11a(4) Directive 2011/83/EU).'),
        );

        $store = $this->storeManager->getStore($storeId);
        $orderUrl = rtrim($store->getBaseUrl(), '/') . '/sales/order/view/order_id/' . $dto->orderId . '/';

        $waiver = $dto->toArray();
        $waiver['items_html'] = $this->buildItemsHtml($dto, $storeId);
        $waiver['consent_at_formatted'] = $this->emailData->formatDateTimeUtc($dto->consentAt);
        $waiver['ack_at_formatted'] = $this->emailData->formatDateTimeUtc($dto->ackAt);
        // Reference shows e.g. "WV-7-1730131989" — order id + epoch from consent_at
        $waiver['ip'] = $waiver['ip'] ?? '—';
        $waiver['text_hash_short'] = $this->shortHash($dto);

        return [
            'subject_text'     => (string) __('Digital content waiver confirmation — Order %1', (string) ($waiver[WaiverConfirmationDto::ORDER_INCREMENT_ID] ?? '')),
            'waiver'           => $waiver,
            'view_url'         => $orderUrl,
            'email_header_html' => $layout->renderHeader(),
            'email_footer_html' => $layout->renderFooter(),
        ];
    }

    /**
     * Build items html.
     *
     * @param WaiverConfirmationDto $dto
     * @param int $storeId
     * @return string
     */
    private function buildItemsHtml(WaiverConfirmationDto $dto, int $storeId): string
    {
        $rows = '';
        foreach ($dto->items as $item) {
            $name = htmlspecialchars((string) $item->name, ENT_QUOTES, 'UTF-8');
            $sku = htmlspecialchars((string) $item->sku, ENT_QUOTES, 'UTF-8');
            $rows .= '<tr><td style="padding:10px 12px;border-bottom:1px solid #f1f3f5;font-family:Helvetica,Arial,sans-serif;font-size:13px;color:#1a1a1a;">'
                . '<div style="font-weight:600;">' . $name . '</div>'
                . '<div style="font-size:11px;color:#6b7280;">' . $sku . '</div>'
                . '</td>'
                . '<td align="right" style="padding:10px 12px;border-bottom:1px solid #f1f3f5;font-family:Helvetica,Arial,sans-serif;font-size:13px;color:#374151;">'
                . '<span style="display:inline-block;background:#dcfce7;color:#15803d;border-radius:12px;padding:2px 8px;font-size:11px;font-weight:600;">'
                . htmlspecialchars((string) __('Waived'), ENT_QUOTES, 'UTF-8')
                . '</span>'
                . '</td></tr>';
        }
        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" '
            . 'style="border:1px solid #e5e7eb;border-radius:4px;border-collapse:separate;">' . $rows . '</table>';
    }

    /**
     * Short hash.
     *
     * @param WaiverConfirmationDto $dto
     * @return string
     */
    private function shortHash(WaiverConfirmationDto $dto): string
    {
        // Display-only badge: abbreviated SHA-256 of the accepted legal-text
        // snapshots. Not the stored waiver_text_hash, which also covers
        // locale + jurisdiction (WaiverTextHasher) — never compare the two.
        $payload = $dto->consentSnapshot . "\n\n---\n\n" . $dto->ackSnapshot;
        $hash = hash('sha256', $payload);
        return substr($hash, 0, 8) . '…' . substr($hash, -5);
    }

    /**
     * @param array<string, mixed> $vars
     * @param string[] $bccList
     */
    private function build(string $template, int $storeId, string $to, array $vars, array $bccList): TransportInterface
    {
        try {
            $this->transportBuilder
                ->setTemplateIdentifier($template)
                ->setTemplateOptions(['area' => Area::AREA_FRONTEND, 'store' => $storeId])
                ->setTemplateVars($vars)
                ->setFromByScope($this->emailConfig->getIdentity(EmailConfig::TYPE_WAIVER_CONFIRMATION, $storeId), $storeId)
                ->addTo($to);
            foreach ($bccList as $bcc) {
                $this->transportBuilder->addBcc($bcc);
            }
            return $this->transportBuilder->getTransport();
        } catch (\Throwable $t) {
            throw new LocalizedException(__('Waiver email render failed: %1', $t->getMessage()), $t);
        }
    }
}
