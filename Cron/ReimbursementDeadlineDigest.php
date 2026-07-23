<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Cron;

use MageMe\EUWithdrawal\Api\Data\RequestInterface;
use MageMe\EUWithdrawal\Model\Mail\EmailConfig;
use MageMe\EUWithdrawal\Model\Reimbursement\DeadlineCalculator;
use Magento\Framework\App\Area;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Escaper;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Store\Model\Store;
use Psr\Log\LoggerInterface;

/**
 * Daily digest of OVERDUE reimbursements. Selects open, not-withheld requests that
 * are still unpaid — no linked credit memo and no manual reimbursement-paid mark —
 * whose Art. 13(1) deadline — 14 days after created_at — has passed and
 * whose last alert is NULL or older than the re-alert window, groups them per
 * store, sends one plain-text digest per store to the existing admin recipients,
 * then stamps reimbursement_last_alerted_at so a second same-day run sends
 * nothing. The deadline is derived from created_at in SQL (never stored), the
 * same way the grid's due-state column derives it; terminality is read from
 * status.
 *
 * The digest has its own admin-notification switch, template and recipients
 * (Stores → Configuration → EU Withdrawal → Admin Notifications → Reimbursement
 * Overdue Digest); it ships OFF. A disabled or unaddressed store is skipped
 * without a stamp, so its requests stay alertable once configured.
 *
 * Days overdue are whole ELAPSED 24h periods measured against UTC_TIMESTAMP(),
 * matching the grid's due-state column so both surfaces report the same day for
 * the same request. DATEDIFF would count calendar-day boundaries instead and
 * disagree with the grid by a day.
 *
 * Mirrors the simple, non-lease ExpireOrphanWaiverEvents cron — an overlapping
 * run can at worst duplicate a digest, never lose a stamp.
 */
class ReimbursementDeadlineDigest
{
    private const TABLE = 'mm_eu_withdrawal_request';
    private const TABLE_ORDER = 'sales_order';

    /** Must stay under the 24h schedule interval: cron jitter shifts each run's
     *  clock either way, and a full 24h window would skip a still-overdue
     *  request whenever a run lands earlier in the day than the previous one. */
    private const REALERT_SECONDS = 23 * 3600;

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly EmailConfig $emailConfig,
        private readonly TransportBuilder $transportBuilder,
        private readonly Escaper $escaper,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function execute(): void
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE);
        $now = gmdate('Y-m-d H:i:s');

        // The Art. 13(1) deadline computed the same way DeadlineCalculator does
        // for the grid: created_at + the 14-day period, in UTC.
        $deadlineExpr = 'DATE_ADD(r.' . RequestInterface::CREATED_AT
            . ', INTERVAL ' . DeadlineCalculator::PERIOD_DAYS . ' DAY)';

        $select = $connection->select()
            ->from(
                ['r' => $table],
                ['request_id', 'store_id', 'increment_id'],
            )
            // Merchants search orders by increment_id, so that is the number the
            // digest must print. LEFT so a request whose order row is gone still
            // gets reported instead of vanishing from the digest.
            ->joinLeft(
                ['so' => $this->resource->getTableName(self::TABLE_ORDER)],
                'r.order_id = so.entity_id',
                ['order_increment_id' => 'so.increment_id'],
            )
            ->columns([
                'deadline' => new \Zend_Db_Expr($deadlineExpr),
                'days_overdue' => new \Zend_Db_Expr(
                    'TIMESTAMPDIFF(DAY, ' . $deadlineExpr . ', UTC_TIMESTAMP())',
                ),
            ])
            ->where('r.status IN (?)', [RequestInterface::STATUS_PENDING, RequestInterface::STATUS_APPROVED])
            ->where('r.' . RequestInterface::REFUND_CREDITMEMO_ID . ' IS NULL')
            ->where('r.' . RequestInterface::REIMBURSEMENT_PAID_AT . ' IS NULL')
            ->where('r.' . RequestInterface::REIMBURSEMENT_WITHHELD_AT . ' IS NULL')
            ->where(
                'r.' . RequestInterface::CREATED_AT
                . ' < UTC_TIMESTAMP() - INTERVAL ' . DeadlineCalculator::PERIOD_DAYS . ' DAY',
            )
            ->where(
                'r.' . RequestInterface::REIMBURSEMENT_LAST_ALERTED_AT . ' IS NULL OR r.'
                . RequestInterface::REIMBURSEMENT_LAST_ALERTED_AT . ' < ?',
                gmdate('Y-m-d H:i:s', time() - self::REALERT_SECONDS),
            );

        $byStore = [];
        foreach ($connection->fetchAll($select) as $row) {
            $byStore[(int) $row['store_id']][] = $row;
        }

        foreach ($byStore as $storeId => $rows) {
            if (!$this->send($storeId, $rows)) {
                continue;
            }
            $connection->update(
                $table,
                [RequestInterface::REIMBURSEMENT_LAST_ALERTED_AT => $now],
                ['request_id IN (?)' => array_map(static fn (array $row): int => (int) $row['request_id'], $rows)],
            );
        }
    }

    /**
     * @param int $storeId
     * @param array<int, array<string, mixed>> $rows
     * @return bool true if a message was dispatched to at least one recipient
     */
    private function send(int $storeId, array $rows): bool
    {
        $sent = false;
        try {
            if (!$this->emailConfig->isEnabled(EmailConfig::TYPE_ADMIN_REIMBURSEMENT_OVERDUE, $storeId)) {
                return false;
            }

            $recipients = $this->emailConfig->getRecipientList(EmailConfig::TYPE_ADMIN_REIMBURSEMENT_OVERDUE, $storeId);
            if ($recipients === []) {
                return false;
            }

            $template = $this->emailConfig->getTemplate(EmailConfig::TYPE_ADMIN_REIMBURSEMENT_OVERDUE, $storeId);
            if ($template === '') {
                return false;
            }

            $rowsHtml = '';
            $lastIndex = count($rows) - 1;
            foreach (array_values($rows) as $index => $row) {
                $rowsHtml .= $this->renderRow($row, $index === $lastIndex);
            }
            $vars = ['overdue_count' => count($rows), 'overdue_rows_html' => $rowsHtml];

            foreach ($recipients as $address) {
                $this->transportBuilder
                    ->setTemplateIdentifier($template)
                    ->setTemplateOptions(['area' => Area::AREA_ADMINHTML, 'store' => Store::DEFAULT_STORE_ID])
                    ->setTemplateVars($vars)
                    ->setFromByScope('general', Store::DEFAULT_STORE_ID)
                    ->addTo($address);
                $this->transportBuilder->getTransport()->sendMessage();
                $sent = true;
            }
        } catch (\Throwable $e) {
            $this->logger->warning(
                'ReimbursementDeadlineDigest send failed for store ' . $storeId . ': ' . $e->getMessage(),
            );
        }

        return $sent;
    }

    /**
     * One overdue request as a `<tr>` for the digest table: withdrawal number,
     * order number (by increment_id, or a muted "order not found" when the order
     * row is gone), the Art. 13(1) deadline date, and a days-overdue pill. All
     * dynamic values are HTML-escaped; the table shell lives in the template.
     * The last row drops its hairline so it does not double up with the footer
     * divider below the table.
     *
     * @param array<string, mixed> $row
     * @param bool $isLast
     * @return string
     */
    private function renderRow(array $row, bool $isLast): string
    {
        $border = $isLast ? '' : 'border-bottom:1px solid #f1f3f5;';
        $cell = 'padding:12px 8px 12px 0;font-size:13px;color:#0f172a;' . $border;
        $cellMuted = 'padding:12px 8px;font-size:13px;color:#334155;' . $border;

        $withdrawal = $this->escaper->escapeHtml((string) ($row['increment_id'] ?? '(no-id)'));
        // 'order_increment_id' is the joined alias, NULL when the order is gone.
        $orderIncrement = $row['order_increment_id'] ?? null;
        $order = $orderIncrement !== null
            ? '#' . $this->escaper->escapeHtml((string) $orderIncrement)
            : '<span style="color:#667085;">' . $this->escaper->escapeHtml((string) __('(order not found)')) . '</span>';
        $deadlineDate = substr((string) $row['deadline'], 0, 10);
        $deadlineTs = strtotime($deadlineDate . ' UTC');
        $deadline = $this->escaper->escapeHtml(
            $deadlineTs !== false ? gmdate('j M Y', $deadlineTs) : $deadlineDate,
        );

        $days = (int) $row['days_overdue'];
        $daysText = $days === 1 ? (string) __('%1 day', $days) : (string) __('%1 days', $days);

        return '<tr class="data-row">'
            . '<td class="data-cell" style="' . $cell . 'font-weight:600;">' . $withdrawal . '</td>'
            . '<td class="data-cell" style="' . $cellMuted . '">' . $order . '</td>'
            . '<td class="data-cell" style="' . $cellMuted . '">' . $deadline . '</td>'
            . '<td class="data-cell" align="right" style="padding:12px 0 12px 8px;' . $border
            . 'font-size:13px;color:#0f172a;font-weight:600;white-space:nowrap;">'
            . $this->escaper->escapeHtml($daysText) . '</td>'
            . '</tr>';
    }
}
