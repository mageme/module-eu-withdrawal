<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Controller\Index;

use MageMe\Core\Controller\AbstractStorefrontGetPage;
use MageMe\EUWithdrawal\Model\Frontend\IndexStepResolver;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\View\Result\PageFactory;

/**
 * Customer-facing withdrawal landing page served at the bare front_name —
 * `/<vanity-prefix>/`. The page picks one of three step layouts via
 * `IndexStepResolver` (`step1a` for unauthenticated lookup, `step1b` for
 * an authenticated order picker, `step2` once an order has been selected).
 *
 * Layout `mageme_eu_withdrawal_index_index.xml` is the file Magento auto-
 * resolves from this action; it merges the shared
 * `mageme_eu_withdrawal_withdraw_index` parent and the controller appends
 * the step-specific override handle below.
 */
class Index extends AbstractStorefrontGetPage
{
    /**
     * Constructor.
     *
     * @param Context $context
     * @param PageFactory $pageFactory
     * @param IndexStepResolver $stepResolver
     */
    public function __construct(
        Context $context,
        private readonly PageFactory $pageFactory,
        private readonly IndexStepResolver $stepResolver,
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
        $page = $this->pageFactory->create();
        $mode = $this->stepResolver->getMode();
        $page->getLayout()->getUpdate()->addHandle('mageme_eu_withdrawal_withdraw_index_' . $mode);
        $page->getConfig()->getTitle()->set(__('Withdraw from a contract'));
        $page->getConfig()->setMetadata('robots', 'NOINDEX,NOFOLLOW');
        return $page;
    }
}
