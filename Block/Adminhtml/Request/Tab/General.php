<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Block\Adminhtml\Request\Tab;

use MageMe\EUWithdrawal\Api\Data\ItemInterface;
use MageMe\EUWithdrawal\Api\Data\RequestInterface;
use MageMe\EUWithdrawal\Api\ItemRepositoryInterface;
use MageMe\EUWithdrawal\Model\Frontend\ReasonsConfigReader;
use MageMe\EUWithdrawal\Model\Reimbursement\DueStateResolver;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Backend\Block\Widget\Tab\TabInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Registry;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use MageMe\EUWithdrawal\Model\Item\ItemAmountResolver;
use Magento\Sales\Api\CreditmemoRepositoryInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;

class General extends Template implements TabInterface
{
    protected $_template = 'MageMe_EUWithdrawal::request/tab/general.phtml';

    /** @var ItemInterface[]|null */
    private ?array $items = null;
    private ?OrderInterface $order = null;
    private bool $orderResolved = false;

    /**
     * Constructor.
     *
     * @param Context $context
     * @param Registry $registry
     * @param ItemRepositoryInterface $itemRepository
     * @param OrderRepositoryInterface $orderRepository
     * @param TimezoneInterface $timezone
     * @param ReasonsConfigReader $reasonsConfig
     * @param ItemAmountResolver $itemAmounts
     * @param array $data
     */
    public function __construct(
        Context $context,
        private readonly Registry $registry,
        private readonly ItemRepositoryInterface $itemRepository,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly TimezoneInterface $timezone,
        private readonly ReasonsConfigReader $reasonsConfig,
        private readonly ItemAmountResolver $itemAmounts,
        private readonly DueStateResolver $dueStateResolver,
        private readonly CreditmemoRepositoryInterface $creditmemoRepository,
        array $data = [],
    ) {
        parent::__construct($context, $data);
    }

    /**
     * The linked refund credit memo — its number, view URL and refunded total —
     * for the summary block, or null when the request has no linked credit memo
     * (a manual "refunded" mark leaves none). Read-only.
     *
     * @return array{increment_id: string, url: string, total: string}|null
     */
    public function getRefundCreditmemoInfo(): ?array
    {
        $request = $this->getRequestEntity();
        if ($request === null) {
            return null;
        }
        $creditmemoId = (int) ($request->getRefundCreditmemoId() ?? 0);
        if ($creditmemoId <= 0) {
            return null;
        }
        try {
            $creditmemo = $this->creditmemoRepository->get($creditmemoId);
        } catch (NoSuchEntityException) {
            return null;
        }
        return [
            'increment_id' => (string) ($creditmemo->getIncrementId() ?? $creditmemoId),
            'url' => $this->getUrl('sales/order_creditmemo/view', ['creditmemo_id' => $creditmemoId]),
            'total' => $this->formatPrice((float) $creditmemo->getGrandTotal()),
        ];
    }

    /**
     * Admin "edit customer" URL for a registered customer, or null for a guest
     * request (no customer_id). Same target as the order view's customer link.
     *
     * @return ?string
     */
    public function getCustomerEditUrl(): ?string
    {
        $request = $this->getRequestEntity();
        if ($request === null) {
            return null;
        }
        $customerId = (int) ($request->getCustomerId() ?? 0);
        if ($customerId <= 0) {
            return null;
        }
        return $this->getUrl('customer/index/edit', ['id' => $customerId]);
    }

    /**
     * Advisory reimbursement due-state for the summary block, or null when it does
     * not apply (terminal or unreadable request) so the row is simply omitted.
     * Shares DueStateResolver with the grid column, so both surfaces always agree.
     *
     * @return array{code: string, label: string, days_overdue: int}|null
     */
    public function getReimbursementDueState(): ?array
    {
        $request = $this->getRequestEntity();
        if ($request === null) {
            return null;
        }
        $state = $this->dueStateResolver->resolve(
            (string) $request->getStatus(),
            (string) $request->getCreatedAt(),
            (int) ($request->getRefundCreditmemoId() ?? 0),
            $request->getReimbursementWithheldAt(),
            $request->getReimbursementPaidAt(),
        );
        return $state['code'] === DueStateResolver::STATE_NA ? null : $state;
    }

    /**
     * The inline "Mark Reimbursement Paid / Unpaid" action for the summary block,
     * or null when it should not be offered (the request is terminal, or a credit
     * memo is already linked — that records payment on its own). Kept here, next to
     * the reimbursement state, rather than in the top action bar beside Approve.
     *
     * @return array{url: string, form_key: string, is_manual_paid: bool}|null
     */
    public function getReimbursementMarkPaidAction(): ?array
    {
        $request = $this->getRequestEntity();
        if ($request === null) {
            return null;
        }
        $isOpen = in_array(
            (string) $request->getStatus(),
            [RequestInterface::STATUS_PENDING, RequestInterface::STATUS_APPROVED],
            true,
        );
        $creditmemoPaid = (int) ($request->getRefundCreditmemoId() ?? 0) > 0;
        if (!$isOpen || $creditmemoPaid) {
            return null;
        }
        return [
            'url' => $this->getUrl(
                'mageme_eu_withdrawal/request/toggleReimbursementPaid',
                ['request_id' => (int) $request->getRequestId()],
            ),
            'form_key' => $this->getFormKey(),
            'is_manual_paid' => $request->getReimbursementPaidAt() !== null,
        ];
    }

    /**
     * Get tab label.
     *
     * @return \Magento\Framework\Phrase
     */
    public function getTabLabel(): \Magento\Framework\Phrase
    {
        return __('Information');
    }

    /**
     * Get tab title.
     *
     * @return \Magento\Framework\Phrase
     */
    public function getTabTitle(): \Magento\Framework\Phrase
    {
        return $this->getTabLabel();
    }

    /**
     * Can show tab.
     *
     * @return bool
     */
    public function canShowTab(): bool
    {
        return true;
    }

    /**
     * Is hidden.
     *
     * @return bool
     */
    public function isHidden(): bool
    {
        return false;
    }

    /**
     * Get request entity.
     *
     * @return ?RequestInterface
     */
    public function getRequestEntity(): ?RequestInterface
    {
        $entity = $this->registry->registry('mageme_eu_withdrawal_current_request');
        return $entity instanceof RequestInterface ? $entity : null;
    }

    /**
     * Get order.
     *
     * @return ?OrderInterface
     */
    public function getOrder(): ?OrderInterface
    {
        if ($this->orderResolved) {
            return $this->order;
        }
        $this->orderResolved = true;
        $request = $this->getRequestEntity();
        if ($request === null) {
            return $this->order = null;
        }
        try {
            $this->order = $this->orderRepository->get((int) $request->getOrderId());
        } catch (NoSuchEntityException) {
            $this->order = null;
        }
        return $this->order;
    }

    /**
     * Get order increment id.
     *
     * @return string
     */
    public function getOrderIncrementId(): string
    {
        $order = $this->getOrder();
        return $order ? (string) $order->getIncrementId() : '';
    }

    /** @return ItemInterface[] */
    public function getItems(): array
    {
        if ($this->items !== null) {
            return $this->items;
        }
        $request = $this->getRequestEntity();
        if ($request === null) {
            return $this->items = [];
        }
        return $this->items = $this->itemRepository->getByRequest((int) $request->getRequestId());
    }

    /**
     * Get order item.
     *
     * @param int $orderItemId
     * @return ?\Magento\Sales\Api\Data\OrderItemInterface
     */
    public function getOrderItem(int $orderItemId): ?\Magento\Sales\Api\Data\OrderItemInterface
    {
        $order = $this->getOrder();
        if ($order === null || $orderItemId <= 0) {
            return null;
        }
        $item = $order->getItemById($orderItemId);
        return $item instanceof \Magento\Sales\Api\Data\OrderItemInterface ? $item : null;
    }

    /**
     * Gross price of one unit as the consumer paid it — VAT in, discount out.
     * The refund column beside it is gross; a net price there mixed two bases.
     *
     * @param int $orderItemId
     * @return float
     */
    public function getItemUnitPricePaid(int $orderItemId): float
    {
        $line = $this->getItemLinePaid($orderItemId);
        $orderItem = $this->getOrderItem($orderItemId);
        $ordered = $orderItem !== null ? (float) $orderItem->getQtyOrdered() : 0.0;
        return $ordered > 0.0 ? round($line / $ordered, 4, PHP_ROUND_HALF_EVEN) : 0.0;
    }

    /**
     * Gross amount the consumer paid for the whole ordered line. The refund can
     * never exceed it, which is the point of showing them side by side.
     *
     * @param int $orderItemId
     * @return float
     */
    public function getItemLinePaid(int $orderItemId): float
    {
        $order = $this->getOrder();
        $orderItem = $this->getOrderItem($orderItemId);
        if ($order === null || $orderItem === null) {
            return 0.0;
        }
        $amounts = $this->itemAmounts->resolve($order, $orderItem);
        return round($amounts->net() + $amounts->taxTotal(), 4, PHP_ROUND_HALF_EVEN);
    }

    /**
     * VAT contained in the refund — items plus delivery, as frozen at consent.
     * Informational: it is already inside the totals above it.
     *
     * @return float
     */
    public function getTaxRefund(): float
    {
        $request = $this->getRequestEntity();
        return $request !== null ? (float) $request->getTaxRefund() : 0.0;
    }

    /**
     * Get tax refund display.
     *
     * @return string
     */
    public function getTaxRefundDisplay(): string
    {
        return $this->formatPrice($this->getTaxRefund());
    }

    public function formatPrice(float $price): string
    {
        $order = $this->getOrder();
        if ($order !== null) {
            return (string) $order->formatPriceTxt($price);
        }
        return number_format($price, 2, '.', '');
    }

    /**
     * Get items subtotal.
     *
     * @return float
     */
    public function getItemsSubtotal(): float
    {
        $total = 0.0;
        foreach ($this->getItems() as $item) {
            $total += (float) $item->getRefundAmount();
        }
        return $total;
    }

    /**
     * Get shipping refund.
     *
     * @return float
     */
    public function getShippingRefund(): float
    {
        $request = $this->getRequestEntity();
        if ($request === null) {
            return 0.0;
        }
        return (float) $request->getShippingRefund();
    }

    /**
     * Get order adjustment refund (signed; 0 for standard orders).
     *
     * @return float
     */
    public function getOrderAdjustmentRefund(): float
    {
        $request = $this->getRequestEntity();
        if ($request === null) {
            return 0.0;
        }
        return (float) $request->getOrderAdjustmentRefund();
    }

    /**
     * Get items subtotal display.
     *
     * @return string
     */
    public function getItemsSubtotalDisplay(): string
    {
        return $this->formatPrice($this->getItemsSubtotal());
    }

    /**
     * Get shipping refund display.
     *
     * @return string
     */
    public function getShippingRefundDisplay(): string
    {
        return $this->formatPrice($this->getShippingRefund());
    }

    /**
     * Get order adjustment refund display.
     *
     * @return string
     */
    public function getOrderAdjustmentRefundDisplay(): string
    {
        return $this->formatPrice($this->getOrderAdjustmentRefund());
    }

    /**
     * Get refund total.
     *
     * @return string
     */
    public function getRefundTotal(): string
    {
        $stored = $this->getRequestEntity()?->getTotalRefund();
        $total = $stored !== null
            ? (float) $stored
            : $this->getItemsSubtotal() + $this->getShippingRefund() + $this->getOrderAdjustmentRefund();
        return $this->formatPrice($total);
    }

    /**
     * Format iso date.
     *
     * @param ?string $iso
     * @return string
     */
    public function formatIsoDate(?string $iso): string
    {
        if ($iso === null || $iso === '') {
            return '—';
        }
        try {
            $dt = new \DateTimeImmutable($iso);
        } catch (\Exception) {
            return '—';
        }
        return $this->timezone->formatDate($dt, \IntlDateFormatter::MEDIUM, true);
    }

    /**
     * Get order url.
     *
     * @param int $orderId
     * @return string
     */
    public function getOrderUrl(int $orderId): string
    {
        return $this->getUrl('sales/order/view', ['order_id' => $orderId]);
    }


    /**
     * Per-item reason label. Free text wins over the preset label when the
     * customer chose "Other"; otherwise the admin-configured label (with a
     * humanised-code fallback when the merchant later removed the preset).
     */
    public function getItemReasonDisplay(ItemInterface $item): string
    {
        $code = $item->getReasonCode();
        $text = $item->getReasonText();
        $storeId = (int) ($this->getOrder()?->getStoreId() ?? 0) ?: null;
        if ($code === ReasonsConfigReader::RESERVED_CODE_OTHER) {
            $t = $text !== null ? trim($text) : '';
            if ($t !== '') {
                return $t;
            }
            return (string) __($this->reasonsConfig->resolveLabel($code, $storeId));
        }
        if ($code !== null && $code !== '') {
            return (string) __($this->reasonsConfig->resolveLabel($code, $storeId));
        }
        if ($text !== null && trim($text) !== '') {
            return trim($text);
        }
        return '—';
    }

    /**
     * Returns the most recent admin status-change reason / note for the
     * current request, or null if none. Read from first-class request state
     * (StatusMachine persists it); the Pro audit log keeps the immutable
     * history but is not required to display this.
     *
     * @return array{label: string, transition: string, note: string, legal_basis: string, when: string}|null
     */
    public function getStatusChangeReason(): ?array
    {
        $request = $this->getRequestEntity();
        if ($request === null) {
            return null;
        }
        $note       = (string) ($request->getStatusChangeNote() ?? '');
        $legalBasis = (string) ($request->getStatusChangeLegalBasis() ?? '');
        $to         = $request->getStatus();
        $adminId    = (string) ($request->getStatusChangeActor() ?? '');
        if ($note === '' && $legalBasis === '') {
            return null;
        }
        $label = match ($to) {
            RequestInterface::STATUS_DENIED    => (string) __('Reason for Denial'),
            RequestInterface::STATUS_CANCELLED => $adminId === 'customer-self'
                ? (string) __('Customer Cancellation Note')
                : (string) __('Cancellation Note'),
            default     => (string) __('Status-Change Note'),
        };
        return [
            'label'       => $label,
            'transition'  => $to,
            'note'        => $note,
            'legal_basis' => $legalBasis,
            'when'        => (string) $request->getUpdatedAt(),
        ];
    }

    /**
     * Human-readable contract_type label (matches the admin config Source
     * used in system.xml). Falls back to the raw code when unknown so audit
     * data never disappears from the UI.
     */
    public function getContractTypeLabel(?string $code): string
    {
        if ($code === null || $code === '') {
            return '—';
        }
        $map = [
            \MageMe\EUWithdrawal\Model\Config\Source\ContractType::PHYSICAL_GOODS  => 'Physical goods',
            \MageMe\EUWithdrawal\Model\Config\Source\ContractType::DIGITAL_CONTENT => 'Digital content (non-tangible)',
            \MageMe\EUWithdrawal\Model\Config\Source\ContractType::DIGITAL_SERVICE => 'Digital service (SaaS / subscription)',
            \MageMe\EUWithdrawal\Model\Config\Source\ContractType::FINANCIAL       => 'Financial services (not supported)',
        ];
        return (string) __($map[$code] ?? ucwords(str_replace('_', ' ', $code)));
    }
}
