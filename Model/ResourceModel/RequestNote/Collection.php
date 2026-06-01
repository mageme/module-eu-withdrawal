<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\ResourceModel\RequestNote;

use MageMe\EUWithdrawal\Model\RequestNote\RequestNote as RequestNoteModel;
use MageMe\EUWithdrawal\Model\ResourceModel\RequestNote as RequestNoteResource;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    /**
     * Construct.
     *
     * @return void
     */
    protected function _construct(): void
    {
        $this->_init(RequestNoteModel::class, RequestNoteResource::class);
    }
}
