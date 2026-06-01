<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Controller\Adminhtml\Request;

use MageMe\EUWithdrawal\Api\RequestRepositoryInterface;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Registry;
use Magento\Framework\View\Result\PageFactory;

class Edit extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'MageMe_EUWithdrawal::request_view';

    /**
     * Constructor.
     *
     * @param Context $context
     * @param RequestRepositoryInterface $requestRepository
     * @param Registry $registry
     * @param PageFactory $resultPageFactory
     * @param EventManager $eventManager
     */
    public function __construct(
        Context $context,
        private readonly RequestRepositoryInterface $requestRepository,
        private readonly Registry $registry,
        private readonly PageFactory $resultPageFactory,
        private readonly EventManager $eventManager,
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
        $id = (int) $this->getRequest()->getParam('request_id');
        if ($id <= 0) {
            $this->messageManager->addErrorMessage((string) __('Invalid request id.'));
            return $this->resultRedirectFactory->create()->setPath('*/*/index');
        }
        try {
            $request = $this->requestRepository->get($id);
        } catch (NoSuchEntityException) {
            $this->messageManager->addErrorMessage((string) __('Request #%1 not found.', $id));
            return $this->resultRedirectFactory->create()->setPath('*/*/index');
        }
        $this->registry->register('mageme_eu_withdrawal_current_request', $request);

        // Audit is a Pro concern (MageMe_EUWithdrawalAudit). Free only emits the
        // generic event on every admin open; the "log once per admin" dedup is
        // applied by the Pro OnAdminViewed observer (it owns the audit reader).
        $adminId = (string) $this->_auth->getUser()?->getId();
        $this->eventManager->dispatch(
            'mageme_eu_withdrawal_audit_admin_viewed',
            [
                'request_id' => $id,
                'admin_id'   => $adminId,
                'ip'         => (string) $this->getRequest()->getClientIp(true),
                'user_agent' => (string) $this->getRequest()->getHeader('User-Agent'),
            ],
        );

        $page = $this->resultPageFactory->create();
        $page->setActiveMenu('MageMe_EUWithdrawal::request');
        $display = $request->getIncrementId() ?? sprintf('%09d', $id);
        $page->getConfig()->getTitle()->prepend(__('Withdrawal Request #%1', $display));
        return $page;
    }
}
