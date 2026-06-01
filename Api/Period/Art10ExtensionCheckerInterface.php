<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Api\Period;

use Magento\Sales\Api\Data\OrderInterface;

/**
 * Decides whether the withdrawal period should be extended under Art.
 * 10(1) Directive 2011/83/EU.
 *
 * Two implementations bind to this interface:
 *
 *  - Default: `Model\Period\NoOpArt10ExtensionChecker` always
 *    returns false. Free cannot prove pre-contract display happened
 *    (no display-event recording), so the conservative-for-merchant
 *    default is "trust the layout-injected Annex I block was shown
 *    and apply the standard 14-day period."
 *  - Pro `MageMe_EUWithdrawalAnnexI`: queries
 *    `mm_eu_withdrawal_display_event` for the order's quote_id and
 *    returns true (extend to 12 months + 14 days) only when no
 *    display event exists. Forensic-grade Art. 10(1) defence.
 */
interface Art10ExtensionCheckerInterface
{
    /**
     * Should extend.
     *
     * @param OrderInterface $order
     * @return bool True if the withdrawal period should be extended to
     *              12 months + 14 days under Art. 10(1) (no proof of
     *              Art. 6(1)(h) disclosure).
     */
    public function shouldExtend(OrderInterface $order): bool;
}
