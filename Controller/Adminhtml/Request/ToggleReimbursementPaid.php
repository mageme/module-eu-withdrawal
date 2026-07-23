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
 * Toggles the manual "reimbursement paid" mark on a request: sets
 * reimbursement_paid_at to now when unset, clears it when set, and records a plain
 * note either way. This covers refunds issued outside the request — offline, bank
 * transfer, external PSP, or a credit memo raised straight from the order — so the
 * grid due-state and the overdue digest stop treating the request as outstanding.
 *
 * A request already linked to a credit memo (via StartCreditMemo) is paid on that
 * evidence alone; the mark is rejected there so a manual flag can never shadow the
 * real refund record. Like withholding, this is a request fact (a column), not a
 * status transition.
 */
class ToggleReimbursementPaid extends Action implements HttpPostActionInterface
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

        if ((int) $request->getRefundCreditmemoId() > 0) {
            $this->messageManager->addErrorMessage(
                (string) __(
                    'Request #%1 is already linked to a credit memo, so its reimbursement is recorded as refunded.',
                    $id,
                ),
            );
            return $redirect->setPath('*/request/edit', ['request_id' => $id]);
        }

        $adminId = (string) ($this->_auth->getUser()?->getId() ?? '');
        $clearing = $request->getReimbursementPaidAt() !== null;

        if ($clearing) {
            $paidAt = null;
            $note = (string) __('Refunded mark cleared; the reimbursement is tracked as outstanding again.');
        } else {
            $paidAt = gmdate('Y-m-d H:i:s');
            $note = (string) __('Reimbursement recorded as refunded outside the request.');
        }

        $connection = $this->resource->getConnection();
        $connection->update(
            $this->resource->getTableName(self::TABLE),
            [RequestInterface::REIMBURSEMENT_PAID_AT => $paidAt],
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
            $clearing
                ? (string) __('Refunded mark for request #%1 cleared.', $id)
                : (string) __('Reimbursement for request #%1 marked as refunded.', $id),
        );
        return $redirect->setPath('*/request/edit', ['request_id' => $id]);
    }
}
