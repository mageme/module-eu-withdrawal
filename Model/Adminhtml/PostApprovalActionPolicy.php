<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Adminhtml;

use Magento\Sales\Model\Order;

/**
 * Decides which post-approval action the admin should be offered on a
 * withdrawal request edit screen. The classic flow assumes the order was
 * paid → admin issues a credit memo. When the order is unpaid (pending
 * payment, offline method, abandoned invoice) there is nothing to refund,
 * so the credit-memo route is replaced with native order cancellation.
 * If neither is available (order already cancelled, fully refunded,
 * closed, or order missing) no action button is offered.
 */
class PostApprovalActionPolicy
{
    public const CREDITMEMO = 'creditmemo';
    public const CANCEL     = 'cancel';
    public const NONE       = 'none';

    /**
     * Resolve. Types on the concrete `Order` rather than `OrderInterface`
     * because the relevant `canCreditmemo()` / `canCancel()` policy checks
     * are not part of the public API contract.
     *
     * @param ?Order $order
     * @return string one of self::CREDITMEMO | self::CANCEL | self::NONE
     */
    public function resolve(?Order $order): string
    {
        if ($order === null) {
            return self::NONE;
        }
        if ($order->canCreditmemo()) {
            return self::CREDITMEMO;
        }
        if ($order->canCancel()) {
            return self::CANCEL;
        }
        return self::NONE;
    }
}
