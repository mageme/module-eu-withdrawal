<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Exception;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;

/**
 * Thrown by `Model\Period\AnchorResolver` when an order has not yet
 * transitioned into any of the configured delivery-confirmation statuses.
 * Callers (`PeriodRule`, `ItemSelector`) treat that as "withdrawal window
 * still open with no fixed deadline", so the message text is rarely
 * surfaced to the customer — but `LocalizedException` requires a Phrase,
 * so we ship a sensible default.
 */
class NoDeliveryInfoException extends LocalizedException
{
    /**
     * Constructor.
     *
     * @param ?Phrase $phrase
     * @param ?\Throwable $cause
     * @param int $code
     */
    public function __construct(?Phrase $phrase = null, ?\Throwable $cause = null, int $code = 0)
    {
        parent::__construct(
            $phrase ?? new Phrase('No delivery confirmation recorded for this order yet.'),
            $cause,
            $code,
        );
    }
}
