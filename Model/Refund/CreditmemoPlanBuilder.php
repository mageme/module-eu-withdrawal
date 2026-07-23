<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Refund;

use MageMe\EUWithdrawal\Api\Data\ItemInterface;
use MageMe\EUWithdrawal\Api\Data\RequestInterface;
use MageMe\EUWithdrawal\Model\Adminhtml\PostApprovalActionPolicy;
use MageMe\EUWithdrawal\Model\Config\Source\ContractType;
use MageMe\EUWithdrawal\Model\EligibilityRequestBuilder;
use MageMe\EUWithdrawal\Model\ResourceModel\Item\CollectionFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\Order\Invoice;
use Magento\Store\Model\ScopeInterface;

/**
 * Decides whether a withdrawal request's refund can be issued as an
 * automatic credit memo, and if so, on what terms (covering invoice, item
 * quantities, shipping, expected total). Pure decision logic — no credit
 * memo is created and no money moves here.
 */
class CreditmemoPlanBuilder
{
    private const DIGITAL_OR_SERVICE_CONTRACT_TYPES = [
        ContractType::DIGITAL_CONTENT,
        ContractType::DIGITAL_SERVICE,
    ];

    public function __construct(
        private readonly CollectionFactory $itemCollectionFactory,
        private readonly CreditMemoQtyExpander $qtyExpander,
        private readonly PostApprovalActionPolicy $actionPolicy,
        private readonly ScopeConfigInterface $scopeConfig,
    ) {
    }

    public function build(RequestInterface $request, OrderInterface $order): CreditmemoPlan
    {
        $currency = (string) $order->getOrderCurrencyCode();

        if ($request->getStatus() !== RequestInterface::STATUS_APPROVED) {
            return $this->manual($currency, 'not approved');
        }

        if ($this->actionPolicy->resolve($order) !== PostApprovalActionPolicy::CREDITMEMO) {
            return $this->manual($currency, 'order not creditmemo-able');
        }

        $contractTypeManualReason = $this->resolveContractTypeManualReason($order);
        if ($contractTypeManualReason !== null) {
            return $this->manual($currency, $contractTypeManualReason);
        }

        $prefill = $this->resolvePrefill($request, $order);

        if ($prefill->items === []) {
            return $this->manual($currency, 'no withdrawn items');
        }

        if (!$prefill->coverageClean) {
            return $this->manual($currency, 'no single covering invoice');
        }

        $invoiceId = $prefill->invoiceId;
        $items = $prefill->items;

        if ($this->hasInternalTender($order)) {
            return $this->manual($currency, 'mixed or internal tender', $invoiceId, $items);
        }

        if ($request->getIsPartial() !== 0) {
            return $this->manual($currency, 'partial withdrawal', $invoiceId, $items);
        }

        if ($request->getTotalRefund() === null) {
            return $this->manual($currency, 'no frozen total', $invoiceId, $items, $prefill->shippingAmount);
        }

        return new CreditmemoPlan(
            invoiceId: $invoiceId,
            items: $items,
            shippingAmount: $prefill->shippingAmount,
            expectedTotal: $prefill->expectedTotal,
            currency: $currency,
            autoEligible: true,
            manualReason: null,
            diagnostics: [],
        );
    }

    /**
     * Computes the prefill data for the admin credit-memo form for every approved
     * request, independent of the auto-issue gates. Always carries an invoice to
     * open against - the single covering paid invoice when one exists, otherwise
     * the first invoice as a fallback - so partial and multi-invoice orders still
     * get a usable form. `coverageClean` is true only when a single invoice
     * cleanly covered every withdrawn order-item.
     */
    public function resolvePrefill(RequestInterface $request, OrderInterface $order): CreditmemoPrefill
    {
        $qtys = $this->loadWithdrawnQtys($request->getRequestId());
        $coveringInvoiceId = $qtys === [] ? null : $this->resolveCoveringInvoice($order, $qtys);
        $invoiceId = $coveringInvoiceId ?? $this->resolveFirstInvoiceId($order);
        $items = $this->qtyExpander->expand($order, $qtys);

        $shippingRefund = $request->getShippingRefund();
        $shippingAmount = ($shippingRefund !== null && (float) $shippingRefund > 0.0) ? null : 0.0;

        $totalRefund = $request->getTotalRefund();

        return new CreditmemoPrefill(
            invoiceId: $invoiceId,
            items: $items,
            shippingAmount: $shippingAmount,
            expectedTotal: $totalRefund === null ? 0.0 : (float) $totalRefund,
            coverageClean: $coveringInvoiceId !== null,
        );
    }

    /**
     * @param array<int,float> $items order_item_id => qty, already expanded (if known yet)
     */
    private function manual(
        string $currency,
        string $reason,
        ?int $invoiceId = null,
        array $items = [],
        ?float $shippingAmount = null,
    ): CreditmemoPlan {
        return new CreditmemoPlan(
            invoiceId: $invoiceId,
            items: $items,
            shippingAmount: $shippingAmount,
            expectedTotal: 0.0,
            currency: $currency,
            autoEligible: false,
            manualReason: $reason,
            diagnostics: [$reason],
        );
    }

    /**
     * @return array<int,int> order_item_id => withdrawn qty
     */
    private function loadWithdrawnQtys(int $requestId): array
    {
        $collection = $this->itemCollectionFactory->create();
        $collection->addFieldToFilter(ItemInterface::REQUEST_ID, $requestId);

        $qtys = [];
        foreach ($collection->getItems() as $item) {
            $qtys[(int) $item->getOrderItemId()] = (int) $item->getQtyWithdraw();
        }
        return $qtys;
    }

    /**
     * The single paid invoice whose items cover every withdrawn order-item,
     * or null when no such invoice exists (unpaid, split across invoices, or
     * only partially invoiced).
     *
     * @param array<int,int> $qtys order_item_id => withdrawn qty
     */
    private function resolveCoveringInvoice(OrderInterface $order, array $qtys): ?int
    {
        foreach ($order->getInvoiceCollection() as $invoice) {
            if ((int) $invoice->getState() !== Invoice::STATE_PAID) {
                continue;
            }

            $invoicedQty = [];
            foreach ($invoice->getAllItems() as $item) {
                $oid = (int) $item->getOrderItemId();
                $invoicedQty[$oid] = ($invoicedQty[$oid] ?? 0.0) + (float) $item->getQty();
            }

            $covers = true;
            foreach ($qtys as $oid => $qty) {
                if (($invoicedQty[$oid] ?? 0.0) < $qty) {
                    $covers = false;
                    break;
                }
            }
            if ($covers) {
                return (int) $invoice->getId();
            }
        }
        return null;
    }

    /**
     * The first invoice on the order regardless of state, used as the fallback
     * prefill target when no single invoice cleanly covers the withdrawn items.
     */
    private function resolveFirstInvoiceId(OrderInterface $order): ?int
    {
        foreach ($order->getInvoiceCollection() as $invoice) {
            return (int) $invoice->getId();
        }
        return null;
    }

    private function resolveContractTypeManualReason(OrderInterface $order): ?string
    {
        $contractType = (string) $this->scopeConfig->getValue(
            EligibilityRequestBuilder::XML_CONTRACT_TYPE,
            ScopeInterface::SCOPE_STORE,
            (int) $order->getStoreId(),
        );

        if ($contractType === ContractType::PHYSICAL_GOODS) {
            return null;
        }

        if (in_array($contractType, self::DIGITAL_OR_SERVICE_CONTRACT_TYPES, true)) {
            return 'digital or service contract';
        }

        return 'unsupported contract type';
    }

    private function hasInternalTender(OrderInterface $order): bool
    {
        return (float) $order->getData('gift_cards_amount') > 0.0
            || (float) $order->getData('customer_balance_amount') > 0.0
            || (float) $order->getData('reward_currency_amount') > 0.0;
    }
}
