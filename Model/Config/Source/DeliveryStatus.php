<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Magento\Sales\Model\ResourceModel\Order\Status\CollectionFactory;

/**
 * Lists every Magento order status (default + merchant-defined custom ones)
 * for the "Delivery Confirmation Status" admin field. The merchant picks the
 * status whose transition means "the customer received the goods" in their
 * fulfillment workflow — `AnchorResolver` then anchors the 14-day withdrawal
 * window on the timestamp of the order's transition into that status.
 *
 * Statuses are read live from `sales_order_status` so locally-added codes
 * (e.g. `delivered`, `in_transit_finalised`) appear without a config rebuild.
 */
class DeliveryStatus implements OptionSourceInterface
{
    /**
     * Constructor.
     *
     * @param CollectionFactory $statusCollectionFactory
     */
    public function __construct(
        private readonly CollectionFactory $statusCollectionFactory,
    ) {
    }

    /**
     * To option array.
     *
     * @return array
     */
    public function toOptionArray(): array
    {
        $options = [];
        $collection = $this->statusCollectionFactory->create();
        // Sort alphabetically by label for a stable, scannable dropdown.
        $collection->setOrder('label', 'ASC');
        foreach ($collection as $status) {
            $code  = (string) $status->getStatus();
            $label = (string) $status->getLabel();
            if ($code === '') {
                continue;
            }
            $options[] = [
                'value' => $code,
                'label' => $label !== '' ? sprintf('%s (%s)', $label, $code) : $code,
            ];
        }
        return $options;
    }
}
