<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Waiver;

class WaiverTextHasher
{
    /**
     * Hash.
     *
     * @param string $consent
     * @param string $ack
     * @param string $locale
     * @param string $jurisdiction
     * @return string
     */
    public function hash(string $consent, string $ack, string $locale, string $jurisdiction): string
    {
        return hash('sha256', $consent . '|' . $ack . '|' . $locale . '|' . $jurisdiction);
    }

    /**
     * Equals.
     *
     * @param string $a
     * @param string $b
     * @return bool
     */
    public function equals(string $a, string $b): bool
    {
        if (strlen($a) !== 64 || strlen($b) !== 64) {
            return false;
        }
        return hash_equals($a, $b);
    }
}
