<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Controller\Adminhtml\Request;

use MageMe\EUWithdrawal\Api\Data\RequestInterface;
use MageMe\EUWithdrawal\Api\RequestRepositoryInterface;
use MageMe\EUWithdrawal\Model\RequestNote\RequestNoteRepository;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Toggles the Art. 13(3) lawful-withholding flag on a request's reimbursement
 * obligation: sets reimbursement_withheld_at to now when off, clears it when on,
 * and records a plain note either way. Withholding is a request fact (a column),
 * not a status transition.
 */
class ToggleWithhold extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'MageMe_EUWithdrawal::request_edit';

    private const TABLE = 'mm_eu_withdrawal_request';

    /**
     * Constructor.
     *
     * @param Context $context
     * @param RequestRepositoryInterface $requestRepository
     * @param RequestNoteRepository $noteRepository
     * @param ResourceConnection $resource
     * @param EventManager $eventManager
     */
    public function __construct(
        Context $context,
        private readonly RequestRepositoryInterface $requestRepository,
        private readonly RequestNoteRepository $noteRepository,
        private readonly ResourceConnection $resource,
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
        $redirect = $this->resultRedirectFactory->create();
        $id = (int) $this->getRequest()->getParam('request_id');

        try {
            $request = $this->requestRepository->get($id);
        } catch (NoSuchEntityException) {
            $this->messageManager->addErrorMessage((string) __('Request #%1 not found.', $id));
            return $redirect->setPath('*/request/edit', ['request_id' => $id]);
        }

        $adminId = (string) ($this->_auth->getUser()?->getId() ?? '');
        $lifting = $request->getReimbursementWithheldAt() !== null;

        if ($lifting) {
            $withheldAt = null;
            $note = (string) __('Withholding lifted; the 14-day reimbursement clock resumes.');
        } else {
            $withheldAt = gmdate('Y-m-d H:i:s');
            $note = (string) __('Reimbursement withheld pending return of goods (Art. 13(3)).');
        }

        $connection = $this->resource->getConnection();
        $connection->update(
            $this->resource->getTableName(self::TABLE),
            [RequestInterface::REIMBURSEMENT_WITHHELD_AT => $withheldAt],
            ['request_id = ?' => (int) $request->getRequestId()],
        );

        $this->noteRepository->add(
            $id,
            $note,
            $adminId !== '' ? 'admin' : 'system',
            $adminId !== '' ? $adminId : null,
        );

        $this->eventManager->dispatch(
            'mageme_eu_withdrawal_audit_admin_note_added',
            [
                'request_id' => $id,
                'admin_id'   => $adminId,
                'note_text'  => $note,
                'ip'         => (string) $this->getRequest()->getClientIp(true),
                'user_agent' => (string) $this->getRequest()->getHeader('User-Agent'),
            ],
        );

        $this->messageManager->addSuccessMessage(
            $lifting
                ? (string) __('Reimbursement withholding for request #%1 lifted.', $id)
                : (string) __('Reimbursement for request #%1 withheld.', $id),
        );
        return $redirect->setPath('*/request/edit', ['request_id' => $id]);
    }
}
