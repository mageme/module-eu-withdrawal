<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Block\Email;

use MageMe\EUWithdrawal\Api\Data\ItemInterface;
use MageMe\EUWithdrawal\Api\Data\RequestInterface;
use MageMe\EUWithdrawal\Model\Frontend\ReasonsConfigReader;
use MageMe\EUWithdrawal\Model\Lookup\RequestLookup;
use MageMe\EUWithdrawal\Model\ResourceModel\Item\CollectionFactory as ItemCollectionFactory;
use Magento\Catalog\Helper\Image as ImageHelper;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Renders the "Returned items" / "Items being withdrawn" table used inside
 * every customer email (notification, approved, denied, cancelled-*, receipt).
 *
 * Pulls data from `mm_eu_withdrawal_item` joined with the order's
 * `sales_order_item` rows for the line name, then resolves the product
 * thumbnail via Magento's standard catalog Image helper. Falls back to a
 * neutral placeholder cell when the product is unavailable.
 */
class ItemsTable extends Template
{
    /**
     * Constructor.
     *
     * @param Context $context
     * @param RequestLookup $requestLookup
     * @param ItemCollectionFactory $itemCollectionFactory
     * @param OrderRepositoryInterface $orderRepository
     * @param ProductRepositoryInterface $productRepository
     * @param ImageHelper $imageHelper
     * @param ReasonsConfigReader $reasonsConfig
     * @param array $data
     */
    public function __construct(
        Context $context,
        private readonly RequestLookup $requestLookup,
        private readonly ItemCollectionFactory $itemCollectionFactory,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly ImageHelper $imageHelper,
        private readonly ReasonsConfigReader $reasonsConfig,
        array $data = [],
    ) {
        parent::__construct($context, $data);
    }

    /**
     * @return array<int, array{
     *     name: string,
     *     sku: string,
     *     qty: int,
     *     refund_amount: string,
     *     image_url: ?string,
     *     eligibility: string,
     *     reason: string,
     *     options: string,
     *     status_label: string,
     *     status_bg: string,
     *     status_fg: string
     * }>
     */
    public function getRowsForRequest(int $requestId): array
    {
        $request = $this->requestLookup->findRequestById($requestId);
        if ($request === null) {
            return [];
        }
        $orderId = (int) $request->order_id;

        $collection = $this->itemCollectionFactory->create();
        $collection->addFieldToFilter(ItemInterface::REQUEST_ID, $requestId)
            ->setOrder('order_item_id', 'ASC');
        $items = array_values(array_map(static fn ($item): array => $item->getData(), $collection->getItems()));
        if (!$items) {
            return [];
        }

        $statusBadge = $this->resolveStatusBadge((string) $this->getData('current_status'));
        $storeIdForReasons = $this->getData('store_id') !== null ? (int) $this->getData('store_id') : null;

        try {
            $order = $this->orderRepository->get($orderId);
        } catch (\Throwable) {
            return $this->buildRowsWithoutOrder($items, $statusBadge);
        }

        $orderItemsByOid = [];
        foreach (($order->getItems() ?? []) as $oi) {
            $orderItemsByOid[(int) $oi->getItemId()] = $oi;
        }

        $rows = [];
        foreach ($items as $i) {
            $oid = (int) $i['order_item_id'];
            $orderItem = $orderItemsByOid[$oid] ?? null;
            $name = $orderItem !== null ? (string) $orderItem->getName() : (string) $i['sku'];
            $imageUrl = null;
            $options = '';
            if ($orderItem !== null) {
                $options = $this->extractOptions($orderItem);
                try {
                    $product = $this->productRepository->getById((int) $orderItem->getProductId());
                    $imageUrl = $this->imageHelper
                        ->init($product, 'cart_page_product_thumbnail')
                        ->setImageFile((string) $product->getImage())
                        ->getUrl();
                } catch (\Throwable) {
                    $imageUrl = null;
                }
            }
            $rows[] = [
                'name'          => $name,
                'sku'           => (string) $i['sku'],
                'qty'           => (int) $i['qty_withdraw'],
                'refund_amount' => (string) $i['refund_amount'],
                'image_url'     => $imageUrl,
                'eligibility'   => (string) $i['eligibility'],
                'reason'        => $this->resolveReasonLabel(
                    (string) ($i['reason_code'] ?? ''),
                    (string) ($i['reason_text'] ?? ''),
                    $storeIdForReasons,
                ),
                'options'       => $options,
                'status_label'  => $statusBadge['label'],
                'status_bg'     => $statusBadge['bg'],
                'status_fg'     => $statusBadge['fg'],
            ];
        }
        return $rows;
    }

    /**
     * Convert the stored reason_code (machine value like `wrong_size`) into the
     * admin-configured label (`Wrong size or fit`). Free-text reason from the
     * "other" preset wins when the code is `other`; falls back to a humanised
     * code when the preset is no longer in the config.
     */
    private function resolveReasonLabel(string $code, string $text, ?int $storeId): string
    {
        $code = trim($code);
        $text = trim($text);
        if ($code === '' && $text === '') {
            return '';
        }
        if ($code === ReasonsConfigReader::RESERVED_CODE_OTHER) {
            if ($text !== '') {
                return $text;
            }
            return $this->reasonsConfig->resolveLabel(ReasonsConfigReader::RESERVED_CODE_OTHER, $storeId);
        }
        if ($code !== '') {
            return $this->reasonsConfig->resolveLabel($code, $storeId);
        }
        return $text;
    }

    /**
     * @return array{label: string, bg: string, fg: string}
     */
    private function resolveStatusBadge(string $requestStatus): array
    {
        return match ($requestStatus) {
            RequestInterface::STATUS_PENDING       => ['label' => (string) __('In progress'),  'bg' => '#fef3c7', 'fg' => '#92400e'],
            RequestInterface::STATUS_APPROVED        => ['label' => (string) __('Approved'), 'bg' => '#dcfce7', 'fg' => '#166534'],
            RequestInterface::STATUS_DENIED          => ['label' => (string) __('Denied'),       'bg' => '#fee2e2', 'fg' => '#991b1b'],
            RequestInterface::STATUS_CANCELLED       => ['label' => (string) __('Cancelled'),    'bg' => '#e5e7eb', 'fg' => '#374151'],
            default                                  => ['label' => (string) __('In progress'),  'bg' => '#fef3c7', 'fg' => '#92400e'],
        };
    }

    /**
     * Extract options.
     *
     * @param \Magento\Sales\Api\Data\OrderItemInterface $orderItem
     * @return string
     */
    private function extractOptions(\Magento\Sales\Api\Data\OrderItemInterface $orderItem): string
    {
        $opts = $orderItem->getProductOptions();
        if (!is_array($opts)) {
            return '';
        }
        $parts = [];
        // Configurable products: 'attributes_info' carries variant attributes.
        if (!empty($opts['attributes_info']) && is_array($opts['attributes_info'])) {
            foreach ($opts['attributes_info'] as $attr) {
                if (!is_array($attr)) {
                    continue;
                }
                $label = trim((string) ($attr['label'] ?? ''));
                $value = trim((string) ($attr['value'] ?? ''));
                if ($label !== '' && $value !== '') {
                    $parts[] = $label . ': ' . $value;
                }
            }
        }
        // Custom options: 'options' array of {label, value}.
        if (!empty($opts['options']) && is_array($opts['options'])) {
            foreach ($opts['options'] as $o) {
                if (!is_array($o)) {
                    continue;
                }
                $label = trim((string) ($o['label'] ?? ''));
                $value = trim((string) ($o['value'] ?? ''));
                if ($label !== '' && $value !== '') {
                    $parts[] = $label . ': ' . $value;
                }
            }
        }
        return implode(' · ', $parts);
    }

    /**
     * Render for request.
     *
     * @param int $requestId
     * @return string
     */
    public function renderForRequest(int $requestId): string
    {
        $rows = $this->getRowsForRequest($requestId);
        if ($rows === []) {
            return '';
        }
        $this->setData('rows', $rows);
        return $this->renderPartial(__DIR__ . '/../../view/frontend/templates/email/items_table.phtml');
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @param array{label: string, bg: string, fg: string} $statusBadge
     * @return array<int, array{name: string, sku: string, qty: int, refund_amount: string, image_url: ?string, eligibility: string, reason: string, options: string, status_label: string, status_bg: string, status_fg: string}>
     */
    private function buildRowsWithoutOrder(array $items, array $statusBadge): array
    {
        $storeId = $this->getData('store_id') !== null ? (int) $this->getData('store_id') : null;
        $rows = [];
        foreach ($items as $i) {
            $rows[] = [
                'name'          => (string) $i['sku'],
                'sku'           => (string) $i['sku'],
                'qty'           => (int) $i['qty_withdraw'],
                'refund_amount' => (string) $i['refund_amount'],
                'image_url'     => null,
                'eligibility'   => (string) $i['eligibility'],
                'reason'        => $this->resolveReasonLabel(
                    (string) ($i['reason_code'] ?? ''),
                    (string) ($i['reason_text'] ?? ''),
                    $storeId,
                ),
                'options'       => '',
                'status_label'  => $statusBadge['label'],
                'status_bg'     => $statusBadge['bg'],
                'status_fg'     => $statusBadge['fg'],
            ];
        }
        return $rows;
    }

    /**
     * Render partial.
     *
     * @param string $absolutePath
     * @return string
     */
    private function renderPartial(string $absolutePath): string
    {
        $block   = $this;
        $escaper = $this->_escaper;
        ob_start();
        try {
            // phpcs:ignore Magento2.Security.IncludeFile.FoundIncludeFile -- module-shipped phtml partial, path is a hard-coded constant
            include $absolutePath;
        } finally {
            $rendered = (string) ob_get_clean();
        }
        return $rendered;
    }
}
