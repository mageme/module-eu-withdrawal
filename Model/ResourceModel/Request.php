<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class Request extends AbstractDb
{
    /**
     * Construct.
     *
     * @return void
     */
    protected function _construct(): void
    {
        $this->_init('mm_eu_withdrawal_request', 'request_id');
    }
}
