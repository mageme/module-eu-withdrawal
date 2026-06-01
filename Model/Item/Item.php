<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Item;

use MageMe\EUWithdrawal\Api\Data\ItemInterface;
use MageMe\EUWithdrawal\Model\ResourceModel\Item\Item as ItemResource;
use Magento\Framework\Model\AbstractModel;

class Item extends AbstractModel implements ItemInterface
{
    /**
     * Construct.
     *
     * @return void
     */
    protected function _construct(): void
    {
        $this->_init(ItemResource::class);
    }

    /** Get item id. */
    public function getItemId(): ?int
    {
        $v = $this->getData(self::ITEM_ID);
        return $v === null ? null : (int) $v;
    }

    /** Set item id. */
    public function setItemId(?int $itemId): self
    {
        $this->setData(self::ITEM_ID, $itemId);
        return $this;
    }

    /** Get request id. */
    public function getRequestId(): int
    {
        return (int) $this->getData(self::REQUEST_ID);
    }

    /** Set request id. */
    public function setRequestId(int $requestId): self
    {
        $this->setData(self::REQUEST_ID, $requestId);
        return $this;
    }

    /** Get order item id. */
    public function getOrderItemId(): int
    {
        return (int) $this->getData(self::ORDER_ITEM_ID);
    }

    /** Set order item id. */
    public function setOrderItemId(int $orderItemId): self
    {
        $this->setData(self::ORDER_ITEM_ID, $orderItemId);
        return $this;
    }

    /** Get sku. */
    public function getSku(): string
    {
        return (string) $this->getData(self::SKU);
    }

    /** Set sku. */
    public function setSku(string $sku): self
    {
        $this->setData(self::SKU, $sku);
        return $this;
    }

    /** Get qty withdraw. */
    public function getQtyWithdraw(): string
    {
        return (string) $this->getData(self::QTY_WITHDRAW);
    }

    /** Set qty withdraw. */
    public function setQtyWithdraw(string $qtyWithdraw): self
    {
        $this->setData(self::QTY_WITHDRAW, $qtyWithdraw);
        return $this;
    }

    /** Get refund amount. */
    public function getRefundAmount(): string
    {
        return (string) $this->getData(self::REFUND_AMOUNT);
    }

    /** Set refund amount. */
    public function setRefundAmount(string $refundAmount): self
    {
        $this->setData(self::REFUND_AMOUNT, $refundAmount);
        return $this;
    }

    /** Get eligibility. */
    public function getEligibility(): string
    {
        return (string) $this->getData(self::ELIGIBILITY);
    }

    /** Set eligibility. */
    public function setEligibility(string $eligibility): self
    {
        $this->setData(self::ELIGIBILITY, $eligibility);
        return $this;
    }

    /** Get exclusion basis. */
    public function getExclusionBasis(): ?string
    {
        $v = $this->getData(self::EXCLUSION_BASIS);
        return $v === null ? null : (string) $v;
    }

    /** Set exclusion basis. */
    public function setExclusionBasis(?string $exclusionBasis): self
    {
        $this->setData(self::EXCLUSION_BASIS, $exclusionBasis);
        return $this;
    }

    /** Get reason code. */
    public function getReasonCode(): ?string
    {
        $v = $this->getData(self::REASON_CODE);
        return $v === null || $v === '' ? null : (string) $v;
    }

    /** Set reason code. */
    public function setReasonCode(?string $reasonCode): self
    {
        $this->setData(self::REASON_CODE, $reasonCode);
        return $this;
    }

    /** Get reason text. */
    public function getReasonText(): ?string
    {
        $v = $this->getData(self::REASON_TEXT);
        return $v === null || $v === '' ? null : (string) $v;
    }

    /** Set reason text. */
    public function setReasonText(?string $reasonText): self
    {
        $this->setData(self::REASON_TEXT, $reasonText);
        return $this;
    }
}
