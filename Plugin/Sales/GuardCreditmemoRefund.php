<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Plugin\Sales;

use MageMe\EUWithdrawal\Api\RequestRepositoryInterface;
use MageMe\EUWithdrawal\Controller\Adminhtml\Request\StartCreditMemo;
use Magento\Backend\Model\Session as BackendSession;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\CreditmemoManagementInterface;
use Magento\Sales\Api\Data\CreditmemoInterface;

/**
 * Rejects a stale admin credit-memo refund whose withdrawal request has since been
 * recorded as reimbursed — a linked credit memo or a manual paid mark — so an
 * already-open form cannot refund the customer a second time.
 *
 * This runs at CreditmemoManagementInterface::refund, BEFORE the payment gateway is
 * called (online) or the offline memo is committed. A save_before observer would be
 * too late online: core sends the gateway refund before saving the memo, so a throw
 * there rolls back the record while the money has already left. Blocking here stops
 * the payout itself.
 *
 * Scoped by the StartCreditMemo session stash, so it only ever inspects credit memos
 * the admin started from a withdrawal request; every other refund passes straight
 * through. The session is PEEKED, never cleared, so LinkCreditMemoToRequest still
 * links a legitimate first memo. adminhtml only — the headless auto-issue path
 * (cron) sets no stash and is guarded separately by the issuer's row claim.
 */
class GuardCreditmemoRefund
{
    /**
     * Constructor.
     *
     * @param BackendSession $backendSession
     * @param RequestRepositoryInterface $requestRepository
     */
    public function __construct(
        private readonly BackendSession $backendSession,
        private readonly RequestRepositoryInterface $requestRepository,
    ) {
    }

    /**
     * Before refund.
     *
     * @param CreditmemoManagementInterface $subject
     * @param CreditmemoInterface $creditmemo
     * @param bool $offlineRequested
     * @return void
     * @throws LocalizedException
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function beforeRefund(
        CreditmemoManagementInterface $subject,
        CreditmemoInterface $creditmemo,
        $offlineRequested = false,
    ): void {
        $requestId = (int) $this->backendSession->getData(StartCreditMemo::SESSION_KEY);
        if ($requestId <= 0) {
            return;
        }
        try {
            $request = $this->requestRepository->get($requestId);
        } catch (\Throwable) {
            return;
        }
        if ((int) $creditmemo->getOrderId() !== (int) $request->getOrderId()) {
            return;
        }
        if ($request->getRefundCreditmemoId() === null && $request->getReimbursementPaidAt() === null) {
            return;
        }
        throw new LocalizedException(
            __(
                'Withdrawal request #%1 is already recorded as reimbursed, so no refund was issued.'
                . ' Reload the request; if you really must refund again, clear its paid mark first.',
                $requestId,
            ),
        );
    }
}
