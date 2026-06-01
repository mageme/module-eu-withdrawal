<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Controller\Adminhtml\Request;

use MageMe\EUWithdrawal\Api\Data\RequestInterface;
use MageMe\EUWithdrawal\Api\Request\StatusMachineInterface;
use MageMe\EUWithdrawal\Api\RequestRepositoryInterface;
use MageMe\EUWithdrawal\Model\Request\Exception\InvalidTransitionException;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\NoSuchEntityException;

class Approve extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'MageMe_EUWithdrawal::request_edit';

    /**
     * Constructor.
     *
     * @param Context $context
     * @param RequestRepositoryInterface $requestRepository
     * @param StatusMachineInterface $statusMachine
     */
    public function __construct(
        Context $context,
        private readonly RequestRepositoryInterface $requestRepository,
        private readonly StatusMachineInterface $statusMachine,
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
        $redirect = $this->resultRedirectFactory->create();
        $id = (int) $this->getRequest()->getParam('request_id');

        try {
            $request = $this->requestRepository->get($id);
            $this->statusMachine->transition(
                $request,
                RequestInterface::STATUS_APPROVED,
                [
                    'admin_id'   => (string) $this->_auth->getUser()?->getId(),
                    'ip'         => (string) $this->getRequest()->getClientIp(true),
                    'user_agent' => (string) $this->getRequest()->getHeader('User-Agent'),
                ],
            );
            $this->messageManager->addSuccessMessage((string) __('Request #%1 approved.', $id));
        } catch (NoSuchEntityException) {
            $this->messageManager->addErrorMessage((string) __('Request #%1 not found.', $id));
        } catch (InvalidTransitionException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        }
        return $redirect->setPath('*/request/edit', ['request_id' => $id]);
    }
}
