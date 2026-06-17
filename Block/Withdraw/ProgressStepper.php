<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Block\Withdraw;

use MageMe\EUWithdrawal\Model\Frontend\SelectionModeConfig;
use Magento\Framework\View\Element\Template;

class ProgressStepper extends Template
{
    private const STEPS = [
        1 => ['title' => 'Find order',         'subtitle' => 'Enter your order number and email'],
        2 => ['title' => 'Select items',       'subtitle' => 'Choose items to return (partial withdrawal)'],
        3 => ['title' => 'Review & confirm',   'subtitle' => 'Review your return details and confirm'],
        4 => ['title' => 'Request submitted',  'subtitle' => "We'll confirm your request by email"],
    ];

    private const STEP2_SUBTITLE_FULL_ORDER = 'Review the items to return';

    /**
     * Constructor.
     *
     * @param Template\Context $context
     * @param SelectionModeConfig $selectionMode
     * @param array $data
     */
    public function __construct(
        Template\Context $context,
        private readonly SelectionModeConfig $selectionMode,
        array $data = [],
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Get active step.
     *
     * @return int
     */
    public function getActiveStep(): int
    {
        $raw = (int) $this->getData('active_step');
        if ($raw < 1 || $raw > 4) {
            return 1;
        }
        return $raw;
    }

    /**
     * @return array<int, array{number:int, title:string, subtitle:string, status:string}>
     */
    public function getSteps(): array
    {
        $active = $this->getActiveStep();
        $fullOrder = $this->selectionMode->isFullOrderMode();
        $out = [];
        foreach (self::STEPS as $n => $meta) {
            $status = match (true) {
                $n < $active  => 'done',
                $n === $active => 'active',
                default        => 'upcoming',
            };
            $subtitle = $n === 2 && $fullOrder ? self::STEP2_SUBTITLE_FULL_ORDER : $meta['subtitle'];
            $out[] = ['number' => $n, 'title' => $meta['title'], 'subtitle' => $subtitle, 'status' => $status];
        }
        return $out;
    }
}
