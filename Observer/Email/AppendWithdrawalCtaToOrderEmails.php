<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Observer\Email;

use MageMe\EUWithdrawal\Api\Email\WithdrawalLinkResolverInterface;
use MageMe\EUWithdrawal\Model\Geo\CountryScope;
use Magento\Framework\DataObject;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Api\Data\InvoiceInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\ShipmentInterface;
use Psr\Log\LoggerInterface;

/**
 * Sets the `withdrawal_link_url` template var on outgoing order / shipment
 * emails. The URL is resolved via `WithdrawalLinkResolverInterface` and
 * rendered into the CTA card by the email template's `{{block}}` directive.
 */
class AppendWithdrawalCtaToOrderEmails implements ObserverInterface
{
    /**
     * Constructor.
     *
     * @param WithdrawalLinkResolverInterface $linkResolver
     * @param LoggerInterface $logger
     * @param CountryScope $countryScope
     */
    public function __construct(
        private readonly WithdrawalLinkResolverInterface $linkResolver,
        private readonly LoggerInterface $logger,
        private readonly CountryScope $countryScope,
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
        $transport = $observer->getData('transportObject');
        if (!$transport instanceof DataObject) {
            return;
        }

        $order = $this->resolveOrderFromTransport($transport);
        if ($order === null) {
            return;
        }

        $orderEntityId = (int) $order->getEntityId();
        if ($orderEntityId <= 0) {
            return;
        }

        if (!$this->countryScope->orderInScope($order)) {
            return;
        }

        try {
            $storeId = (int) $order->getStoreId();
            $url = $this->linkResolver->resolveForOrder($orderEntityId, $storeId > 0 ? $storeId : null);
            $transport->setData('withdrawal_link_url', $url);
        } catch (\Throwable $e) {
            $this->logger->error(
                'Failed to resolve EU withdrawal CTA URL for order email: ' . $e->getMessage(),
                ['exception' => $e, 'order_entity_id' => $orderEntityId],
            );
        }
    }

    /**
     * Resolve order from transport.
     *
     * @param DataObject $transport
     * @return ?OrderInterface
     */
    private function resolveOrderFromTransport(DataObject $transport): ?OrderInterface
    {
        $order = $transport->getData('order');
        if ($order instanceof OrderInterface) {
            return $order;
        }
        $shipment = $transport->getData('shipment');
        if ($shipment instanceof ShipmentInterface) {
            $viaShipment = $shipment->getOrder();
            return $viaShipment instanceof OrderInterface ? $viaShipment : null;
        }
        $invoice = $transport->getData('invoice');
        if ($invoice instanceof InvoiceInterface) {
            $viaInvoice = $invoice->getOrder();
            return $viaInvoice instanceof OrderInterface ? $viaInvoice : null;
        }
        return null;
    }
}
