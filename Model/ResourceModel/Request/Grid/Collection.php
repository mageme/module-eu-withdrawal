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
                // Full refund shown in the grid = items subtotal + the frozen
                // Art. 13(2) delivery refund + the frozen order-level adjustment
                // stored on the request (both NULL on standard/partial rows),
                // matching the Refund Totals on the edit screen.
                'items_refund_total' => new \Zend_Db_Expr(
                    '(SELECT COALESCE(SUM(i.refund_amount), 0) FROM ' . $itemTable . ' i
                      WHERE i.request_id = main_table.request_id)
                      + COALESCE(main_table.shipping_refund, 0)
                      + COALESCE(main_table.order_adjustment_refund, 0)'
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
            $passThrough = ['order_increment_id', 'order_total', 'items_count', 'items_refund_total'];
            if (!in_array($field, $passThrough, true)) {
                $field = 'main_table.' . $field;
            }
        }
        return parent::addFieldToFilter($field, $condition);
    }
}
