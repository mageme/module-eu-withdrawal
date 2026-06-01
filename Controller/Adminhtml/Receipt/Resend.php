<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Controller\Adminhtml\Receipt;

use MageMe\EUWithdrawal\Api\RequestRepositoryInterface;
use MageMe\EUWithdrawal\Model\Queue\ReceiptSendPublisher;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\Exception\NoSuchEntityException;

class Resend extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'MageMe_EUWithdrawal::request_edit';

    public const TABLE_REQUEST = 'mm_eu_withdrawal_request';

    /**
     * Constructor.
     *
     * @param Context $context
     * @param RequestRepositoryInterface $requestRepository
     * @param ReceiptSendPublisher $publisher
     * @param EventManager $eventManager
     * @param ResourceConnection $resource
     */
    public function __construct(
        Context $context,
        private readonly RequestRepositoryInterface $requestRepository,
        private readonly ReceiptSendPublisher $publisher,
        private readonly EventManager $eventManager,
        private readonly ResourceConnection $resource,
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
            return $redirect->setPath('*/request/index');
        }

        if ((int) $request->getRequestId() === 0) {
            $this->messageManager->addErrorMessage((string) __('Request #%1 not found.', $id));
            return $redirect->setPath('*/request/index');
        }

        try {
            // Force-resend: ReceiptSendConsumer early-returns on receipt_status='sent'
            // (terminal-status guard against the hash-mismatch → DLQ cascade). Reset
            // the retry-state columns so the consumer treats this as a fresh send,
            // even when the customer already received the receipt once.
            $conn = $this->resource->getConnection();
            $conn->update(
                $this->resource->getTableName(self::TABLE_REQUEST),
                [
                    'receipt_status'        => 'pending',
                    'receipt_send_attempts' => 0,
                    'receipt_next_send_at'  => null,
                    'receipt_last_error'    => null,
                ],
                ['request_id = ?' => $id],
            );

            $messageId = $this->publisher->publish($id);
            $this->eventManager->dispatch('mageme_eu_withdrawal_audit_receipt_queued', [
                'request_id' => $id,
                'topic'      => ReceiptSendPublisher::TOPIC,
                'message_id' => $messageId,
                'resent_by'  => (string) $this->_auth->getUser()?->getId(),
            ]);
            $this->messageManager->addSuccessMessage(
                (string) __('Receipt email re-queued for request #%1. Delivery will retry asynchronously.', $id),
            );
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage(
                (string) __('Failed to queue receipt resend: %1', $e->getMessage()),
            );
        }

        return $redirect->setPath('*/request/edit', ['request_id' => $id]);
    }
}
