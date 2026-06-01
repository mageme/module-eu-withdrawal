<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Ui\DataProvider\Request;

use MageMe\EUWithdrawal\Model\ResourceModel\Request\Grid\Collection;
use MageMe\EUWithdrawal\Model\ResourceModel\Request\Grid\CollectionFactory;
use Magento\Framework\Api\FilterBuilder;
use Magento\Framework\Api\Search\ReportingInterface;
use Magento\Framework\Api\Search\SearchCriteriaBuilder;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\View\Element\UiComponent\DataProvider\DataProvider;

class ListingProvider extends DataProvider
{
    /**
     * @var Collection
     */
    protected $collection;

    /**
     * Constructor.
     *
     * @param string $name
     * @param string $primaryFieldName
     * @param string $requestFieldName
     * @param ReportingInterface $reporting
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param RequestInterface $request
     * @param FilterBuilder $filterBuilder
     * @param CollectionFactory $collectionFactory
     * @param array $meta
     * @param array $data
     */
    public function __construct(
        string $name,
        string $primaryFieldName,
        string $requestFieldName,
        ReportingInterface $reporting,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        RequestInterface $request,
        FilterBuilder $filterBuilder,
        private readonly CollectionFactory $collectionFactory,
        array $meta = [],
        array $data = [],
    ) {
        parent::__construct(
            $name,
            $primaryFieldName,
            $requestFieldName,
            $reporting,
            $searchCriteriaBuilder,
            $request,
            $filterBuilder,
            $meta,
            $data,
        );
        $this->collection = $this->collectionFactory->create();
    }

    /**
     * Return the grid collection.
     *
     * @return Collection
     */
    public function getCollection(): Collection
    {
        return $this->collection;
    }

    /**
     * Grid models extend AbstractModel (not AbstractExtensibleModel), so the
     * parent DataProvider's call to $item->getCustomAttributes() returns null
     * and blows up in foreach. Return plain row arrays directly.
     *
     * @return array<string, mixed>
     */
    public function getData()
    {
        $collection = $this->getSearchResult();
        $items = [];
        foreach ($collection->getItems() as $row) {
            $data = $row instanceof \Magento\Framework\DataObject ? $row->getData() : (array) $row;
            // `ip` is stored as varbinary(16) — raw bytes make json_encode() fail with
            // JSON_HEX_TAG ("Unable to serialize value") when the grid payload is built
            // for the initial layout config. Decode to a printable string here.
            if (isset($data['ip']) && $data['ip'] !== null && $data['ip'] !== '') {
                $decoded = ((is_string((string) $data['ip']) && in_array(strlen((string) $data['ip']), [4, 16], true)) ? inet_ntop((string) $data['ip']) : false);
                $data['ip'] = $decoded !== false ? $decoded : '';
            }
            $items[] = $data;
        }
        return [
            'totalRecords' => $collection->getTotalCount(),
            'items'        => $items,
        ];
    }
}
