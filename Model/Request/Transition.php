<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Request;

class Transition
{
    /**
     * Constructor.
     *
     * @param int $requestId
     * @param string $from
     * @param string $to
     * @param string $adminId
     * @param ?string $note
     * @param ?string $legalBasis
     * @param ?string $ip
     * @param ?string $userAgent
     */
    public function __construct(
        public readonly int $requestId,
        public readonly string $from,
        public readonly string $to,
        public readonly string $adminId,
        public readonly ?string $note = null,
        public readonly ?string $legalBasis = null,
        public readonly ?string $ip = null,
        public readonly ?string $userAgent = null,
    ) {
    }
}
