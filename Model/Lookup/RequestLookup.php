<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Lookup;

use MageMe\EUWithdrawal\Api\Data\ItemInterface;
use MageMe\EUWithdrawal\Api\Data\RequestInterface;
use MageMe\EUWithdrawal\Model\ResourceModel\Item\CollectionFactory as ItemCollectionFactory;
use MageMe\EUWithdrawal\Model\ResourceModel\Request\CollectionFactory as RequestCollectionFactory;

class RequestLookup
{
    public const TABLE_REQUEST = 'mm_eu_withdrawal_request';
    public const TABLE_ITEM = 'mm_eu_withdrawal_item';

    /**
     * Constructor.
     *
     * @param RequestCollectionFactory $requestCollectionFactory
     * @param ItemCollectionFactory $itemCollectionFactory
     */
    public function __construct(
        private readonly RequestCollectionFactory $requestCollectionFactory,
        private readonly ItemCollectionFactory $itemCollectionFactory,
    ) {
    }

    /**
     * Find request by id.
     *
     * @param int $requestId
     * @return ?\stdClass
     */
    public function findRequestById(int $requestId): ?\stdClass
    {
        $collection = $this->requestCollectionFactory->create();
        $collection->addFieldToFilter(RequestInterface::REQUEST_ID, $requestId)
            ->setPageSize(1);
        $model = $collection->getFirstItem();
        if (!$model->getId()) {
            return null;
        }
        return (object) $model->getData();
    }

    /**
     * @return \stdClass[]
     *
     * TODO(task-19): memoize per request_id within the request scope so the
     * confirm page doesn't double-fetch when both Block\Withdraw\Confirm and
     * Block\Withdraw\DigitalWaiverDisplay call this for the same id. A
     * `private array $itemsById = []` cache keyed by request id is sufficient.
     */
    public function findItemsByRequestId(int $requestId): array
    {
        $collection = $this->itemCollectionFactory->create();
        $collection->addFieldToFilter(ItemInterface::REQUEST_ID, $requestId)
            ->setOrder(ItemInterface::ITEM_ID, 'ASC');
        // array_values: getItems() is keyed by entity id; callers expect a 0-based list.
        return array_values(array_map(
            static fn ($item): \stdClass => (object) $item->getData(),
            $collection->getItems()
        ));
    }
}
