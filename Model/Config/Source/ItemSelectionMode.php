<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class ItemSelectionMode implements OptionSourceInterface
{
    public const PER_ITEM = 'per_item';
    public const FULL_ORDER = 'full_order';

    public function toOptionArray(): array
    {
        return [
            ['value' => self::PER_ITEM,   'label' => __('Per item (customer selects items and quantities)')],
            ['value' => self::FULL_ORDER, 'label' => __('Full order (all returnable items, no selection)')],
        ];
    }
}
