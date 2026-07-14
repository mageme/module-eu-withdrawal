<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Seal;

use MageMe\EUWithdrawal\Api\Seal\SealKindResolverInterface;
use MageMe\EUWithdrawal\Model\Rule\Preset\SealedAVPreset;
use MageMe\EUWithdrawal\Model\Rule\Preset\SealedHygienePreset;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;

class SealKindResolver implements SealKindResolverInterface
{
    /** @var array<string, SealKind> */
    private array $cache = [];

    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
    ) {
    }

    public function resolve(int $productId, int $storeId): SealKind
    {
        $key = $productId . ':' . $storeId;
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }
        try {
            $product = $this->productRepository->getById($productId, false, $storeId, false);
        } catch (NoSuchEntityException) {
            return $this->cache[$key] = SealKind::NONE;
        }
        return $this->cache[$key] = $this->fromProduct($product);
    }

    private function fromProduct(ProductInterface $product): SealKind
    {
        if ($this->flag($product, SealedHygienePreset::ATTRIBUTE)) {
            return SealKind::HYGIENE;
        }
        if ($this->flag($product, SealedAVPreset::ATTRIBUTE)) {
            return SealKind::AV;
        }
        return SealKind::NONE;
    }

    private function flag(ProductInterface $product, string $code): bool
    {
        $attr = $product->getCustomAttribute($code);
        return $attr !== null && (int) $attr->getValue() === 1;
    }
}
