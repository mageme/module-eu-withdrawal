<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Config\Source;

use MageMe\EUWithdrawal\Service\DigitalContentDetector;
use Magento\Framework\Data\OptionSourceInterface;

class DigitalDetectionStrategy implements OptionSourceInterface
{
    /** @return array<int, array{value:string, label:\Magento\Framework\Phrase}> */
    public function toOptionArray(): array
    {
        return [
            ['value' => DigitalContentDetector::STRATEGY_DOWNLOADABLE, 'label' => __('Downloadable products')],
            ['value' => DigitalContentDetector::STRATEGY_VIRTUAL,      'label' => __('Virtual products')],
            ['value' => DigitalContentDetector::STRATEGY_BUNDLE,       'label' => __('Bundles containing digital children')],
            ['value' => DigitalContentDetector::STRATEGY_ATTRIBUTE,    'label' => __('Custom attribute flag')],
        ];
    }
}
