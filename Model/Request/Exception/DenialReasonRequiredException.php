<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Request\Exception;

use Magento\Framework\Phrase;

class DenialReasonRequiredException extends InvalidTransitionException
{
    public function __construct()
    {
        \Magento\Framework\Exception\LocalizedException::__construct(
            new Phrase('Denying a withdrawal request requires a legal basis of at least 10 characters.')
        );
    }
}
