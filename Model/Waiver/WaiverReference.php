<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Waiver;

class WaiverReference
{
    /**
     * Generate.
     *
     * @param int $orderId
     * @param string $consentAtIso8601
     * @return string
     */
    public function generate(int $orderId, string $consentAtIso8601): string
    {
        $digest = hash('sha256', $orderId . '||' . $consentAtIso8601);
        return 'WVR-' . substr($digest, 0, 8);
    }
}
