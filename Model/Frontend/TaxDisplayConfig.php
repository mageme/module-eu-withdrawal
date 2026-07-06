<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Frontend;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Tax\Model\Config as TaxConfig;

class TaxDisplayConfig
{
    public function __construct(
        private readonly TaxConfig $taxConfig,
        private readonly StoreManagerInterface $storeManager,
    ) {
    }

    /**
     * Whether the refund summary should present prices including tax. Mirrors
     * how placed-order totals are shown (sales-display group): "Including Tax"
     * or "Both" => incl; "Excluding Tax" => excl. Resolves the current store
     * when $storeId is null.
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isInclTax(?int $storeId = null): bool
    {
        $storeId = $this->resolveStoreId($storeId);
        return $this->taxConfig->displaySalesSubtotalInclTax($storeId)
            || $this->taxConfig->displaySalesSubtotalBoth($storeId);
    }

    /**
     * Whether tax is folded into the grand total instead of shown as its own
     * line. Mirrors the sales-display "grandtotal" setting that core uses in
     * \Magento\Tax\Block\Sales\Order\Tax::initTotals() to decide whether a
     * standalone Tax row is added to order/invoice/credit-memo totals.
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isTaxFoldedIntoTotal(?int $storeId = null): bool
    {
        return $this->taxConfig->displaySalesTaxWithGrandTotal($this->resolveStoreId($storeId));
    }

    /**
     * Whether the standalone tax line should be suppressed. Only in incl mode
     * may the line be folded into the gross total; in excl mode the net rows
     * need the tax line to remain so the figures add up.
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isTaxLineHidden(?int $storeId = null): bool
    {
        $storeId = $this->resolveStoreId($storeId);
        return $this->isInclTax($storeId) && $this->isTaxFoldedIntoTotal($storeId);
    }

    /**
     * Resolve the current store id when none is given.
     *
     * @param int|null $storeId
     * @return int|null
     */
    private function resolveStoreId(?int $storeId): ?int
    {
        if ($storeId !== null) {
            return $storeId;
        }
        try {
            return (int) $this->storeManager->getStore()->getId();
        } catch (NoSuchEntityException) {
            return null;
        }
    }
}
