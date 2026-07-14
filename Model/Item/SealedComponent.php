<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Item;

use MageMe\EUWithdrawal\Model\Seal\SealKind;

/**
 * Seal state of an inert bundle-content child (whole-bundle mode). Presentation
 * only: the render template shows a seal question for this child, and a broken
 * seal excludes the selectable line identified by `lineOrderItemId` — the parent
 * bundle's order-item id.
 */
final class SealedComponent
{
    public function __construct(
        public readonly SealKind $kind,
        public readonly int $lineOrderItemId,
    ) {
    }
}
