<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Customer;

use MageMe\EUWithdrawal\Model\Session as WithdrawalSession;
use MageMe\EUWithdrawal\Api\Token\MagicLinkServiceInterface;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\RequestInterface;

/**
 * Builds a `CustomerIdentity` from the current request. Sources, in order:
 *   1. logged-in customer session → customerId
 *   2. `?t=TOKEN` magic-link query param → boundOrderEntityId
 *   3. guest session verified-order list (set by the Lookup controller after
 *      a successful email+order_id match) → boundOrderEntityId when the URL
 *      carries a matching `?order_id=<entity_id>` but no `?t=` param
 */
class CustomerIdentityFactory
{
    /**
     * Constructor.
     *
     * @param CustomerSession $session
     * @param RequestInterface $request
     * @param MagicLinkServiceInterface $magicLinkService
     * @param WithdrawalSession $withdrawalSession
     */
    public function __construct(
        private readonly CustomerSession $session,
        private readonly RequestInterface $request,
        private readonly MagicLinkServiceInterface $magicLinkService,
        private readonly WithdrawalSession $withdrawalSession,
    ) {
    }

    /**
     * Create.
     *
     * @return CustomerIdentity
     */
    public function create(): CustomerIdentity
    {
        $customerId = $this->session->isLoggedIn()
            ? (int) $this->session->getCustomerId()
            : null;

        $boundOrderEntityId = null;

        $token = (string) $this->request->getParam('t');
        if ($token !== '') {
            $boundOrderEntityId = $this->magicLinkService->resolveOrder($token);
        }

        // Fallback: guest previously verified this order via the Lookup flow
        // in the same session. Keeps the Cancel button visible after a
        // refresh that strips the `?t=` param.
        if ($boundOrderEntityId === null) {
            $queryOrderId = trim((string) $this->request->getParam('order_id', ''));
            if ($queryOrderId !== '' && ctype_digit($queryOrderId)) {
                $entityId = (int) $queryOrderId;
                if ($this->withdrawalSession->isOrderVerified($entityId)) {
                    $boundOrderEntityId = $entityId;
                }
            }
        }

        return new CustomerIdentity($customerId, $boundOrderEntityId);
    }
}
