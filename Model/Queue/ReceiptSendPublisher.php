<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Queue;

use Magento\Framework\MessageQueue\PublisherInterface;

class ReceiptSendPublisher
{
    public const TOPIC = 'eu_withdrawal.receipt.send';

    /**
     * Constructor.
     *
     * @param PublisherInterface $publisher
     */
    public function __construct(
        private readonly PublisherInterface $publisher,
    ) {
    }

    /**
     * Publish.
     *
     * @param int $requestId
     * @return string
     */
    public function publish(int $requestId): string
    {
        $messageId = bin2hex(random_bytes(16));
        $this->publisher->publish(self::TOPIC, $requestId);
        return $messageId;
    }
}
