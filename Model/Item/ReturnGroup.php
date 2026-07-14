<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Item;

/**
 * An ordered group of return rows. `bundle` groups carry a non-null header label
 * ONLY in per-component mode (the inert bundle header above its selectable
 * children); in whole-bundle mode the parent is itself the first selectable row
 * and `headerLabel` is null. `standalone` groups hold a single selectable row.
 */
class ReturnGroup
{
    public const TYPE_BUNDLE = 'bundle';
    public const TYPE_STANDALONE = 'standalone';

    /** @param ReturnRow[] $rows */
    public function __construct(
        public readonly string $type,
        public readonly ?string $headerLabel,
        public readonly ?string $headerSku,
        public readonly array $rows,
        // The bundle parent's order_item_id — set for a per-component bundle header so
        // the template can render the bundle's own product image next to its name.
        public readonly ?int $headerOrderItemId = null,
    ) {
    }
}
