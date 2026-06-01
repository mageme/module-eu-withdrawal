<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Request\Source;

use MageMe\EUWithdrawal\Api\Data\RequestInterface;
use Magento\Framework\Data\OptionSourceInterface;
use Magento\Framework\Phrase;

class Status implements OptionSourceInterface
{
    /**
     * Admin-facing label overrides. Statuses absent here fall back to the
     * humanized status code.
     */
    private const LABELS = [
        RequestInterface::STATUS_PENDING => 'Pending',
    ];

    /**
     * To option array.
     *
     * @return array
     */
    public function toOptionArray(): array
    {
        $options = [];
        foreach (RequestInterface::ALL_STATUSES as $status) {
            $options[] = [
                'value' => $status,
                'label' => self::labelFor($status),
            ];
        }
        return $options;
    }

    /**
     * Admin-facing label for a request status.
     *
     * @param string $status
     * @return Phrase
     */
    public static function labelFor(string $status): Phrase
    {
        return __(self::LABELS[$status] ?? ucwords(str_replace('_', ' ', $status)));
    }
}
