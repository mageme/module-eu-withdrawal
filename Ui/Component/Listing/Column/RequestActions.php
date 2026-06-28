<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Ui\Component\Listing\Column;

use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

class RequestActions extends Column
{
    /**
     * Constructor.
     *
     * @param ContextInterface $context
     * @param UiComponentFactory $uiComponentFactory
     * @param UrlInterface $url
     * @param array $components
     * @param array $data
     */
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        private readonly UrlInterface $url,
        array $components = [],
        array $data = [],
    ) {
        parent::__construct($context, $uiComponentFactory, $components, $data);
    }

    /**
     * Prepare data source.
     *
     * @param array $dataSource
     * @return array
     */
    public function prepareDataSource(array $dataSource): array
    {
        if (!isset($dataSource['data']['items'])) {
            return $dataSource;
        }
        foreach ($dataSource['data']['items'] as &$item) {
            if (!isset($item['request_id'])) {
                continue;
            }
            $id = (int) $item['request_id'];
            $actions = [
                'view' => [
                    'href'  => $this->url->getUrl('mageme_eu_withdrawal/request/edit', ['request_id' => $id]),
                    'label' => __('View Request'),
                ],
            ];

            $orderId = (int) ($item['order_id'] ?? 0);
            if ($orderId > 0) {
                $actions['view_order'] = [
                    'href'  => $this->url->getUrl('sales/order/view', ['order_id' => $orderId]),
                    'label' => __('View Order'),
                ];
            }

            $shipmentId = (int) ($item['shipment_id'] ?? 0);
            if ($shipmentId > 0) {
                $actions['view_shipment'] = [
                    'href'  => $this->url->getUrl('sales/shipment/view', ['shipment_id' => $shipmentId]),
                    'label' => __('View Shipment'),
                ];
            }

            $item[$this->getData('name')] = $actions;
        }
        return $dataSource;
    }
}
