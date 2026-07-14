<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Seal;

final class BundleSealSubject
{
    public function __construct(
        public readonly int $orderItemId,
        public readonly int $lineOrderItemId,
        public readonly SealKind $kind,
    ) {
    }
}
