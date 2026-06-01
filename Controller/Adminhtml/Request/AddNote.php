<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Controller\Adminhtml\Request;

use MageMe\EUWithdrawal\Api\RequestRepositoryInterface;
use MageMe\EUWithdrawal\Model\RequestNote\RequestNoteRepository;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\Exception\NoSuchEntityException;

class AddNote extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'MageMe_EUWithdrawal::request_edit';
    public const NOTE_MAX = 2000;

    /**
     * Constructor.
     *
     * @param Context $context
     * @param RequestRepositoryInterface $requestRepository
     * @param RequestNoteRepository $noteRepository
     * @param EventManager $eventManager
     */
    public function __construct(
        Context $context,
        private readonly RequestRepositoryInterface $requestRepository,
        private readonly RequestNoteRepository $noteRepository,
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
        $isAjax = (bool) $this->getRequest()->getHeader('X-Requested-With');
        $id = (int) $this->getRequest()->getParam('request_id');
        $note = trim((string) $this->getRequest()->getPost('note_text', ''));

        if ($note === '') {
            return $this->respond($isAjax, false, (string) __('Note text is empty.'), $id);
        }
        $note = mb_substr($note, 0, self::NOTE_MAX);

        try {
            $this->requestRepository->get($id);
        } catch (NoSuchEntityException) {
            return $this->respond($isAjax, false, (string) __('Request #%1 not found.', $id), $id);
        }

        $adminUser = $this->_auth->getUser();
        $adminId = (string) ($adminUser?->getId() ?? '');
        $authorType = $adminId !== '' ? 'admin' : 'system';
        $actorName = $adminUser !== null
            ? (trim((string) $adminUser->getName()) ?: (string) $adminUser->getUserName())
            : (string) __('System');

        // First-class Free state (operational store + the source for the UI).
        $this->noteRepository->add($id, $note, $authorType, $adminId !== '' ? $adminId : null);

        // Generic event — when Pro MageMe_EUWithdrawalAudit is installed its
        // OnAdminNoteAdded observer forensically mirrors this into the
        // tamper-evident hash-chained audit log (no dual write here).
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

        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        if ($isAjax) {
            return $this->resultFactory->create(ResultFactory::TYPE_JSON)
                ->setData([
                    'success'    => true,
                    'note_text'  => $note,
                    'created_at' => $now,
                    'actor'      => $actorName,
                    'message'    => (string) __('Note added to request #%1.', $id),
                ]);
        }

        $this->messageManager->addSuccessMessage((string) __('Note added to request #%1.', $id));
        return $this->resultRedirectFactory->create()->setPath('*/request/edit', ['request_id' => $id]);
    }

    /**
     * Respond.
     *
     * @param bool $isAjax
     * @param bool $success
     * @param string $message
     * @param int $id
     * @return ResultInterface
     */
    private function respond(bool $isAjax, bool $success, string $message, int $id): ResultInterface
    {
        if ($isAjax) {
            return $this->resultFactory->create(ResultFactory::TYPE_JSON)
                ->setData(['success' => $success, 'message' => $message]);
        }
        if ($success) {
            $this->messageManager->addSuccessMessage($message);
        } else {
            $this->messageManager->addErrorMessage($message);
        }
        return $this->resultRedirectFactory->create()->setPath('*/request/edit', ['request_id' => $id]);
    }
}
