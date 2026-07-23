<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Reimbursement;

use MageMe\EUWithdrawal\Api\Data\RequestInterface;

/**
 * Single source of the advisory, read-only reimbursement due-state, shared by the
 * admin grid column and the request edit screen so both always agree. The state is
 * derived, never stored: a missing created_at or a terminal status is "not
 * applicable", a reimbursed request (a linked credit memo OR a manual refunded
 * mark) is "refunded", an active withholding is "withheld", a passed Art. 13(1)
 * deadline is "overdue", otherwise "on track".
 *
 * "Refunded" is defined here and nowhere else: a linked refund_creditmemo_id, or a
 * manual reimbursement_paid_at set by the admin for refunds issued outside the
 * request (offline, bank transfer, external PSP, or a credit memo raised straight
 * from the order). The overdue digest cron mirrors the same three exclusions in
 * SQL.
 *
 * The deadline is computed on read as 14 days after created_at (UTC), the same way
 * the digest's SQL derives it, so grid, edit screen and digest report the same day
 * for the same request. Only a recognised, non-terminal status yields a live state;
 * anything else reads as not applicable, so an unreadable status understates rather
 * than asserts an obligation.
 */
class DueStateResolver
{
    public const STATE_NA       = 'na';
    public const STATE_REFUNDED = 'refunded';
    public const STATE_WITHHELD = 'withheld';
    public const STATE_OVERDUE  = 'overdue';
    public const STATE_ON_TRACK = 'on_track';

    private const TERMINAL_STATUSES = [
        RequestInterface::STATUS_DENIED,
        RequestInterface::STATUS_CANCELLED,
        RequestInterface::STATUS_ANONYMISED,
    ];

    public function __construct(
        private readonly DeadlineCalculator $deadlineCalculator,
    ) {
    }

    /**
     * @return array{code: string, label: string, days_overdue: int}
     */
    public function resolve(
        string $status,
        string $createdAtUtc,
        int $refundCreditmemoId,
        ?string $withheldAt,
        ?string $paidAt,
    ): array {
        $isOpen = in_array($status, RequestInterface::ALL_STATUSES, true)
            && !in_array($status, self::TERMINAL_STATUSES, true);
        if ($createdAtUtc === '' || !$isOpen) {
            return $this->result(self::STATE_NA, 0);
        }
        if ($refundCreditmemoId > 0 || (string) $paidAt !== '') {
            return $this->result(self::STATE_REFUNDED, 0);
        }
        if ((string) $withheldAt !== '') {
            return $this->result(self::STATE_WITHHELD, 0);
        }
        $overdueBy = time() - strtotime($this->deadlineCalculator->deadlineFor($createdAtUtc) . ' UTC');
        if ($overdueBy > 0) {
            return $this->result(self::STATE_OVERDUE, (int) floor($overdueBy / 86400));
        }
        return $this->result(self::STATE_ON_TRACK, 0);
    }

    /**
     * @return array{code: string, label: string, days_overdue: int}
     */
    private function result(string $code, int $daysOverdue): array
    {
        return ['code' => $code, 'label' => $this->label($code, $daysOverdue), 'days_overdue' => $daysOverdue];
    }

    private function label(string $code, int $daysOverdue): string
    {
        return match ($code) {
            self::STATE_REFUNDED => (string) __('Refunded'),
            self::STATE_WITHHELD => (string) __('Withheld'),
            self::STATE_OVERDUE  => (string) __('Overdue %1d', $daysOverdue),
            self::STATE_ON_TRACK => (string) __('On track'),
            default              => '—',
        };
    }
}
