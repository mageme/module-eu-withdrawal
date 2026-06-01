<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\ResourceModel\WaiverEvent;

use MageMe\EUWithdrawal\Model\ResourceModel\WaiverEvent as WaiverEventResource;
use MageMe\EUWithdrawal\Model\Waiver\WaiverEvent as WaiverEventModel;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    protected $_idFieldName = 'event_id';

    /**
     * Construct.
     *
     * @return void
     */
    protected function _construct(): void
    {
        $this->_init(WaiverEventModel::class, WaiverEventResource::class);
    }
}
