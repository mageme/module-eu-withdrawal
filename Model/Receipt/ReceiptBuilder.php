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

        // JOIN sales_order_item so we can split each line's gross refund_amount
        // (= subtotal + line_tax, baked together by RequestCreator) back into
        // its net subtotal and tax components for receipt breakdown. Without
        // this JOIN the verify page can only show one combined number, which
        // hides the tax line that EU Art. 6(1)(d) requires consumers to see.
        $itemSelect = $conn->select()
            ->from(['i' => $this->resource->getTableName(self::TABLE_ITEM)])
            ->joinLeft(
                ['oi' => $this->resource->getTableName('sales_order_item')],
                'oi.item_id = i.order_item_id',
                ['oi_tax_amount' => 'tax_amount', 'oi_qty_ordered' => 'qty_ordered'],
            )
            ->where('i.request_id = ?', $requestId)
            ->order('i.order_item_id ASC');
        $itemRows = $conn->fetchAll($itemSelect);

        $items = [];
        $itemsNetSubtotal = 0.0;
        $itemsTaxTotal = 0.0;
        $refundItemsTotal = 0.0;
        foreach ($itemRows as $i) {
            $qtyWithdraw   = (int) $i['qty_withdraw'];
            $refundGross   = (float) $i['refund_amount'];
            $orderTax      = (float) ($i['oi_tax_amount'] ?? 0.0);
            $orderedQty    = (float) ($i['oi_qty_ordered'] ?? 0.0);
            $lineTax       = $orderedQty > 0
                ? round($qtyWithdraw * $orderTax / $orderedQty, 4, PHP_ROUND_HALF_EVEN)
                : 0.0;
            $lineSubtotal  = $refundGross - $lineTax;

            $items[] = [
                'order_item_id' => (int) $i['order_item_id'],
                'sku'           => (string) $i['sku'],
                'qty'           => $qtyWithdraw,
                'refund_amount' => number_format($refundGross, 2, '.', ''),
            ];
            $itemsNetSubtotal += $lineSubtotal;
            $itemsTaxTotal    += $lineTax;
            $refundItemsTotal += $refundGross;
        }

        // Pull the order's shipping figures so we can tell the verify-page
        // whether the request triggered a full-return shipping refund and,
        // if so, how much of it was net vs VAT. `request.shipping_refund`
        // since 0.12.2 stores the gross figure (net + VAT); we split it
        // back into components by reading the original order columns.
        $shippingRefundStored = (float) ($row['shipping_refund'] ?? 0.0);
        $shippingNet = 0.0;
        $shippingTax = 0.0;
        if ($shippingRefundStored > 0) {
            $orderSelect = $conn->select()
                ->from(
                    $this->resource->getTableName('sales_order'),
                    ['shipping_amount', 'shipping_tax_amount'],
                )
                ->where('entity_id = ?', (int) $row['order_id']);
            $orderRow = $conn->fetchRow($orderSelect);
            if ($orderRow) {
                $shippingNet = (float) $orderRow['shipping_amount'];
                $shippingTax = (float) $orderRow['shipping_tax_amount'];
            }
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

        // Order-level refund component (gross, signed) frozen at consent time —
        // payment-method discount, gift card or custom total not present in item
        // fields. Shown as its own line so item/shipping/tax stay transparent.
        $orderAdjustment = (float) ($row['order_adjustment_refund'] ?? 0.0);

        // Receipt breakdown shows EU Art. 6(1)(d) component split:
        //   - items      = net subtotal of withdrawn lines (refund_amount - line_tax)
        //   - shipping    = net shipping refund only (no VAT)
        //   - tax         = combined VAT (line tax + shipping tax)
        //   - adjustment  = order-level refund component (signed)
        //   - total       = items + shipping + tax + adjustment
        $taxRefundTotal = round($itemsTaxTotal + $shippingTax, 4, PHP_ROUND_HALF_EVEN);
        $refundTotal    = round(
            $itemsNetSubtotal + $shippingNet + $taxRefundTotal + $orderAdjustment,
            4,
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
                'items'      => number_format($itemsNetSubtotal, 2, '.', ''),
                'shipping'   => number_format($shippingNet, 2, '.', ''),
                'tax'        => number_format($taxRefundTotal, 2, '.', ''),
                'adjustment' => number_format($orderAdjustment, 2, '.', ''),
                'total'      => number_format($refundTotal, 2, '.', ''),
            ],
            receipt: [
                'created_at'   => $this->toIsoZ((string) $row['created_at']),
                'confirmed_at' => $row['confirmed_at'] !== null ? $this->toIsoZ((string) $row['confirmed_at']) : '',
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
