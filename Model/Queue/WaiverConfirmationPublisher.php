<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Queue;

use Magento\Framework\MessageQueue\PublisherInterface;

class WaiverConfirmationPublisher
{
    public const TOPIC = 'eu_withdrawal.waiver_confirmation.send';

    /**
     * Constructor.
     *
     * @param PublisherInterface $publisher
     */
    public function __construct(private readonly PublisherInterface $publisher)
    {
    }

    /**
     * Publish.
     *
     * @param int $orderId
     * @return void
     */
    public function publish(int $orderId): void
    {
        if ($orderId <= 0) {
            throw new \InvalidArgumentException('orderId must be positive');
        }
        $this->publisher->publish(self::TOPIC, $orderId);
    }
}
