<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Request;

use MageMe\EUWithdrawal\Api\Data\RequestInterface;
use MageMe\EUWithdrawal\Api\Request\StatusMachineInterface;
use MageMe\EUWithdrawal\Model\Request\Exception\DenialReasonRequiredException;
use MageMe\EUWithdrawal\Model\Request\Exception\InvalidTransitionException;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Event\ManagerInterface as EventManagerInterface;

class StatusMachine implements StatusMachineInterface
{
    public const AUDIT_EVENT = 'mageme_eu_withdrawal_audit_admin_status_changed';

    private const TABLE = 'mm_eu_withdrawal_request';

    private const TRANSITIONS = [
        // `submitted` is the initial state (RequestCreator writes it directly);
        // the customer may self-cancel and the merchant may approve/deny.
        RequestInterface::STATUS_PENDING => [
            RequestInterface::STATUS_APPROVED,
            RequestInterface::STATUS_DENIED,
            RequestInterface::STATUS_CANCELLED,
        ],
        // approved / denied / cancelled / anonymised are terminal.
    ];

    /**
     * Constructor.
     *
     * @param EventManagerInterface $eventManager
     * @param ResourceConnection $resource
     */
    public function __construct(
        private readonly EventManagerInterface $eventManager,
        private readonly ResourceConnection $resource,
    ) {
    }

    /**
     * Transition.
     *
     * @param RequestInterface $request
     * @param string $toStatus
     * @param array $context
     * @return RequestInterface
     */
    public function transition(RequestInterface $request, string $toStatus, array $context): RequestInterface
    {
        $from = $request->getStatus();
        $allowed = self::TRANSITIONS[$from] ?? [];
        if (!in_array($toStatus, $allowed, true)) {
            throw new InvalidTransitionException($from, $toStatus);
        }
        if ($toStatus === RequestInterface::STATUS_DENIED) {
            $reason = (string) ($context['denial_reason'] ?? '');
            if (strlen(trim($reason)) < 10) {
                throw new DenialReasonRequiredException();
            }
        }

        $connection = $this->resource->getConnection();
        $connection->beginTransaction();
        try {
            // Lock the row and re-read its authoritative status. A concurrent
            // admin action (or a double-click) that already moved the row is
            // detected here, so the transition applies at most once and its side
            // effects (save + audit dispatch) never duplicate.
            $lockedStatus = (string) $connection->fetchOne(
                $connection->select()
                    ->from($this->resource->getTableName(self::TABLE), RequestInterface::STATUS)
                    ->where('request_id = ?', (int) $request->getRequestId())
                    ->forUpdate(true)
            );
            if ($lockedStatus !== $from) {
                throw new InvalidTransitionException($lockedStatus, $toStatus);
            }

            $note = !empty($context['note']) ? (string) $context['note'] : null;
            $legalBasis = !empty($context['denial_reason']) ? (string) $context['denial_reason'] : null;
            $actor = (string) ($context['admin_id'] ?? 'system');

            $request->setStatus($toStatus);
            // First-class request state: the latest status-change reason shown to
            // the consumer (independent of the Pro audit log; the audit event below
            // still records the immutable forensic history).
            $request->setStatusChangeNote($note);
            $request->setStatusChangeLegalBasis($legalBasis);
            $request->setStatusChangeActor($actor);

            // Persist ONLY the status columns via a targeted UPDATE keyed by
            // request_id. A full ORM save would rewrite every column from a model
            // that was loaded before the lock, reverting a concurrent direct-SQL
            // write to receipt_status / acknowledged_at / refund_creditmemo_id.
            $connection->update(
                $this->resource->getTableName(self::TABLE),
                [
                    RequestInterface::STATUS => $toStatus,
                    RequestInterface::STATUS_CHANGE_NOTE => $note,
                    RequestInterface::STATUS_CHANGE_LEGAL_BASIS => $legalBasis,
                    RequestInterface::STATUS_CHANGE_ACTOR => $actor,
                ],
                ['request_id = ?' => (int) $request->getRequestId()],
            );

            $connection->commit();
        } catch (\Throwable $t) {
            $connection->rollBack();
            throw $t;
        }

        $payload = [
            'request_id' => $request->getRequestId(),
            'from'       => $from,
            'to'         => $toStatus,
            'admin_id'   => (string) ($context['admin_id'] ?? 'system'),
        ];
        if (!empty($context['note'])) {
            $payload['note'] = (string) $context['note'];
        }
        if (!empty($context['denial_reason'])) {
            $payload['legal_basis'] = (string) $context['denial_reason'];
        }
        if (!empty($context['ip'])) {
            $payload['ip'] = (string) $context['ip'];
        }
        if (!empty($context['user_agent'])) {
            $payload['user_agent'] = (string) $context['user_agent'];
        }
        if (!empty($context['customer_id'])) {
            $payload['customer_id'] = $context['customer_id'];
        }
        $this->eventManager->dispatch(self::AUDIT_EVENT, $payload);

        return $request;
    }
}
