<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Item;

use MageMe\EUWithdrawal\Api\Data\EligibilityRequestInterface;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\Data\OrderInterface;

/**
 * Reads a boolean Art. 16 flag for the order line under evaluation.
 *
 * The line's own product is checked first, then the products behind its child
 * lines — a configurable line carries the parent product, whose variants hold
 * their own attribute values, and a bundle line carries the bundle product
 * while its components hold theirs.
 */
class ProductFlagReader
{
    /** @var array<int, array<int, int[]>> Order id => parent item id => child product ids. */
    private array $childProductIds = [];

    /** @var array<string, bool> "<product id>:<attribute code>" => flag. */
    private array $flags = [];

    /**
     * Constructor.
     *
     * @param ProductRepositoryInterface $productRepository
     */
    public function __construct(private readonly ProductRepositoryInterface $productRepository)
    {
    }

    /**
     * Whether the flag is set on the line's product or on any of its children.
     *
     * @param EligibilityRequestInterface $request
     * @param string $attributeCode
     * @return bool
     */
    public function isSet(EligibilityRequestInterface $request, string $attributeCode): bool
    {
        if ($this->isFlagged($request->getCurrentProduct(), $attributeCode)) {
            return true;
        }

        $item = $request->getCurrentItem();
        if ($item === null) {
            return false;
        }

        $order = $request->getOrder();
        $children = $this->childProductIds($order)[(int) $item->getItemId()] ?? [];
        foreach ($children as $productId) {
            if ($this->isChildFlagged($productId, $attributeCode)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Child product ids of every line of the order, indexed by parent item id.
     *
     * @param OrderInterface $order
     * @return array<int, int[]>
     */
    private function childProductIds(OrderInterface $order): array
    {
        $orderKey = (int) $order->getEntityId();
        if ($orderKey === 0) {
            return $this->indexChildren($order);
        }

        return $this->childProductIds[$orderKey] ??= $this->indexChildren($order);
    }

    /**
     * Index children.
     *
     * @param OrderInterface $order
     * @return array<int, int[]>
     */
    private function indexChildren(OrderInterface $order): array
    {
        $index = [];
        foreach ($order->getItems() ?? [] as $item) {
            if ($item->getParentItemId() === null) {
                continue;
            }
            $index[(int) $item->getParentItemId()][] = (int) $item->getProductId();
        }

        return $index;
    }

    /**
     * Is child flagged.
     *
     * @param int $productId
     * @param string $attributeCode
     * @return bool
     */
    private function isChildFlagged(int $productId, string $attributeCode): bool
    {
        $key = $productId . ':' . $attributeCode;
        if (isset($this->flags[$key])) {
            return $this->flags[$key];
        }

        try {
            $product = $this->productRepository->getById($productId);
        } catch (NoSuchEntityException) {
            return $this->flags[$key] = false;
        }

        return $this->flags[$key] = $this->isFlagged($product, $attributeCode);
    }

    /**
     * Is flagged.
     *
     * @param ?ProductInterface $product
     * @param string $attributeCode
     * @return bool
     */
    private function isFlagged(?ProductInterface $product, string $attributeCode): bool
    {
        if ($product === null) {
            return false;
        }
        $attr = $product->getCustomAttribute($attributeCode);
        return $attr !== null && (int) $attr->getValue() === 1;
    }
}
