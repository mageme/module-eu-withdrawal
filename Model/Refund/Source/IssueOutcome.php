<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Refund\Source;

class IssueOutcome
{
    public const ISSUED = 'issued';
    public const ROUTED_TO_MANUAL = 'routed_to_manual';
    public const ALREADY_DONE = 'already_done';
    public const FAILED = 'failed';
}
