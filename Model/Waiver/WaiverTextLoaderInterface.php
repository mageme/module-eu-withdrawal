<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Waiver;

interface WaiverTextLoaderInterface
{
    /** @return array{consent:string,acknowledgment:string}|null */
    public function load(string $locale, string $jurisdiction): ?array;
}
