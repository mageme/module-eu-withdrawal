<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\ResourceModel\Request\Grid;

use MageMe\EUWithdrawal\Model\Request\Request;
use MageMe\EUWithdrawal\Model\ResourceModel\Request as RequestResource;
use Magento\Framework\View\Element\UiComponent\DataProvider\SearchResult;

class Collection extends SearchResult
{
    /**
     * Init select.
     *
     * @return self
     */
    protected function _initSelect(): self
    {
        parent::_initSelect();

        $itemTable = $this->getTable('mm_eu_withdrawal_item');
        $orderTable = $this->getTable('sales_order');
        $shipmentTable = $this->getTable('sales_shipment');

        $this->getSelect()
            ->joinLeft(
                ['so' => $orderTable],
                'main_table.order_id = so.entity_id',
                [
                    'order_increment_id' => 'so.increment_id',
                    'order_total'        => 'so.grand_total',
                ]
            )
            ->columns([
                'items_count' => new \Zend_Db_Expr(
                    '(SELECT COUNT(*) FROM ' . $itemTable . ' i
                      WHERE i.request_id = main_table.request_id)'
                ),
                // Full refund shown in the grid = the frozen total_refund stored on
                // the request at consent time (items + shipping + order adjustment),
                // matching the Refund Totals on the edit screen and the order comment.
                'items_refund_total' => new \Zend_Db_Expr('COALESCE(main_table.total_refund, 0)'),
                // Latest shipment for the order (NULL when none) — surfaced as a
                // "View shipment" row action. MAX(entity_id) avoids row fan-out
                // when an order has multiple shipments.
                'shipment_id' => new \Zend_Db_Expr(
                    '(SELECT MAX(s.entity_id) FROM ' . $shipmentTable . ' s
                      WHERE s.order_id = main_table.order_id)'
                ),
            ]);

        return $this;
    }

    /**
     * Construct.
     *
     * @return void
     */
    protected function _construct(): void
    {
        $this->_init(Request::class, RequestResource::class);
    }

    /**
     * Prefix bare column names with `main_table.` so grid filters never collide
     * with the LEFT-joined `sales_order` table — bare `WHERE status = ?` is
     * ambiguous because both `mm_eu_withdrawal_request` and `sales_order`
     * carry a `status` column. Columns that exist only as join aliases
     * (`order_*`) or sub-select expressions (`items_*`) are passed through
     * unchanged.
     *
     * @param string|array $field
     * @param mixed $condition
     * @return self
     */
    public function addFieldToFilter($field, $condition = null)
    {
        if (is_string($field) && !str_contains($field, '.')) {
            $passThrough = ['order_increment_id', 'order_total', 'items_count', 'items_refund_total', 'shipment_id'];
            if (!in_array($field, $passThrough, true)) {
                $field = 'main_table.' . $field;
            }
        }
        return parent::addFieldToFilter($field, $condition);
    }
}
