<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\ResourceModel\Request;

use MageMe\EUWithdrawal\Model\Request\Request as RequestModel;
use MageMe\EUWithdrawal\Model\ResourceModel\Request as RequestResource;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    protected $_idFieldName = 'request_id';

    /**
     * Construct.
     *
     * @return void
     */
    protected function _construct(): void
    {
        $this->_init(RequestModel::class, RequestResource::class);
    }
}
