<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Seal;

enum SealKind: string
{
    case NONE = 'none';
    case HYGIENE = 'hygiene';
    case AV = 'av';

    public function isSealed(): bool
    {
        return $this !== self::NONE;
    }

    public function questionKind(): ?string
    {
        return $this === self::NONE ? null : $this->value;
    }
}
