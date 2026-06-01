<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Controller\Withdraw;

use MageMe\Core\Controller\AbstractStorefrontGetPage;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\View\Result\PageFactory;

class Success extends AbstractStorefrontGetPage
{
    /**
     * Constructor.
     *
     * @param Context $context
     * @param PageFactory $pageFactory
     */
    public function __construct(
        Context $context,
        private readonly PageFactory $pageFactory,
    ) {
        parent::__construct($context);
    }

    /**
     * Execute.
     *
     * @return ResultInterface
     */
    public function execute(): ResultInterface
    {
        return $this->pageFactory->create();
    }
}
