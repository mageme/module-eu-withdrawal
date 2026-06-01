<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class ContractType implements OptionSourceInterface
{
    public const PHYSICAL_GOODS = 'physical_goods';
    public const DIGITAL_CONTENT = 'digital_content';
    public const DIGITAL_SERVICE = 'digital_service';
    public const FINANCIAL = 'financial';

    /**
     * To option array.
     *
     * @return array
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => self::PHYSICAL_GOODS,  'label' => __('Physical goods')],
            ['value' => self::DIGITAL_CONTENT, 'label' => __('Digital content (non-tangible)')],
            ['value' => self::DIGITAL_SERVICE, 'label' => __('Digital service (SaaS / subscription)')],
            ['value' => self::FINANCIAL,       'label' => __('Financial services (Enterprise tier)')],
        ];
    }
}
