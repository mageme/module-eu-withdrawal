<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Period;

use MageMe\EUWithdrawal\Api\Period\Art10ExtensionCheckerInterface;
use Magento\Sales\Api\Data\OrderInterface;

/**
 * free-tier Art. 10(1) extension checker: always returns false.
 *
 * The base module has no display-event table to query (the Pro
 * `MageMe_EUWithdrawalAnnexI` add-on owns that table). The conservative-
 * for-merchant default is to trust that the layout-injected Annex I
 * block was shown at checkout and apply the standard 14-day period.
 *
 * Merchants who need forensic-grade Art. 10(1) defence (extend to 12
 * months + 14 days when no proof of disclosure exists) should install
 * the Pro AnnexI add-on, which binds the interface to a
 * `DisplayEventBasedArt10ExtensionChecker` that queries
 * `mm_eu_withdrawal_display_event`.
 */
class NoOpArt10ExtensionChecker implements Art10ExtensionCheckerInterface
{
    /**
     * Should extend.
     *
     * @param OrderInterface $order
     * @return bool Always false in the free tier.
     */
    public function shouldExtend(OrderInterface $order): bool
    {
        return false;
    }
}
