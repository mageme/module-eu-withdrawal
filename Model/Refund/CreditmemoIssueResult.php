<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Refund;

use MageMe\EUWithdrawal\Api\Data\CreditmemoIssueResultInterface;

class CreditmemoIssueResult implements CreditmemoIssueResultInterface
{
    public function __construct(
        private readonly string $outcome,
        private readonly ?int $creditmemoId = null,
        private readonly ?string $reason = null,
    ) {
    }

    public function getOutcome(): string
    {
        return $this->outcome;
    }

    public function getCreditmemoId(): ?int
    {
        return $this->creditmemoId;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }
}
