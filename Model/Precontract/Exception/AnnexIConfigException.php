<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Precontract\Exception;

use Magento\Framework\Exception\LocalizedException;

/**
 * Thrown when the Annex I XML for a requested locale is missing or malformed.
 */
class AnnexIConfigException extends LocalizedException
{
}
