<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Api\Data;

interface CreditmemoIssueResultInterface
{
    public function getOutcome(): string;

    public function getCreditmemoId(): ?int;

    public function getReason(): ?string;
}
