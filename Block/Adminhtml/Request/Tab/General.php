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
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Backend\Block\Widget\Tab\TabInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Registry;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
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
     * @param array $data
     */
    public function __construct(
        Context $context,
        private readonly Registry $registry,
        private readonly ItemRepositoryInterface $itemRepository,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly TimezoneInterface $timezone,
        private readonly ReasonsConfigReader $reasonsConfig,
        array $data = [],
    ) {
        parent::__construct($context, $data);
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
     * Format price.
     *
     * @param float $price
     * @return string
     */
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
     * Get refund total.
     *
     * @return string
     */
    public function getRefundTotal(): string
    {
        return $this->formatPrice($this->getItemsSubtotal() + $this->getShippingRefund());
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
            \MageMe\EUWithdrawal\Model\Config\Source\ContractType::FINANCIAL       => 'Financial services (Enterprise tier)',
        ];
        return (string) __($map[$code] ?? ucwords(str_replace('_', ' ', $code)));
    }
}
