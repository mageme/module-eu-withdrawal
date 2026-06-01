<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Block\Withdraw;

use MageMe\EUWithdrawal\Model\Waiver\WaiverEventReader;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;

class WaiverReasonRenderer extends Template
{
    private int $orderId = 0;
    private int $orderItemId = 0;

    /**
     * Constructor.
     *
     * @param Context $context
     * @param WaiverEventReader $reader
     * @param array $data
     */
    public function __construct(
        Context $context,
        private readonly WaiverEventReader $reader,
        array $data = [],
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Set order context.
     *
     * @param int $orderId
     * @param int $orderItemId
     * @return self
     */
    public function setOrderContext(int $orderId, int $orderItemId): self
    {
        $this->orderId = $orderId;
        $this->orderItemId = $orderItemId;
        return $this;
    }

    /**
     * Get reason.
     *
     * @return ?string
     */
    public function getReason(): ?string
    {
        if ($this->orderId <= 0 || $this->orderItemId <= 0) {
            return null;
        }
        if (!$this->reader->hasPerformanceStarted($this->orderId, $this->orderItemId)) {
            return null;
        }
        $events = $this->reader->findEventsForOrder($this->orderId)[$this->orderItemId] ?? [];
        foreach ($events as $e) {
            if (($e['event_type'] ?? null) === WaiverEventReader::EVT_PERF) {
                return (string) __(
                    'Withdrawal right lost (Art. 16(m) CRD) — digital performance started on %1.',
                    (string) ($e['created_at'] ?? '')
                );
            }
        }
        return null;
    }
}
