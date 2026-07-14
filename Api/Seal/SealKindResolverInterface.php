<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Api\Seal;

use MageMe\EUWithdrawal\Model\Seal\SealKind;

interface SealKindResolverInterface
{
    /** Deleted / missing product fails closed to SealKind::NONE. */
    public function resolve(int $productId, int $storeId): SealKind;
}
