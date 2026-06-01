<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Api;

use MageMe\EUWithdrawal\Api\Data\RequestInterface;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SearchResultsInterface;
use Magento\Framework\Exception\NoSuchEntityException;

interface RequestRepositoryInterface
{
    /**
     * @throws NoSuchEntityException
     */
    public function get(int $requestId): RequestInterface;

    /**
     * Save.
     *
     * @param RequestInterface $request
     * @return RequestInterface
     */
    public function save(RequestInterface $request): RequestInterface;

    /**
     * Get list.
     *
     * @param SearchCriteriaInterface $criteria
     * @return SearchResultsInterface
     */
    public function getList(SearchCriteriaInterface $criteria): SearchResultsInterface;
}
