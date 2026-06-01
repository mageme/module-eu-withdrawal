<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Api;

use MageMe\EUWithdrawal\Api\Data\ItemInterface;

interface ItemRepositoryInterface
{
    /** @return ItemInterface[] */
    public function getByRequest(int $requestId): array;
}
