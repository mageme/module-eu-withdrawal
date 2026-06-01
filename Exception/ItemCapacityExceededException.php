<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Exception;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;

class ItemCapacityExceededException extends LocalizedException
{
    /**
     * Constructor.
     *
     * @param int $orderItemId
     * @param int $requestedQty
     * @param int $remainingQty
     */
    public function __construct(
        public readonly int $orderItemId,
        public readonly int $requestedQty,
        public readonly int $remainingQty,
    ) {
        parent::__construct(new Phrase(
            'Requested qty %1 exceeds remaining %2 for order item %3',
            [$requestedQty, $remainingQty, $orderItemId],
        ));
    }
}
