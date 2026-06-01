<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Precontract\Exception;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;

/**
 * Thrown when AnnexIRenderer cannot interpolate one or more required
 * merchant variables — fail-closed so downstream consumers never receive
 * a snapshot with empty merchant_name / merchant_email / etc.
 */
class MissingMerchantVarsException extends LocalizedException
{
    /**
     * Constructor.
     *
     * @param string[] $missing
     */
    public function __construct(array $missing)
    {
        parent::__construct(
            new Phrase(
                'Missing merchant variables for Annex I rendering: %1',
                [implode(', ', $missing)]
            )
        );
    }
}
