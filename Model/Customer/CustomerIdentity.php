<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Customer;

use Magento\Sales\Api\Data\OrderInterface;

/**
 * Who is visiting the withdrawal page, and which orders can they see?
 *
 * Three identity shapes:
 *  - Logged-in:  customerId set, boundOrderEntityId null. Sees any order
 *                where order.customer_id == customerId.
 *  - Magic-link: customerId null, boundOrderEntityId set. Sees only that one
 *                order (even if the magic-link email happens to have gone to
 *                an address that also has a customer account).
 *  - Anonymous:  both null. Sees nothing.
 */
class CustomerIdentity
{
    /**
     * Constructor.
     *
     * @param ?int $customerId
     * @param ?int $boundOrderEntityId
     */
    public function __construct(
        public readonly ?int $customerId,
        public readonly ?int $boundOrderEntityId,
    ) {
    }

    /**
     * Is logged in.
     *
     * @return bool
     */
    public function isLoggedIn(): bool
    {
        return $this->customerId !== null;
    }

    /**
     * Is magic link.
     *
     * @return bool
     */
    public function isMagicLink(): bool
    {
        return $this->boundOrderEntityId !== null;
    }

    /**
     * Can see order.
     *
     * @param int $orderEntityId
     * @param OrderInterface $order
     * @return bool
     */
    public function canSeeOrder(int $orderEntityId, OrderInterface $order): bool
    {
        if ($this->isMagicLink() && $this->boundOrderEntityId === $orderEntityId) {
            return true;
        }
        if ($this->isLoggedIn() && (int) $order->getCustomerId() === $this->customerId) {
            return true;
        }
        return false;
    }

    /**
     * Can see request.
     *
     * @param OrderInterface $order
     * @param int $requestOrderId
     * @return bool
     */
    public function canSeeRequest(OrderInterface $order, int $requestOrderId): bool
    {
        $orderEntityId = (int) $order->getEntityId();
        if ($orderEntityId !== $requestOrderId) {
            return false;
        }
        return $this->canSeeOrder($orderEntityId, $order);
    }
}
