<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Request\Exception;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;

class InvalidTransitionException extends LocalizedException
{
    /**
     * Constructor.
     *
     * @param string $from
     * @param string $to
     * @param ?\Exception $previous
     */
    public function __construct(string $from, string $to, ?\Exception $previous = null)
    {
        parent::__construct(
            new Phrase('Withdrawal request cannot transition from "%1" to "%2".', [$from, $to]),
            $previous,
        );
    }
}
