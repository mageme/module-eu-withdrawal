<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Receipt;

use MageMe\EUWithdrawal\Exception\ReceiptBuilderException;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Phrase;
use Magento\Store\Model\ScopeInterface;

class ReceiptBuilder
{
    public const TABLE_REQUEST = 'mm_eu_withdrawal_request';
    public const TABLE_ITEM    = 'mm_eu_withdrawal_item';

    /**
     * Constructor.
     *
     * @param ResourceConnection $resource
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly ScopeConfigInterface $scopeConfig,
    ) {
    }

    /**
     * Build.
     *
     * @param int $requestId
     * @return ReceiptDto
     */
    public function build(int $requestId): ReceiptDto
    {
        $conn = $this->resource->getConnection();

        $rowSelect = $conn->select()
            ->from($this->resource->getTableName(self::TABLE_REQUEST))
            ->where('request_id = ?', $requestId);
        $row = $conn->fetchRow($rowSelect);
        if (!$row) {
            throw new ReceiptBuilderException(
                new Phrase('Request %1 not found.', [$requestId]),
            );
        }

        $snapshot = $row['receipt_snapshot'] ?? null;
        if (is_string($snapshot) && $snapshot !== '') {
            $decoded = json_decode($snapshot, true);
            if (is_array($decoded)) {
                return $this->fromSnapshot((int) $row['request_id'], $decoded);
            }
        }

        // Item lines for the receipt list (gross per-line refund). The net / tax /
        // total split is read from the frozen columns on the request row below —
        // RequestCreator writes them at consent time before the first build, so the
        // receipt never re-derives the breakdown from the (mutable) order.
        $itemSelect = $conn->select()
            ->from(
                $this->resource->getTableName(self::TABLE_ITEM),
                ['order_item_id', 'sku', 'qty_withdraw', 'refund_amount'],
            )
            ->where('request_id = ?', $requestId)
            ->order('order_item_id ASC');
        $itemRows = $conn->fetchAll($itemSelect);

        $items = [];
        foreach ($itemRows as $i) {
            $items[] = [
                'order_item_id' => (int) $i['order_item_id'],
                'sku'           => (string) $i['sku'],
                'qty'           => (int) $i['qty_withdraw'],
                'refund_amount' => number_format((float) $i['refund_amount'], 2, '.', ''),
            ];
        }

        $storeId = (int) $row['store_id'];
        $merchant = [
            'name'    => (string) ($this->scopeConfig->getValue('general/store_information/name', ScopeInterface::SCOPE_STORE, $storeId) ?? ''),
            'vat_id'  => (string) ($this->scopeConfig->getValue('general/store_information/merchant_vat_number', ScopeInterface::SCOPE_STORE, $storeId) ?? ''),
            'address' => (string) ($this->scopeConfig->getValue('general/store_information/street_line1', ScopeInterface::SCOPE_STORE, $storeId) ?? ''),
        ];

        $ipHash = $row['ip'] !== null ? hash('sha256', (string) $row['ip']) : '';
        $ua     = (string) ($row['user_agent'] ?? '');
        if (strlen($ua) > 100) {
            $ua = substr($ua, 0, 100);
        }

        // Gross Art. 6(1)(d) presentation: the refund is shown as the amount
        // actually returned (items gross + shipping gross) with the VAT it
        // contains as an informational line. Item gross subtotal is the sum of
        // the per-line gross refund_amount; shipping gross is the residual so
        // items + shipping always reconcile to the canonical total.
        //   - items      = gross subtotal of withdrawn lines (net + line VAT)
        //   - tax        = combined VAT (item VAT + shipping VAT), informational
        //   - adjustment = order-level refund component (signed)
        //   - total      = canonical refund
        //   - shipping   = gross residual so the parts always reconcile to total
        $itemsGross = 0.0;
        foreach ($itemRows as $i) {
            $itemsGross += (float) $i['refund_amount'];
        }
        $itemsGross       = round($itemsGross, 2, PHP_ROUND_HALF_EVEN);
        $taxRefundTotal   = (float) ($row['tax_refund'] ?? 0.0);
        $orderAdjustment  = (float) ($row['order_adjustment_refund'] ?? 0.0);
        $refundTotal      = (float) ($row['total_refund'] ?? 0.0);
        $shippingGross    = round(
            $refundTotal - $itemsGross - $orderAdjustment,
            2,
            PHP_ROUND_HALF_EVEN,
        );

        return new ReceiptDto(
            requestId: (int) $row['request_id'],
            consumer: [
                'name'   => (string) $row['customer_name'],
                'email'  => (string) $row['customer_email'],
                'reason' => $row['reason_text'] !== null ? (string) $row['reason_text'] : null,
            ],
            order: [
                'increment_id' => (string) $row['contract_identifier'],
                'created_at'   => $this->toIsoZ((string) $row['created_at']),
                'total'        => number_format($refundTotal, 2, '.', ''),
            ],
            items: $items,
            refund: [
                'items'      => number_format($itemsGross, 2, '.', ''),
                'shipping'   => number_format($shippingGross, 2, '.', ''),
                'tax'        => number_format($taxRefundTotal, 2, '.', ''),
                'adjustment' => number_format($orderAdjustment, 2, '.', ''),
                'total'      => number_format($refundTotal, 2, '.', ''),
            ],
            receipt: [
                'created_at'   => $this->toIsoZ((string) $row['created_at']),
                'locale'       => (string) $row['locale'],
                'ip_hash'      => $ipHash,
                'user_agent'   => $ua,
            ],
            merchant: $merchant,
            legal: [
                'withdrawal_period_days' => max(
                    14,
                    (int) $this->scopeConfig->getValue(
                        'mageme_eu_withdrawal/withdrawal_window/period_days',
                        ScopeInterface::SCOPE_STORE,
                        $storeId,
                    ),
                ),
                'article_ref'            => 'Art. 9 CRD',
            ],
        );
    }

    /**
     * Rebuild a DTO from the frozen snapshot captured at finalize, so the
     * content hash and receipt stay stable when store config or order rows
     * later change.
     *
     * @param int $requestId
     * @param array $data
     * @return ReceiptDto
     */
    private function fromSnapshot(int $requestId, array $data): ReceiptDto
    {
        return new ReceiptDto(
            requestId: $requestId,
            consumer: (array) ($data[ReceiptDto::CONSUMER] ?? []),
            order: (array) ($data[ReceiptDto::ORDER] ?? []),
            items: array_values((array) ($data[ReceiptDto::ITEMS] ?? [])),
            refund: (array) ($data[ReceiptDto::REFUND] ?? []),
            receipt: (array) ($data[ReceiptDto::RECEIPT] ?? []),
            merchant: (array) ($data[ReceiptDto::MERCHANT] ?? []),
            legal: (array) ($data[ReceiptDto::LEGAL] ?? []),
        );
    }

    /**
     * To iso z.
     *
     * @param string $mysqlDate
     * @return string
     */
    private function toIsoZ(string $mysqlDate): string
    {
        $dt = new \DateTimeImmutable($mysqlDate, new \DateTimeZone('UTC'));
        return $dt->format('Y-m-d\TH:i:s\Z');
    }
}
