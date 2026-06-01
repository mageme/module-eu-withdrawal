<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model;

use Magento\Framework\Session\SessionManager;

/**
 * Short-lived session singleton for the withdrawal flow.
 *
 * Used to hand off the just-finalized request_id from the Finalize controller
 * to the Success page without exposing it in the URL (prevents enumeration of
 * other customers' withdrawal numbers).
 */
class Session extends SessionManager
{
    /**
     * Set last withdrawal request id.
     *
     * @param int $requestId
     * @return self
     */
    public function setLastWithdrawalRequestId(int $requestId): self
    {
        $this->setData('last_withdrawal_request_id', $requestId);
        return $this;
    }

    /**
     * Get last withdrawal request id.
     *
     * @return ?int
     */
    public function getLastWithdrawalRequestId(): ?int
    {
        $v = $this->getData('last_withdrawal_request_id');
        return $v === null ? null : (int) $v;
    }

    /**
     * Clear last withdrawal request id.
     *
     * @return self
     */
    public function clearLastWithdrawalRequestId(): self
    {
        $this->unsetData('last_withdrawal_request_id');
        return $this;
    }

    /**
     * Marks an order entity id as verified in this guest session — the Lookup
     * controller sets it after an email+order_id match. Lets ExistingWithdrawals
     * show the cancel button even when no magic-link token is in the URL (e.g.
     * after a reload that strips the `?t=` param). Capped to 10 items.
     */
    public function markOrderVerified(int $orderEntityId): self
    {
        $ids = (array) ($this->getData('verified_order_ids') ?? []);
        $ids[] = $orderEntityId;
        $ids = array_values(array_unique(array_map('intval', array_filter($ids))));
        if (count($ids) > 10) {
            $ids = array_slice($ids, -10);
        }
        $this->setData('verified_order_ids', $ids);
        return $this;
    }

    /**
     * Is order verified.
     *
     * @param int $orderEntityId
     * @return bool
     */
    public function isOrderVerified(int $orderEntityId): bool
    {
        $ids = (array) ($this->getData('verified_order_ids') ?? []);
        return in_array($orderEntityId, array_map('intval', $ids), true);
    }
}
