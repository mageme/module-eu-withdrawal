<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Observer;

use MageMe\EUWithdrawal\Api\RequestRepositoryInterface;
use MageMe\EUWithdrawal\Controller\Adminhtml\Request\StartCreditMemo;
use Magento\Backend\Model\Session as BackendSession;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;

/**
 * Listens on `sales_order_creditmemo_save_after`. When the admin started
 * the credit memo flow from a withdrawal request (via the "Issue Credit
 * Memo" button on the request edit page), `StartCreditMemo` stashed the
 * request_id in the admin session. This observer reads it back, links the
 * newly-saved credit memo's id to `mm_eu_withdrawal_request.refund_creditmemo_id`,
 * then clears the session bag so a later unrelated credit memo doesn't
 * get linked to the same request.
 *
 * The link is best-effort: if the request load fails or the stash is
 * empty (admin issued the credit memo through the standard order flow,
 * not through our button), the observer no-ops silently.
 */
class LinkCreditMemoToRequest implements ObserverInterface
{
    /**
     * Constructor.
     *
     * @param BackendSession $backendSession
     * @param RequestRepositoryInterface $requestRepository
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly BackendSession $backendSession,
        private readonly RequestRepositoryInterface $requestRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Execute.
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        $requestId = (int) $this->backendSession->getData(StartCreditMemo::SESSION_KEY, true);
        if ($requestId <= 0) {
            return;
        }
        $creditmemo = $observer->getEvent()->getCreditmemo();
        if (!$creditmemo) {
            return;
        }
        $creditmemoId = (int) $creditmemo->getEntityId();
        if ($creditmemoId <= 0) {
            return;
        }
        try {
            $request = $this->requestRepository->get($requestId);
            if ((int) $creditmemo->getOrderId() !== (int) $request->getOrderId()) {
                // Stale session bag: this credit memo belongs to a different order
                // than the withdrawal request the admin started from. Do not link.
                $this->logger->warning(
                    'EUWithdrawal: creditmemo order does not match the withdrawal request order; skipping link.',
                    [
                        'request_id' => $requestId,
                        'creditmemo_id' => $creditmemoId,
                        'creditmemo_order_id' => (int) $creditmemo->getOrderId(),
                        'request_order_id' => (int) $request->getOrderId(),
                    ],
                );
                return;
            }
            $request->setData('refund_creditmemo_id', $creditmemoId);
            $this->requestRepository->save($request);
        } catch (\Throwable $t) {
            $this->logger->warning(
                'EUWithdrawal: failed to link creditmemo to request: ' . $t->getMessage(),
                ['request_id' => $requestId, 'creditmemo_id' => $creditmemoId],
            );
        }
    }
}
