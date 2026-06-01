<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Block\Withdraw;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Helper\Image as ImageHelper;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\Data\OrderItemInterface;

/**
 * Resolves a product thumbnail URL for an order item. Tries a few image-helper
 * types before giving up — Luma's `cart_thumbnail` sometimes returns a broken
 * `placeholder/.jpg` URL when no placeholder image is configured;
 * `product_small_image` and `category_page_list` are reliable fallbacks
 * because they key off `small_image`, which every sample product carries.
 * Returns '' when no usable URL is found so the template can hide the img.
 */
class ProductThumbnail
{
    private const TYPES = ['product_small_image', 'category_page_list', 'cart_thumbnail'];

    /**
     * Constructor.
     *
     * @param ProductRepositoryInterface $productRepository
     * @param ImageHelper $imageHelper
     */
    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly ImageHelper $imageHelper,
    ) {
    }

    /**
     * Url for.
     *
     * @param OrderItemInterface $item
     * @return string
     */
    public function urlFor(OrderItemInterface $item): string
    {
        $productId = (int) $item->getProductId();
        if ($productId <= 0) {
            return '';
        }
        try {
            $product = $this->productRepository->getById($productId);
        } catch (NoSuchEntityException) {
            return '';
        }
        foreach (self::TYPES as $type) {
            $url = (string) $this->imageHelper->init($product, $type)->getUrl();
            if ($url !== '' && !$this->isBrokenPlaceholder($url)) {
                return $url;
            }
        }
        return '';
    }

    /**
     * Is broken placeholder.
     *
     * @param string $url
     * @return bool
     */
    private function isBrokenPlaceholder(string $url): bool
    {
        return (bool) preg_match('#/placeholder/\.(jpg|jpeg|png|gif|webp)$#i', $url);
    }
}
