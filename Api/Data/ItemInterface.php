<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Api\Data;

interface ItemInterface
{
    public const ITEM_ID        = 'item_id';
    public const REQUEST_ID     = 'request_id';
    public const ORDER_ITEM_ID  = 'order_item_id';
    public const SKU            = 'sku';
    public const QTY_WITHDRAW   = 'qty_withdraw';
    public const REFUND_AMOUNT  = 'refund_amount';
    public const ELIGIBILITY    = 'eligibility';
    public const EXCLUSION_BASIS = 'exclusion_basis';
    public const REASON_CODE    = 'reason_code';
    public const REASON_TEXT    = 'reason_text';

    /** Get item id. */
    public function getItemId(): ?int;

    /** Set item id. */
    public function setItemId(?int $itemId): self;

    /** Get request id. */
    public function getRequestId(): int;

    /** Set request id. */
    public function setRequestId(int $requestId): self;

    /** Get order item id. */
    public function getOrderItemId(): int;

    /** Set order item id. */
    public function setOrderItemId(int $orderItemId): self;

    /** Get sku. */
    public function getSku(): string;

    /** Set sku. */
    public function setSku(string $sku): self;

    /** Get qty withdraw. */
    public function getQtyWithdraw(): string;   // decimal(12,4) as string

    /** Set qty withdraw. */
    public function setQtyWithdraw(string $qtyWithdraw): self;

    /** Get refund amount. */
    public function getRefundAmount(): string;  // decimal(12,4) as string

    /** Set refund amount. */
    public function setRefundAmount(string $refundAmount): self;

    /** Get eligibility. */
    public function getEligibility(): string;

    /** Set eligibility. */
    public function setEligibility(string $eligibility): self;

    /** Get exclusion basis. */
    public function getExclusionBasis(): ?string;

    /** Set exclusion basis. */
    public function setExclusionBasis(?string $exclusionBasis): self;

    /** Get reason code. */
    public function getReasonCode(): ?string;

    /** Set reason code. */
    public function setReasonCode(?string $reasonCode): self;

    /** Get reason text. */
    public function getReasonText(): ?string;

    /** Set reason text. */
    public function setReasonText(?string $reasonText): self;
}
