<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Api\Data;

interface RequestInterface
{
    public const STATUS_PENDING         = 'pending';
    public const STATUS_APPROVED        = 'approved';
    public const STATUS_DENIED          = 'denied';
    public const STATUS_CANCELLED       = 'cancelled';
    public const STATUS_ANONYMISED      = 'anonymised';

    public const ALL_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_APPROVED, self::STATUS_DENIED,
        self::STATUS_CANCELLED, self::STATUS_ANONYMISED,
    ];

    public const REQUEST_ID               = 'request_id';
    public const INCREMENT_ID             = 'increment_id';
    public const ORDER_ID                 = 'order_id';
    public const STORE_ID                 = 'store_id';
    public const JURISDICTION             = 'jurisdiction';
    public const CUSTOMER_ID              = 'customer_id';
    public const CUSTOMER_EMAIL           = 'customer_email';
    public const CUSTOMER_NAME            = 'customer_name';
    public const CONTRACT_IDENTIFIER      = 'contract_identifier';
    public const CONTRACT_TYPE            = 'contract_type';
    public const STATUS                   = 'status';
    public const REASON_TEXT              = 'reason_text';
    public const IS_PARTIAL               = 'is_partial';
    public const LOCALE                   = 'locale';
    public const IP                       = 'ip';
    public const USER_AGENT               = 'user_agent';
    public const CONTENT_HASH             = 'content_hash';
    public const RECEIPT_SNAPSHOT         = 'receipt_snapshot';
    public const PRO_RATA_REFUND          = 'pro_rata_refund';
    public const SHIPPING_REFUND          = 'shipping_refund';
    public const ORDER_ADJUSTMENT_REFUND  = 'order_adjustment_refund';
    public const ITEMS_SUBTOTAL           = 'items_subtotal';
    public const TAX_REFUND               = 'tax_refund';
    public const TOTAL_REFUND             = 'total_refund';
    public const SUBMITTED_AT             = 'submitted_at';
    public const ACKNOWLEDGED_AT          = 'acknowledged_at';
    public const RECEIPT_STATUS           = 'receipt_status';
    public const RECEIPT_SEND_ATTEMPTS    = 'receipt_send_attempts';
    public const RECEIPT_NEXT_SEND_AT     = 'receipt_next_send_at';
    public const RECEIPT_LAST_ERROR       = 'receipt_last_error';
    public const PERIOD_END_AT            = 'period_end_at';
    public const ANONYMISED_AT            = 'anonymised_at';
    public const REFUND_CREDITMEMO_ID     = 'refund_creditmemo_id';
    public const CREATED_AT               = 'created_at';
    public const UPDATED_AT               = 'updated_at';
    public const STATUS_CHANGE_NOTE       = 'status_change_note';
    public const STATUS_CHANGE_LEGAL_BASIS = 'status_change_legal_basis';
    public const STATUS_CHANGE_ACTOR      = 'status_change_actor';
    public const REIMBURSEMENT_WITHHELD_AT = 'reimbursement_withheld_at';
    public const REIMBURSEMENT_LAST_ALERTED_AT = 'reimbursement_last_alerted_at';
    public const REIMBURSEMENT_PAID_AT     = 'reimbursement_paid_at';

    /** Get request id. */
    public function getRequestId(): int;

    /** Set request id. */
    public function setRequestId(int $requestId): self;

    /** Get increment id. */
    public function getIncrementId(): ?string;

    /** Set increment id. */
    public function setIncrementId(?string $incrementId): self;

    /** Get order id. */
    public function getOrderId(): int;

    /** Set order id. */
    public function setOrderId(int $orderId): self;

    /** Get store id. */
    public function getStoreId(): int;

    /** Set store id. */
    public function setStoreId(int $storeId): self;

    /** Get jurisdiction. */
    public function getJurisdiction(): string;

    /** Set jurisdiction. */
    public function setJurisdiction(string $jurisdiction): self;

    /** Get customer id. */
    public function getCustomerId(): ?int;

    /** Set customer id. */
    public function setCustomerId(?int $customerId): self;

    /** Get customer email. */
    public function getCustomerEmail(): ?string;

    /** Set customer email. */
    public function setCustomerEmail(?string $customerEmail): self;

    /** Get customer name. */
    public function getCustomerName(): ?string;

    /** Set customer name. */
    public function setCustomerName(?string $customerName): self;

    /** Get contract identifier. */
    public function getContractIdentifier(): string;

    /** Set contract identifier. */
    public function setContractIdentifier(string $contractIdentifier): self;

    /** Get contract type. */
    public function getContractType(): string;

    /** Set contract type. */
    public function setContractType(string $contractType): self;

    /** Get status. */
    public function getStatus(): string;

    /** Set status. */
    public function setStatus(string $status): self;

    /** Get reason text. */
    public function getReasonText(): ?string;

    /** Set reason text. */
    public function setReasonText(?string $reasonText): self;

    /** Get is partial. */
    public function getIsPartial(): int;

    /** Set is partial. */
    public function setIsPartial(int $isPartial): self;

    /** Get locale. */
    public function getLocale(): string;

    /** Set locale. */
    public function setLocale(string $locale): self;

    /** Get ip. */
    public function getIp(): ?string;

    /** Set ip. */
    public function setIp(?string $ip): self;

    /** Get user agent. */
    public function getUserAgent(): ?string;

    /** Set user agent. */
    public function setUserAgent(?string $userAgent): self;

    /** Get content hash. */
    public function getContentHash(): ?string;

    /** Set content hash. */
    public function setContentHash(?string $contentHash): self;

    /** Get pro rata refund. */
    public function getProRataRefund(): ?string;

    /** Set pro rata refund. */
    public function setProRataRefund(?string $proRataRefund): self;

    /** Get shipping refund. */
    public function getShippingRefund(): ?string;

    /** Set shipping refund. */
    public function setShippingRefund(?string $shippingRefund): self;

    /** Get order adjustment refund. */
    public function getOrderAdjustmentRefund(): ?string;

    /** Set order adjustment refund. */
    public function setOrderAdjustmentRefund(?string $orderAdjustmentRefund): self;

    /** Get items subtotal (net items refund), frozen at consent time. */
    public function getItemsSubtotal(): ?string;

    /** Set items subtotal. */
    public function setItemsSubtotal(?string $itemsSubtotal): self;

    /** Get tax refund (combined items + shipping VAT), frozen at consent time. */
    public function getTaxRefund(): ?string;

    /** Set tax refund. */
    public function setTaxRefund(?string $taxRefund): self;

    /** Get total refund, frozen at consent time. */
    public function getTotalRefund(): ?string;

    /** Set total refund. */
    public function setTotalRefund(?string $totalRefund): self;

    /** Get submitted at. */
    public function getSubmittedAt(): string;

    /** Set submitted at. */
    public function setSubmittedAt(string $submittedAt): self;

    /** Get acknowledged at. */
    public function getAcknowledgedAt(): ?string;

    /** Set acknowledged at. */
    public function setAcknowledgedAt(?string $acknowledgedAt): self;

    /** Get receipt status. */
    public function getReceiptStatus(): string;

    /** Set receipt status. */
    public function setReceiptStatus(string $receiptStatus): self;

    /** Get receipt send attempts. */
    public function getReceiptSendAttempts(): int;

    /** Set receipt send attempts. */
    public function setReceiptSendAttempts(int $receiptSendAttempts): self;

    /** Get receipt next send at. */
    public function getReceiptNextSendAt(): ?string;

    /** Set receipt next send at. */
    public function setReceiptNextSendAt(?string $receiptNextSendAt): self;

    /** Get receipt last error. */
    public function getReceiptLastError(): ?string;

    /** Set receipt last error. */
    public function setReceiptLastError(?string $receiptLastError): self;

    /** Get period end at. */
    public function getPeriodEndAt(): ?string;

    /** Set period end at. */
    public function setPeriodEndAt(?string $periodEndAt): self;

    /** Get anonymised at. */
    public function getAnonymisedAt(): ?string;

    /** Set anonymised at. */
    public function setAnonymisedAt(?string $anonymisedAt): self;

    /** Get refund creditmemo id. */
    public function getRefundCreditmemoId(): ?int;

    /** Set refund creditmemo id. */
    public function setRefundCreditmemoId(?int $refundCreditmemoId): self;

    /** Get created at. */
    public function getCreatedAt(): string;

    /** Set created at. */
    public function setCreatedAt(string $createdAt): self;

    /** Get updated at. */
    public function getUpdatedAt(): string;

    /** Set updated at. */
    public function setUpdatedAt(string $updatedAt): self;

    /** Get status change note. */
    public function getStatusChangeNote(): ?string;

    /** Set status change note. */
    public function setStatusChangeNote(?string $statusChangeNote): self;

    /** Get status change legal basis. */
    public function getStatusChangeLegalBasis(): ?string;

    /** Set status change legal basis. */
    public function setStatusChangeLegalBasis(?string $statusChangeLegalBasis): self;

    /** Get status change actor. */
    public function getStatusChangeActor(): ?string;

    /** Set status change actor. */
    public function setStatusChangeActor(?string $statusChangeActor): self;

    /** Get Art. 13(3) reimbursement withheld-at timestamp (UTC), or null. */
    public function getReimbursementWithheldAt(): ?string;

    /** Set Art. 13(3) reimbursement withheld-at timestamp (UTC); null lifts it. */
    public function setReimbursementWithheldAt(?string $reimbursementWithheldAt): self;

    /** Get manual "reimbursement paid" mark timestamp (UTC), or null. */
    public function getReimbursementPaidAt(): ?string;

    /** Set manual "reimbursement paid" mark timestamp (UTC); null clears it. */
    public function setReimbursementPaidAt(?string $reimbursementPaidAt): self;
}
