<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Waiver;

use MageMe\EUWithdrawal\Exception\WaiverMissingException;
use MageMe\EUWithdrawal\Service\DigitalContentDetector;
use Magento\Sales\Model\Order;
use Magento\Store\Model\StoreManagerInterface;

class WaiverConfirmationBuilder
{
    /**
     * Constructor.
     *
     * @param WaiverEventReader $reader
     * @param WaiverTextResolver $resolver
     * @param DigitalContentDetector $detector
     * @param StoreManagerInterface $storeManager
     * @param WaiverReference $waiverReference
     */
    public function __construct(
        private readonly WaiverEventReader $reader,
        private readonly WaiverTextResolver $resolver,
        private readonly DigitalContentDetector $detector,
        private readonly StoreManagerInterface $storeManager,
        private readonly WaiverReference $waiverReference,
    ) {
    }

    /**
     * Build.
     *
     * @param Order $order
     * @return WaiverConfirmationDto
     */
    public function build(Order $order): WaiverConfirmationDto
    {
        $digital = $this->detector->filterDigitalItems($order->getAllVisibleItems());
        if (empty($digital)) {
            throw new WaiverMissingException(
                __('No digital items on order %1', $order->getIncrementId())
            );
        }

        $orderId = (int) $order->getEntityId();
        $grouped = $this->reader->findEventsForOrder($orderId);
        $locale = (string) $this->storeManager->getStore($order->getStoreId())
            ->getConfig('general/locale/code');
        $jurisdiction = strtoupper(substr(
            (string) ($order->getBillingAddress()?->getCountryId() ?? ''),
            0,
            2
        ));
        // Durable medium: reproduce the exact text the customer consented to
        // (frozen in the waiver_event snapshot); live resolve is the legacy fallback.
        $persistedSnapshot = $this->firstSnapshot($grouped);
        if ($persistedSnapshot !== null) {
            [$consentText, $ackText] = $this->splitSnapshot($persistedSnapshot);
        } else {
            $texts = $this->resolver->resolve($locale, $jurisdiction !== '' ? $jurisdiction : '__eu_generic__');
            $consentText = (string) $texts['consent'];
            $ackText = (string) $texts['acknowledgment'];
        }

        $items = [];
        $consentAt = '';
        $ackAt = '';
        foreach ($digital as $item) {
            $events = $grouped[(int) $item->getItemId()] ?? [];
            if ($consentAt === '') {
                $consentAt = $this->eventTs($events, WaiverEventReader::EVT_CONSENT) ?? '';
            }
            if ($ackAt === '') {
                $ackAt = $this->eventTs($events, WaiverEventReader::EVT_LOSS) ?? '';
            }
            $items[] = new WaiverConfirmationItem(
                orderItemId: (int) $item->getItemId(),
                sku: (string) $item->getSku(),
                name: (string) $item->getName(),
                price: (string) $item->getPrice(),
                productType: (string) $item->getProductType(),
            );
        }
        usort($items, fn (WaiverConfirmationItem $a, WaiverConfirmationItem $b) => $a->orderItemId <=> $b->orderItemId);

        $referenceSeed = $consentAt !== '' ? $consentAt : gmdate('c');

        return new WaiverConfirmationDto(
            orderId: $orderId,
            orderIncrementId: (string) $order->getIncrementId(),
            customerName: trim((string) $order->getCustomerFirstname() . ' ' . (string) $order->getCustomerLastname()),
            customerEmail: (string) $order->getCustomerEmail(),
            items: $items,
            consentSnapshot: $consentText,
            ackSnapshot: $ackText,
            consentAt: $consentAt,
            ackAt: $ackAt,
            waiverReference: $this->waiverReference->generate($orderId, $referenceSeed),
            locale: $locale,
            downloadUrl: null,
        );
    }

    /**
     * @param array<int, list<array<string,mixed>>> $grouped
     */
    private function firstSnapshot(array $grouped): ?string
    {
        foreach ($grouped as $events) {
            foreach ($events as $e) {
                $snap = (string) ($e['waiver_text_snapshot'] ?? '');
                if ($snap !== '') {
                    return $snap;
                }
            }
        }
        return null;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitSnapshot(string $snapshot): array
    {
        $parts = explode("\n\n---\n\n", $snapshot, 2);
        return [$parts[0], $parts[1] ?? ''];
    }

    /** @param list<array<string,mixed>> $events */
    private function eventTs(array $events, string $eventType): ?string
    {
        foreach ($events as $e) {
            if (($e['event_type'] ?? null) === $eventType) {
                return (string) ($e['created_at'] ?? '');
            }
        }
        return null;
    }
}
