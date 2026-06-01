<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Block\Withdraw;

use Magento\Framework\Registry;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use MageMe\EUWithdrawal\Controller\Withdraw\Confirm as ConfirmController;
use MageMe\EUWithdrawal\Model\Lookup\RequestLookup;

class DigitalWaiverDisplay extends Template
{
    public const ART_16M = 'Art. 16(m)';

    /**
     * Constructor.
     *
     * @param Context $context
     * @param Registry $registry
     * @param RequestLookup $lookup
     * @param array $data
     */
    public function __construct(
        Context $context,
        private readonly Registry $registry,
        private readonly RequestLookup $lookup,
        array $data = [],
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Has digital waiver items.
     *
     * @return bool
     */
    public function hasDigitalWaiverItems(): bool
    {
        $requestId = (int) $this->registry->registry(ConfirmController::REGISTRY_REQUEST_ID);
        if ($requestId <= 0) {
            return false;
        }
        foreach ($this->lookup->findItemsByRequestId($requestId) as $item) {
            if ((string) ($item->exclusion_basis ?? '') === self::ART_16M) {
                return true;
            }
        }
        return false;
    }
}
