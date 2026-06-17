<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Controller\Index;

use MageMe\Core\Controller\AbstractStorefrontGetPage;
use MageMe\EUWithdrawal\Model\Customer\CustomerIdentityFactory;
use MageMe\EUWithdrawal\Model\Frontend\IndexStepResolver;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\View\Result\PageFactory;
use Magento\Sales\Api\OrderRepositoryInterface;

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
     * @param OrderRepositoryInterface $orderRepository
     * @param CustomerIdentityFactory $identityFactory
     */
    public function __construct(
        Context $context,
        private readonly PageFactory $pageFactory,
        private readonly IndexStepResolver $stepResolver,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly CustomerIdentityFactory $identityFactory,
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
        $gate = $this->guardOrderAccess();
        if ($gate !== null) {
            return $gate;
        }

        $page = $this->pageFactory->create();
        $mode = $this->stepResolver->getMode();
        $page->getLayout()->getUpdate()->addHandle('mageme_eu_withdrawal_withdraw_index_' . $mode);
        $page->getConfig()->getTitle()->set(__('Withdraw from a contract'));
        $page->getConfig()->setMetadata('robots', 'NOINDEX,NOFOLLOW');
        return $page;
    }

    /**
     * Access gate for a `?order_id=` the visitor may not view:
     *  - a logged-in customer asking for an order that is not theirs gets a 404
     *    (the order's existence is not disclosed);
     *  - a guest who has not verified the order (no magic-link token, no Lookup
     *    session binding) is redirected to step 1a to search for their order.
     * Own orders, verified guests and the bare landing pass through (null).
     *
     * @return ?ResultInterface
     */
    private function guardOrderAccess(): ?ResultInterface
    {
        $orderId = trim((string) $this->getRequest()->getParam('order_id', ''));
        if ($orderId === '' || !ctype_digit($orderId)) {
            return null;
        }

        $identity = $this->identityFactory->create();
        try {
            $order = $this->orderRepository->get((int) $orderId);
        } catch (NoSuchEntityException | InputException) {
            $order = null;
        }
        if ($order !== null && $identity->canSeeOrder((int) $orderId, $order)) {
            return null;
        }

        if ($identity->isLoggedIn()) {
            return $this->resultFactory->create(ResultFactory::TYPE_FORWARD)->forward('noroute');
        }
        return $this->resultFactory->create(ResultFactory::TYPE_REDIRECT)->setPath('withdraw-contract');
    }
}
