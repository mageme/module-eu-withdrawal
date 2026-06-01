<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Controller\Withdraw;

use MageMe\EUWithdrawal\Model\Lookup\OrderLookupByIncrementId;
use MageMe\EUWithdrawal\Model\Security\ResponseTimer;
use MageMe\EUWithdrawal\Model\Session as WithdrawalSession;
use MageMe\EUWithdrawal\Api\Token\MagicLinkServiceInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Controller\ResultInterface;

/**
 * Step 1a guest-lookup handler. Verifies that (order_id, email) actually
 * belong together before forwarding the guest to step 2. Always pads the
 * response so success and failure take the same time (anti-enumeration:
 * an attacker probing the endpoint can't tell whether an email-order pair
 * matches). On mismatch we redirect back to step 1a with `?lookup=fail`
 * so the template can surface a single, deliberately-vague banner.
 */
class Lookup implements HttpPostActionInterface
{
    /**
     * Constructor.
     *
     * @param RequestInterface $request
     * @param RedirectFactory $redirectFactory
     * @param OrderLookupByIncrementId $orderLookup
     * @param ResponseTimer $responseTimer
     * @param MagicLinkServiceInterface $magicLinkService
     * @param WithdrawalSession $withdrawalSession
     */
    public function __construct(
        private readonly RequestInterface $request,
        private readonly RedirectFactory $redirectFactory,
        private readonly OrderLookupByIncrementId $orderLookup,
        private readonly ResponseTimer $responseTimer,
        private readonly MagicLinkServiceInterface $magicLinkService,
        private readonly WithdrawalSession $withdrawalSession,
    ) {
    }

    /**
     * Execute.
     *
     * @return ResultInterface
     */
    public function execute(): ResultInterface
    {
        $this->responseTimer->start();
        $redirect = $this->redirectFactory->create();

        $orderId = trim((string) $this->request->getParam('order_id', ''));
        $email = trim((string) $this->request->getParam('email', ''));

        if ($orderId === '' || $email === '') {
            $this->responseTimer->pad(200);
            return $redirect->setPath('withdraw-contract');
        }

        $order = $this->orderLookup->find($orderId);
        $matches = $order !== null
            && strcasecmp((string) $order->getCustomerEmail(), $email) === 0;

        $this->responseTimer->pad(200);

        if ($matches) {
            // Mark the order verified in the guest session. The Pro
            // MagicLinkService also issues a ?t= URL token here (Pro, 30-day default) so
            // the binding survives browser-tab loss; the base module NoOp
            // returns an empty string and the session-only fallback in
            // CustomerIdentityFactory handles subsequent loads.
            $orderEntityId = (int) $order->getEntityId();
            $token = $this->magicLinkService->issueOrReuseForOrder($orderEntityId);
            $this->withdrawalSession->markOrderVerified($orderEntityId);
            // URL carries entity_id (Magento sales/order convention) — keeps
            // BraintreeAdapter::StoreConfigResolver::getStoreId() happy when it
            // reads ?order_id= on every storefront page.
            $query = ['order_id' => $orderEntityId];
            if ($token !== '') {
                $query['t'] = $token;
            }
            return $redirect->setPath('withdraw-contract', ['_query' => $query]);
        }

        return $redirect->setPath(
            'withdraw-contract',
            ['_query' => ['lookup' => 'fail']],
        );
    }
}
