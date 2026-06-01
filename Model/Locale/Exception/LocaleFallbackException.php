<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Locale\Exception;

use Magento\Framework\Exception\LocalizedException;

/**
 * Thrown when LocaleFallbackResolver cannot resolve any usable locale,
 * including the en_US final fallback. Indicates module misconfiguration
 * (etc/locale_inheritance.xml malformed or missing).
 */
class LocaleFallbackException extends LocalizedException
{
}
