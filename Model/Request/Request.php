<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Request;

use MageMe\EUWithdrawal\Api\Data\RequestInterface;
use MageMe\EUWithdrawal\Model\ResourceModel\Request as RequestResource;
use Magento\Framework\Model\AbstractModel;

class Request extends AbstractModel implements RequestInterface
{
    /**
     * Construct.
     *
     * @return void
     */
    protected function _construct(): void
    {
        $this->_init(RequestResource::class);
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

    /** Get increment id. */
    public function getIncrementId(): ?string
    {
        $v = $this->getData(self::INCREMENT_ID);
        return $v === null ? null : (string) $v;
    }

    /** Set increment id. */
    public function setIncrementId(?string $incrementId): self
    {
        $this->setData(self::INCREMENT_ID, $incrementId);
        return $this;
    }

    /** Get order id. */
    public function getOrderId(): int
    {
        return (int) $this->getData(self::ORDER_ID);
    }

    /** Set order id. */
    public function setOrderId(int $orderId): self
    {
        $this->setData(self::ORDER_ID, $orderId);
        return $this;
    }

    /** Get store id. */
    public function getStoreId(): int
    {
        return (int) $this->getData(self::STORE_ID);
    }

    /** Set store id. */
    public function setStoreId(int $storeId): self
    {
        $this->setData(self::STORE_ID, $storeId);
        return $this;
    }

    /** Get jurisdiction. */
    public function getJurisdiction(): string
    {
        return (string) $this->getData(self::JURISDICTION);
    }

    /** Set jurisdiction. */
    public function setJurisdiction(string $jurisdiction): self
    {
        $this->setData(self::JURISDICTION, $jurisdiction);
        return $this;
    }

    /** Get customer id. */
    public function getCustomerId(): ?int
    {
        $v = $this->getData(self::CUSTOMER_ID);
        return $v === null ? null : (int) $v;
    }

    /** Set customer id. */
    public function setCustomerId(?int $customerId): self
    {
        $this->setData(self::CUSTOMER_ID, $customerId);
        return $this;
    }

    /** Get customer email. */
    public function getCustomerEmail(): ?string
    {
        $v = $this->getData(self::CUSTOMER_EMAIL);
        return $v === null ? null : (string) $v;
    }

    /** Set customer email. */
    public function setCustomerEmail(?string $customerEmail): self
    {
        $this->setData(self::CUSTOMER_EMAIL, $customerEmail);
        return $this;
    }

    /** Get customer name. */
    public function getCustomerName(): ?string
    {
        $v = $this->getData(self::CUSTOMER_NAME);
        return $v === null ? null : (string) $v;
    }

    /** Set customer name. */
    public function setCustomerName(?string $customerName): self
    {
        $this->setData(self::CUSTOMER_NAME, $customerName);
        return $this;
    }

    /** Get contract identifier. */
    public function getContractIdentifier(): string
    {
        return (string) $this->getData(self::CONTRACT_IDENTIFIER);
    }

    /** Set contract identifier. */
    public function setContractIdentifier(string $contractIdentifier): self
    {
        $this->setData(self::CONTRACT_IDENTIFIER, $contractIdentifier);
        return $this;
    }

    /** Get contract type. */
    public function getContractType(): string
    {
        return (string) $this->getData(self::CONTRACT_TYPE);
    }

    /** Set contract type. */
    public function setContractType(string $contractType): self
    {
        $this->setData(self::CONTRACT_TYPE, $contractType);
        return $this;
    }

    /** Get status. */
    public function getStatus(): string
    {
        return (string) $this->getData(self::STATUS);
    }

    /** Set status. */
    public function setStatus(string $status): self
    {
        $this->setData(self::STATUS, $status);
        return $this;
    }

    /** Get reason text. */
    public function getReasonText(): ?string
    {
        $v = $this->getData(self::REASON_TEXT);
        return $v === null ? null : (string) $v;
    }

    /** Set reason text. */
    public function setReasonText(?string $reasonText): self
    {
        $this->setData(self::REASON_TEXT, $reasonText);
        return $this;
    }

    /** Get is partial. */
    public function getIsPartial(): int
    {
        return (int) $this->getData(self::IS_PARTIAL);
    }

    /** Set is partial. */
    public function setIsPartial(int $isPartial): self
    {
        $this->setData(self::IS_PARTIAL, $isPartial);
        return $this;
    }

    /** Get locale. */
    public function getLocale(): string
    {
        return (string) $this->getData(self::LOCALE);
    }

    /** Set locale. */
    public function setLocale(string $locale): self
    {
        $this->setData(self::LOCALE, $locale);
        return $this;
    }

    /** Get ip. */
    public function getIp(): ?string
    {
        $v = $this->getData(self::IP);
        return $v === null ? null : (string) $v;
    }

    /** Set ip. */
    public function setIp(?string $ip): self
    {
        $this->setData(self::IP, $ip);
        return $this;
    }

    /** Get user agent. */
    public function getUserAgent(): ?string
    {
        $v = $this->getData(self::USER_AGENT);
        return $v === null ? null : (string) $v;
    }

    /** Set user agent. */
    public function setUserAgent(?string $userAgent): self
    {
        $this->setData(self::USER_AGENT, $userAgent);
        return $this;
    }

    /** Get content hash. */
    public function getContentHash(): ?string
    {
        $v = $this->getData(self::CONTENT_HASH);
        return $v === null ? null : (string) $v;
    }

    /** Set content hash. */
    public function setContentHash(?string $contentHash): self
    {
        $this->setData(self::CONTENT_HASH, $contentHash);
        return $this;
    }

    /** Get pro rata refund. */
    public function getProRataRefund(): ?string
    {
        $v = $this->getData(self::PRO_RATA_REFUND);
        return $v === null ? null : (string) $v;
    }

    /** Set pro rata refund. */
    public function setProRataRefund(?string $proRataRefund): self
    {
        $this->setData(self::PRO_RATA_REFUND, $proRataRefund);
        return $this;
    }

    /** Get shipping refund. */
    public function getShippingRefund(): ?string
    {
        $v = $this->getData(self::SHIPPING_REFUND);
        return $v === null ? null : (string) $v;
    }

    /** Set shipping refund. */
    public function setShippingRefund(?string $shippingRefund): self
    {
        $this->setData(self::SHIPPING_REFUND, $shippingRefund);
        return $this;
    }

    /** Get order adjustment refund. */
    public function getOrderAdjustmentRefund(): ?string
    {
        $v = $this->getData(self::ORDER_ADJUSTMENT_REFUND);
        return $v === null ? null : (string) $v;
    }

    /** Set order adjustment refund. */
    public function setOrderAdjustmentRefund(?string $orderAdjustmentRefund): self
    {
        $this->setData(self::ORDER_ADJUSTMENT_REFUND, $orderAdjustmentRefund);
        return $this;
    }

    /** Get items subtotal. */
    public function getItemsSubtotal(): ?string
    {
        $v = $this->getData(self::ITEMS_SUBTOTAL);
        return $v === null ? null : (string) $v;
    }

    /** Set items subtotal. */
    public function setItemsSubtotal(?string $itemsSubtotal): self
    {
        $this->setData(self::ITEMS_SUBTOTAL, $itemsSubtotal);
        return $this;
    }

    /** Get tax refund. */
    public function getTaxRefund(): ?string
    {
        $v = $this->getData(self::TAX_REFUND);
        return $v === null ? null : (string) $v;
    }

    /** Set tax refund. */
    public function setTaxRefund(?string $taxRefund): self
    {
        $this->setData(self::TAX_REFUND, $taxRefund);
        return $this;
    }

    /** Get total refund. */
    public function getTotalRefund(): ?string
    {
        $v = $this->getData(self::TOTAL_REFUND);
        return $v === null ? null : (string) $v;
    }

    /** Set total refund. */
    public function setTotalRefund(?string $totalRefund): self
    {
        $this->setData(self::TOTAL_REFUND, $totalRefund);
        return $this;
    }

    /** Get submitted at. */
    public function getSubmittedAt(): string
    {
        return (string) $this->getData(self::SUBMITTED_AT);
    }

    /** Set submitted at. */
    public function setSubmittedAt(string $submittedAt): self
    {
        $this->setData(self::SUBMITTED_AT, $submittedAt);
        return $this;
    }

    /** Get acknowledged at. */
    public function getAcknowledgedAt(): ?string
    {
        $v = $this->getData(self::ACKNOWLEDGED_AT);
        return $v === null ? null : (string) $v;
    }

    /** Set acknowledged at. */
    public function setAcknowledgedAt(?string $acknowledgedAt): self
    {
        $this->setData(self::ACKNOWLEDGED_AT, $acknowledgedAt);
        return $this;
    }

    /** Get receipt status. */
    public function getReceiptStatus(): string
    {
        return (string) $this->getData(self::RECEIPT_STATUS);
    }

    /** Set receipt status. */
    public function setReceiptStatus(string $receiptStatus): self
    {
        $this->setData(self::RECEIPT_STATUS, $receiptStatus);
        return $this;
    }

    /** Get receipt send attempts. */
    public function getReceiptSendAttempts(): int
    {
        return (int) $this->getData(self::RECEIPT_SEND_ATTEMPTS);
    }

    /** Set receipt send attempts. */
    public function setReceiptSendAttempts(int $receiptSendAttempts): self
    {
        $this->setData(self::RECEIPT_SEND_ATTEMPTS, $receiptSendAttempts);
        return $this;
    }

    /** Get receipt next send at. */
    public function getReceiptNextSendAt(): ?string
    {
        $v = $this->getData(self::RECEIPT_NEXT_SEND_AT);
        return $v === null ? null : (string) $v;
    }

    /** Set receipt next send at. */
    public function setReceiptNextSendAt(?string $receiptNextSendAt): self
    {
        $this->setData(self::RECEIPT_NEXT_SEND_AT, $receiptNextSendAt);
        return $this;
    }

    /** Get receipt last error. */
    public function getReceiptLastError(): ?string
    {
        $v = $this->getData(self::RECEIPT_LAST_ERROR);
        return $v === null ? null : (string) $v;
    }

    /** Set receipt last error. */
    public function setReceiptLastError(?string $receiptLastError): self
    {
        $this->setData(self::RECEIPT_LAST_ERROR, $receiptLastError);
        return $this;
    }

    /** Get period end at. */
    public function getPeriodEndAt(): ?string
    {
        $v = $this->getData(self::PERIOD_END_AT);
        return $v === null ? null : (string) $v;
    }

    /** Set period end at. */
    public function setPeriodEndAt(?string $periodEndAt): self
    {
        $this->setData(self::PERIOD_END_AT, $periodEndAt);
        return $this;
    }

    /** Get anonymised at. */
    public function getAnonymisedAt(): ?string
    {
        $v = $this->getData(self::ANONYMISED_AT);
        return $v === null ? null : (string) $v;
    }

    /** Set anonymised at. */
    public function setAnonymisedAt(?string $anonymisedAt): self
    {
        $this->setData(self::ANONYMISED_AT, $anonymisedAt);
        return $this;
    }

    /** Get refund creditmemo id. */
    public function getRefundCreditmemoId(): ?int
    {
        $v = $this->getData(self::REFUND_CREDITMEMO_ID);
        return $v === null ? null : (int) $v;
    }

    /** Set refund creditmemo id. */
    public function setRefundCreditmemoId(?int $refundCreditmemoId): self
    {
        $this->setData(self::REFUND_CREDITMEMO_ID, $refundCreditmemoId);
        return $this;
    }

    /** Get created at. */
    public function getCreatedAt(): string
    {
        return (string) $this->getData(self::CREATED_AT);
    }

    /** Set created at. */
    public function setCreatedAt(string $createdAt): self
    {
        $this->setData(self::CREATED_AT, $createdAt);
        return $this;
    }

    /** Get updated at. */
    public function getUpdatedAt(): string
    {
        return (string) $this->getData(self::UPDATED_AT);
    }

    /** Set updated at. */
    public function setUpdatedAt(string $updatedAt): self
    {
        $this->setData(self::UPDATED_AT, $updatedAt);
        return $this;
    }

    /** Get status change note. */
    public function getStatusChangeNote(): ?string
    {
        $v = $this->getData(self::STATUS_CHANGE_NOTE);
        return $v === null ? null : (string) $v;
    }

    /** Set status change note. */
    public function setStatusChangeNote(?string $statusChangeNote): self
    {
        $this->setData(self::STATUS_CHANGE_NOTE, $statusChangeNote);
        return $this;
    }

    /** Get status change legal basis. */
    public function getStatusChangeLegalBasis(): ?string
    {
        $v = $this->getData(self::STATUS_CHANGE_LEGAL_BASIS);
        return $v === null ? null : (string) $v;
    }

    /** Set status change legal basis. */
    public function setStatusChangeLegalBasis(?string $statusChangeLegalBasis): self
    {
        $this->setData(self::STATUS_CHANGE_LEGAL_BASIS, $statusChangeLegalBasis);
        return $this;
    }

    /** Get status change actor. */
    public function getStatusChangeActor(): ?string
    {
        $v = $this->getData(self::STATUS_CHANGE_ACTOR);
        return $v === null ? null : (string) $v;
    }

    /** Set status change actor. */
    public function setStatusChangeActor(?string $statusChangeActor): self
    {
        $this->setData(self::STATUS_CHANGE_ACTOR, $statusChangeActor);
        return $this;
    }

    /** Get Art. 13(3) reimbursement withheld-at timestamp (UTC), or null. */
    public function getReimbursementWithheldAt(): ?string
    {
        $v = $this->getData(self::REIMBURSEMENT_WITHHELD_AT);
        return $v === null ? null : (string) $v;
    }

    /** Set Art. 13(3) reimbursement withheld-at timestamp (UTC); null lifts it. */
    public function setReimbursementWithheldAt(?string $reimbursementWithheldAt): self
    {
        $this->setData(self::REIMBURSEMENT_WITHHELD_AT, $reimbursementWithheldAt);
        return $this;
    }

    /** Get manual "reimbursement paid" mark timestamp (UTC), or null. */
    public function getReimbursementPaidAt(): ?string
    {
        $v = $this->getData(self::REIMBURSEMENT_PAID_AT);
        return $v === null ? null : (string) $v;
    }

    /** Set manual "reimbursement paid" mark timestamp (UTC); null clears it. */
    public function setReimbursementPaidAt(?string $reimbursementPaidAt): self
    {
        $this->setData(self::REIMBURSEMENT_PAID_AT, $reimbursementPaidAt);
        return $this;
    }
}
