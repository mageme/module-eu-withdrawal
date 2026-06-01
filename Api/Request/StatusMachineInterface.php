<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Api\Request;

use MageMe\EUWithdrawal\Api\Data\RequestInterface;
use MageMe\EUWithdrawal\Model\Request\Exception\DenialReasonRequiredException;
use MageMe\EUWithdrawal\Model\Request\Exception\InvalidTransitionException;

interface StatusMachineInterface
{
    /**
     * Apply a status transition with audit-emit semantics.
     *
     * @param array{admin_id:string, denial_reason?:string, note?:string, ip?:string, user_agent?:string} $context
     *
     * @throws InvalidTransitionException     Transition from current to $toStatus not permitted
     * @throws DenialReasonRequiredException  Specific case: submitted→denied without denial_reason ≥ 10 chars
     */
    public function transition(RequestInterface $request, string $toStatus, array $context): RequestInterface;
}
