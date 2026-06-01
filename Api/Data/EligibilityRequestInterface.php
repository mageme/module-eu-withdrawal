<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Api\Data;

use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderItemInterface;
use Magento\Catalog\Api\Data\ProductInterface;

interface EligibilityRequestInterface
{
    /**
     * Get order.
     *
     * @return OrderInterface
     */
    public function getOrder(): OrderInterface;

    /**
     * Get contract type.
     *
     * @return string
     */
    public function getContractType(): string;

    /**
     * Get store id.
     *
     * @return int
     */
    public function getStoreId(): int;

    /**
     * Get submitted at.
     *
     * @return \DateTimeImmutable
     */
    public function getSubmittedAt(): \DateTimeImmutable;

    /**
     * Get current item.
     *
     * @return ?OrderItemInterface
     */
    public function getCurrentItem(): ?OrderItemInterface;

    /**
     * Get current product.
     *
     * @return ?ProductInterface
     */
    public function getCurrentProduct(): ?ProductInterface;

    /**
     * Get customer declaration.
     *
     * @param string $key
     * @return ?bool
     */
    public function getCustomerDeclaration(string $key): ?bool;

    /**
     * With item.
     *
     * @param OrderItemInterface $item
     * @param ProductInterface $product
     * @return self
     */
    public function withItem(OrderItemInterface $item, ProductInterface $product): self;
}
