<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\ViewModel;

use MageMe\EUWithdrawal\Model\Frontend\SelectionModeConfig;
use Magento\Framework\View\Element\Block\ArgumentInterface;

class SelectionMode implements ArgumentInterface
{
    public function __construct(private readonly SelectionModeConfig $config)
    {
    }

    public function isFullOrderMode(): bool
    {
        return $this->config->isFullOrderMode();
    }
}
