<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Waiver;

use MageMe\EUWithdrawal\Model\ResourceModel\WaiverEvent as WaiverEventResource;
use Magento\Framework\Model\AbstractModel;

class WaiverEvent extends AbstractModel
{
    /**
     * Construct.
     *
     * @return void
     */
    protected function _construct(): void
    {
        $this->_init(WaiverEventResource::class);
    }
}
