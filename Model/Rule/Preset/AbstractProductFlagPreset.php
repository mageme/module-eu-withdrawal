<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Rule\Preset;

use MageMe\EUWithdrawal\Api\Data\EligibilityRequestInterface;
use MageMe\EUWithdrawal\Model\Item\ProductFlagReader;
use Magento\Framework\App\Config\ScopeConfigInterface;

/**
 * Base for presets whose exclusion is driven by a boolean product attribute.
 */
abstract class AbstractProductFlagPreset extends AbstractPreset
{
    /**
     * Constructor.
     *
     * @param ScopeConfigInterface $scopeConfig
     * @param ProductFlagReader $flagReader
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        private readonly ProductFlagReader $flagReader,
    ) {
        parent::__construct($scopeConfig);
    }

    /**
     * Is flag set.
     *
     * @param EligibilityRequestInterface $request
     * @param string $attributeCode
     * @return bool
     */
    protected function isFlagSet(EligibilityRequestInterface $request, string $attributeCode): bool
    {
        return $this->flagReader->isSet($request, $attributeCode);
    }
}
