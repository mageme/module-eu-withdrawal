<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Notification;

use Magento\Framework\App\Area;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Store\Model\Store;

class DlqAlerter
{
    private const RATE_KEY    = 'eu_withdrawal_dlq_alert_window';
    private const RATE_TTL    = 900; // 15 minutes

    public function __construct(
        private readonly CacheInterface $cache,
        private readonly TransportBuilder $transportBuilder,
        private readonly ScopeConfigInterface $scopeConfig,
    ) {
    }

    /**
     * Alert.
     *
     * @param int $requestId
     * @return void
     */
    public function alert(int $requestId): void
    {
        if ($this->cache->load(self::RATE_KEY)) {
            return;
        }

        $address = (string) $this->scopeConfig->getValue('mageme_eu_withdrawal/notifications/receipt/alert_address');
        if ($address === '') {
            $address = (string) $this->scopeConfig->getValue('trans_email/ident_general/email');
        }
        if ($address === '') {
            return;
        }

        $this->transportBuilder
            ->setTemplateIdentifier('mageme_eu_withdrawal_dlq_alert')
            ->setTemplateOptions([
                'area'  => Area::AREA_ADMINHTML,
                'store' => Store::DEFAULT_STORE_ID,
            ])
            ->setTemplateVars([
                'request_id' => $requestId,
            ])
            ->setFromByScope('general', Store::DEFAULT_STORE_ID)
            ->addTo($address);

        $this->transportBuilder->getTransport()->sendMessage();
        $this->cache->save('1', self::RATE_KEY, [], self::RATE_TTL);
    }
}
