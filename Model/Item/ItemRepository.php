<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Item;

use MageMe\EUWithdrawal\Api\Data\ItemInterface;
use MageMe\EUWithdrawal\Api\ItemRepositoryInterface;
use MageMe\EUWithdrawal\Model\ResourceModel\Item\CollectionFactory;

class ItemRepository implements ItemRepositoryInterface
{
    /**
     * Constructor.
     *
     * @param CollectionFactory $collectionFactory
     */
    public function __construct(
        private readonly CollectionFactory $collectionFactory,
    ) {
    }

    /**
     * Get by request.
     *
     * @param int $requestId
     * @return array
     */
    public function getByRequest(int $requestId): array
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter(ItemInterface::REQUEST_ID, $requestId)
                   ->setOrder(ItemInterface::ITEM_ID, 'ASC');
        return $collection->getItems();
    }
}
