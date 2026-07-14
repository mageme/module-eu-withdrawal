<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Item;

/**
 * One row in a return group. Either a SELECTABLE returnable unit (carries a
 * RemainingItemState, rendered with the existing qty/seal/reason controls) or an
 * INFORMATIONAL row (a whole-bundle content line — inert, never part of the
 * selection state / totals / POST). An informational row may carry a
 * SealedComponent when the content product is sealed, so the template can gate
 * the parent line on its seal.
 */
class ReturnRow
{
    public function __construct(
        public readonly bool $selectable,
        public readonly ?RemainingItemState $state = null,
        public readonly ?int $orderItemId = null,
        public readonly ?string $label = null,
        public readonly ?string $optionLabel = null,
        public readonly bool $priced = false,
        public readonly ?SealedComponent $sealed = null,
    ) {
    }
}
