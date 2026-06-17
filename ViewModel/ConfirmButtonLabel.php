<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\ViewModel;

use MageMe\EUWithdrawal\Model\Frontend\FooterLinkLabelResolver;
use Magento\Framework\View\Element\Block\ArgumentInterface;

class ConfirmButtonLabel implements ArgumentInterface
{
    public function __construct(private readonly FooterLinkLabelResolver $labels)
    {
    }

    /**
     * Counsel-signed Art. 11a(3) confirmation label for the current locale.
     * Frozen whitelist string — must not pass through __().
     */
    public function getLabel(): string
    {
        return $this->labels->step2Label();
    }
}
