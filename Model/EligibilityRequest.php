<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model;

use MageMe\EUWithdrawal\Api\Data\EligibilityRequestInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderItemInterface;
use Magento\Catalog\Api\Data\ProductInterface;

class EligibilityRequest implements EligibilityRequestInterface
{
    /**
     * @param array<string, bool> $customerDeclarations
     */
    public function __construct(
        private readonly OrderInterface $order,
        private readonly string $contractType,
        private readonly int $storeId,
        private readonly \DateTimeImmutable $submittedAt,
        private readonly ?OrderItemInterface $currentItem = null,
        private readonly ?ProductInterface $currentProduct = null,
        private readonly array $customerDeclarations = [],
    ) {
    }

    /**
     * Get order.
     *
     * @return OrderInterface
     */
    public function getOrder(): OrderInterface
    {
        return $this->order;
    }

    /**
     * Get contract type.
     *
     * @return string
     */
    public function getContractType(): string
    {
        return $this->contractType;
    }

    /**
     * Get store id.
     *
     * @return int
     */
    public function getStoreId(): int
    {
        return $this->storeId;
    }

    /**
     * Get submitted at.
     *
     * @return \DateTimeImmutable
     */
    public function getSubmittedAt(): \DateTimeImmutable
    {
        return $this->submittedAt;
    }

    /**
     * Get current item.
     *
     * @return ?OrderItemInterface
     */
    public function getCurrentItem(): ?OrderItemInterface
    {
        return $this->currentItem;
    }

    /**
     * Get current product.
     *
     * @return ?ProductInterface
     */
    public function getCurrentProduct(): ?ProductInterface
    {
        return $this->currentProduct;
    }

    /**
     * Get customer declaration.
     *
     * @param string $key
     * @return ?bool
     */
    public function getCustomerDeclaration(string $key): ?bool
    {
        return $this->customerDeclarations[$key] ?? null;
    }

    /**
     * With item.
     *
     * @param OrderItemInterface $item
     * @param ProductInterface $product
     * @return EligibilityRequestInterface
     */
    public function withItem(OrderItemInterface $item, ProductInterface $product): EligibilityRequestInterface
    {
        return new self(
            order: $this->order,
            contractType: $this->contractType,
            storeId: $this->storeId,
            submittedAt: $this->submittedAt,
            currentItem: $item,
            currentProduct: $product,
            customerDeclarations: $this->customerDeclarations,
        );
    }
}
