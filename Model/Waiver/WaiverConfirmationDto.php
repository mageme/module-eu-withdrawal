<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Waiver;

class WaiverConfirmationDto
{
    /** toArray() keys. */
    public const ORDER_ID           = 'order_id';
    public const ORDER_INCREMENT_ID = 'order_increment_id';
    public const CUSTOMER_NAME      = 'customer_name';
    public const CUSTOMER_EMAIL     = 'customer_email';
    public const ITEMS              = 'items';
    public const CONSENT_SNAPSHOT   = 'consent_snapshot';
    public const ACK_SNAPSHOT       = 'ack_snapshot';
    public const CONSENT_AT         = 'consent_at';
    public const ACK_AT             = 'ack_at';
    public const WAIVER_REFERENCE   = 'waiver_reference';
    public const LOCALE             = 'locale';
    public const DOWNLOAD_URL       = 'download_url';

    /**
     * @param WaiverConfirmationItem[] $items
     */
    public function __construct(
        public readonly int $orderId,
        public readonly string $orderIncrementId,
        public readonly string $customerName,
        public readonly string $customerEmail,
        public readonly array $items,
        public readonly string $consentSnapshot,
        public readonly string $ackSnapshot,
        public readonly string $consentAt,
        public readonly string $ackAt,
        public readonly string $waiverReference,
        public readonly string $locale,
        public readonly ?string $downloadUrl,
    ) {
    }

    /**
     * To array.
     *
     * @return array
     */
    public function toArray(): array
    {
        $items = array_map(fn(WaiverConfirmationItem $i) => $i->toArray(), $this->items);
        usort(
            $items,
            fn($a, $b) => $a[WaiverConfirmationItem::ORDER_ITEM_ID] <=> $b[WaiverConfirmationItem::ORDER_ITEM_ID],
        );
        return [
            self::ORDER_ID => $this->orderId,
            self::ORDER_INCREMENT_ID => $this->orderIncrementId,
            self::CUSTOMER_NAME => $this->customerName,
            self::CUSTOMER_EMAIL => $this->customerEmail,
            self::ITEMS => $items,
            self::CONSENT_SNAPSHOT => $this->consentSnapshot,
            self::ACK_SNAPSHOT => $this->ackSnapshot,
            self::CONSENT_AT => $this->consentAt,
            self::ACK_AT => $this->ackAt,
            self::WAIVER_REFERENCE => $this->waiverReference,
            self::LOCALE => $this->locale,
            self::DOWNLOAD_URL => $this->downloadUrl,
        ];
    }
}
