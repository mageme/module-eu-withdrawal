<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Request;

use MageMe\EUWithdrawal\Api\Data\RequestInterface;
use MageMe\EUWithdrawal\Api\RequestRepositoryInterface;
use MageMe\EUWithdrawal\Model\ResourceModel\Request as RequestResource;
use MageMe\EUWithdrawal\Model\ResourceModel\Request\CollectionFactory;
use Magento\Framework\Api\SearchCriteria\CollectionProcessorInterface;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SearchResultsInterface;
use Magento\Framework\Api\SearchResultsInterfaceFactory;
use Magento\Framework\Exception\NoSuchEntityException;

class RequestRepository implements RequestRepositoryInterface
{
    /**
     * Constructor.
     *
     * @param RequestFactory $requestFactory
     * @param RequestResource $resource
     * @param CollectionProcessorInterface $collectionProcessor
     * @param SearchResultsInterfaceFactory $searchResultsFactory
     * @param ?CollectionFactory $collectionFactory
     */
    public function __construct(
        private readonly RequestFactory $requestFactory,
        private readonly RequestResource $resource,
        private readonly CollectionProcessorInterface $collectionProcessor,
        private readonly SearchResultsInterfaceFactory $searchResultsFactory,
        private readonly ?CollectionFactory $collectionFactory = null,
    ) {
    }

    /**
     * Get.
     *
     * @param int $requestId
     * @return RequestInterface
     */
    public function get(int $requestId): RequestInterface
    {
        $req = $this->requestFactory->create();
        $this->resource->load($req, $requestId);
        if ($req->getId() === null) {
            throw new NoSuchEntityException(__('Withdrawal request #%1 does not exist.', $requestId));
        }
        return $req;
    }

    /**
     * Save.
     *
     * @param RequestInterface $request
     * @return RequestInterface
     */
    public function save(RequestInterface $request): RequestInterface
    {
        $this->resource->save($request);
        return $request;
    }

    /**
     * Get list.
     *
     * @param SearchCriteriaInterface $criteria
     * @return SearchResultsInterface
     */
    public function getList(SearchCriteriaInterface $criteria): SearchResultsInterface
    {
        $collection = $this->collectionFactory !== null
            ? $this->collectionFactory->create()
            : throw new \LogicException('CollectionFactory not injected');
        $this->collectionProcessor->process($criteria, $collection);
        $results = $this->searchResultsFactory->create();
        $results->setSearchCriteria($criteria);
        $results->setItems($collection->getItems());
        $results->setTotalCount($collection->getSize());
        return $results;
    }
}
