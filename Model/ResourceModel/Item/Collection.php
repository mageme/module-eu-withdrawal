<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\ResourceModel\Item;

use MageMe\EUWithdrawal\Model\Item\Item as ItemModel;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    protected $_idFieldName = 'item_id';

    /**
     * Construct.
     *
     * @return void
     */
    protected function _construct(): void
    {
        $this->_init(ItemModel::class, Item::class);
    }
}
