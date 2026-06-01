<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Exception;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;

class NoEligibleItemsException extends LocalizedException
{
    public function __construct()
    {
        parent::__construct(new Phrase('No eligible items selected for withdrawal'));
    }
}
