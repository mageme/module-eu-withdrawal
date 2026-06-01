<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Waiver;

class PerformanceDetector
{
    public const TRIGGER_DOWNLOADABLE = 'downloadable_link_purchased';
    public const TRIGGER_VIRTUAL_TIMER = 'virtual_timer_0min';
    public const TRIGGER_BUNDLE       = 'bundle_digital_child';

    private const ALLOWED = [
        self::TRIGGER_DOWNLOADABLE,
        self::TRIGGER_VIRTUAL_TIMER,
        self::TRIGGER_BUNDLE,
    ];

    /**
     * Constructor.
     *
     * @param WaiverEventReader $reader
     * @param WaiverEventWriter $writer
     */
    public function __construct(
        private readonly WaiverEventReader $reader,
        private readonly WaiverEventWriter $writer,
    ) {
    }

    /**
     * Mark started.
     *
     * @param int $orderId
     * @param int $orderItemId
     * @param string $triggerCode
     * @return bool
     */
    public function markStarted(int $orderId, int $orderItemId, string $triggerCode): bool
    {
        if (!in_array($triggerCode, self::ALLOWED, true)) {
            throw new \InvalidArgumentException("Unknown performance trigger code: {$triggerCode}");
        }
        if ($this->reader->hasPerformanceStarted($orderId, $orderItemId)) {
            return false;
        }
        return $this->writer->write([
            'order_id' => $orderId,
            'order_item_id' => $orderItemId,
            'event_type' => WaiverEventReader::EVT_PERF,
            'consent_value' => 1,
            'performance_trigger' => $triggerCode,
        ]);
    }
}
