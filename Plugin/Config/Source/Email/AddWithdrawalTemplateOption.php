<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Plugin\Config\Source\Email;

use Magento\Config\Model\Config\Source\Email\Template as TemplateSource;
use Magento\Email\Model\Template\Config as EmailConfig;

class AddWithdrawalTemplateOption
{
    private const PATH_TEMPLATE_MAP = [
        'sales_email/order/template'          => 'mageme_eu_withdrawal_sales_email_order_template',
        'sales_email/order/guest_template'    => 'mageme_eu_withdrawal_sales_email_order_guest_template',
        'sales_email/shipment/template'       => 'mageme_eu_withdrawal_sales_email_shipment_template',
        'sales_email/shipment/guest_template' => 'mageme_eu_withdrawal_sales_email_shipment_guest_template',
    ];

    /**
     * Constructor.
     *
     * @param EmailConfig $emailConfig
     */
    public function __construct(
        private readonly EmailConfig $emailConfig,
    ) {
    }

    /**
     * Surface the module's withdrawal-CTA email template as a selectable option
     * in its matching sales-email dropdown (a file-registered template is
     * otherwise not listed there).
     *
     * @param TemplateSource $subject
     * @param array $result
     * @return array
     */
    public function afterToOptionArray(TemplateSource $subject, array $result): array
    {
        $templateId = self::PATH_TEMPLATE_MAP[$subject->getPath()] ?? null;
        if ($templateId === null) {
            return $result;
        }
        $result[] = ['value' => $templateId, 'label' => $this->emailConfig->getTemplateLabel($templateId)];
        return $result;
    }
}
