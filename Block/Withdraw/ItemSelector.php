<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Block\Withdraw;

use MageMe\EUWithdrawal\Api\EligibilityEngineInterface;
use MageMe\EUWithdrawal\Exception\NoDeliveryInfoException;
use MageMe\EUWithdrawal\Model\EligibilityRequestBuilder;
use MageMe\EUWithdrawal\Model\Item\ExclusionReason;
use MageMe\EUWithdrawal\Model\Frontend\ReasonsConfigReader;
use MageMe\EUWithdrawal\Model\Item\OrderPartialStateCalculator;
use MageMe\EUWithdrawal\Model\Item\RemainingItemState;
use MageMe\EUWithdrawal\Api\Token\MagicLinkServiceInterface;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderItemInterface;
use Magento\Sales\Api\OrderRepositoryInterface;

class ItemSelector extends Template
{
    /** @var array<int, RemainingItemState>|null */
    private ?array $states = null;
    private ?OrderInterface $resolvedOrder = null;
    private bool $orderResolved = false;

    /**
     * Constructor.
     *
     * @param Context $context
     * @param RequestInterface $request
     * @param MagicLinkServiceInterface $magicLinkService
     * @param CustomerSession $customerSession
     * @param OrderRepositoryInterface $orderRepository
     * @param EligibilityEngineInterface $eligibilityEngine
     * @param EligibilityRequestBuilder $eligibilityRequestBuilder
     * @param OrderPartialStateCalculator $stateCalculator
     * @param PriceCurrencyInterface $priceCurrency
     * @param ProductThumbnail $thumbnail
     * @param ReasonsConfigReader $reasonsConfig
     * @param array $data
     */
    public function __construct(
        Context $context,
        private readonly RequestInterface $request,
        private readonly MagicLinkServiceInterface $magicLinkService,
        private readonly CustomerSession $customerSession,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly EligibilityEngineInterface $eligibilityEngine,
        private readonly EligibilityRequestBuilder $eligibilityRequestBuilder,
        private readonly OrderPartialStateCalculator $stateCalculator,
        private readonly PriceCurrencyInterface $priceCurrency,
        private readonly ProductThumbnail $thumbnail,
        private readonly ReasonsConfigReader $reasonsConfig,
        array $data = [],
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Get thumbnail url.
     *
     * @param OrderItemInterface $item
     * @return string
     */
    public function getThumbnailUrl(OrderItemInterface $item): string
    {
        return $this->thumbnail->urlFor($item);
    }

    /**
     * Get order item.
     *
     * @param int $orderItemId
     * @return ?OrderItemInterface
     */
    public function getOrderItem(int $orderItemId): ?OrderItemInterface
    {
        $order = $this->resolveOrder();
        if ($order === null || $orderItemId <= 0) {
            return null;
        }
        $item = $order->getItemById($orderItemId);
        return $item instanceof OrderItemInterface ? $item : null;
    }

    /**
     * @return array<int, array{label: string, value: string}>
     */
    public function getOrderItemOptions(int $orderItemId): array
    {
        $item = $this->getOrderItem($orderItemId);
        if ($item === null) {
            return [];
        }
        $productOptions = $item->getProductOptions();
        if (!is_array($productOptions)) {
            return [];
        }
        $out = [];
        // Configurable-product selections (e.g. Size, Color).
        foreach (($productOptions['attributes_info'] ?? []) as $row) {
            if (!is_array($row) || !isset($row['label'], $row['value'])) {
                continue;
            }
            $out[] = ['label' => (string) $row['label'], 'value' => (string) $row['value']];
        }
        // Custom-option selections (drop-down / text / etc.).
        foreach (($productOptions['options'] ?? []) as $row) {
            if (!is_array($row) || !isset($row['label'], $row['value'])) {
                continue;
            }
            $out[] = ['label' => (string) $row['label'], 'value' => (string) $row['value']];
        }
        return $out;
    }

    /**
     * Has resolved order.
     *
     * @return bool
     */
    public function hasResolvedOrder(): bool
    {
        return $this->getStates() !== null;
    }

    /**
     * Get resolved order id.
     *
     * @return ?int
     */
    public function getResolvedOrderId(): ?int
    {
        $order = $this->resolveOrder();
        return $order !== null ? (int) $order->getEntityId() : null;
    }

    /** @return array<int, RemainingItemState>|null */
    public function getStates(): ?array
    {
        if ($this->states === null) {
            $order = $this->resolveOrder();
            if ($order === null) {
                return null;
            }
            try {
                $eligibilityRequest = $this->eligibilityRequestBuilder->build($order);
                $result = $this->eligibilityEngine->evaluate($eligibilityRequest);
                $this->states = $this->stateCalculator->calculate($order, $result, null);
            } catch (NoDeliveryInfoException) {
                return null;
            }
        }
        return $this->states;
    }

    /**
     * Get exclusion label.
     *
     * @param string $code
     * @return string
     */
    public function getExclusionLabel(string $code): string
    {
        return (string) __(ExclusionReason::getLabel($code));
    }

    /**
     * Compact per-row status descriptor used by the items table. Returns null
     * when the line is fully eligible (no badge needed).
     *   - 'done'     — already processed / fully returned
     *   - 'pending'  — held by an unconfirmed request
     *   - 'excluded' — legally or procedurally excluded
     *
     * @return array{tone:string, label:string}|null
     */
    public function getItemStatusBadge(RemainingItemState $state): ?array
    {
        if ($state->alreadyWithdrawnQty >= $state->purchasedQty && $state->purchasedQty > 0) {
            return ['tone' => 'done', 'label' => (string) __('Fully returned')];
        }
        if (!$state->isEligible()) {
            if ($state->exclusionReason === ExclusionReason::ALREADY_WITHDRAWN) {
                return ['tone' => 'done', 'label' => (string) __('Fully returned')];
            }
            if ($state->exclusionReason === ExclusionReason::PENDING_ONLY) {
                return ['tone' => 'pending', 'label' => (string) __('Return requested')];
            }
            return ['tone' => 'excluded', 'label' => (string) __('Not eligible')];
        }
        if ($state->pendingQty > 0) {
            return ['tone' => 'pending', 'label' => (string) __('Partially requested')];
        }
        return null;
    }

    /** Count shown in the new "Returned" column = approved + pending. */
    public function getReturnedQty(RemainingItemState $state): int
    {
        return $state->alreadyWithdrawnQty + $state->pendingQty;
    }

    /**
     * Reason preset list assembled from admin config. First entry is the
     * empty placeholder; the trailing "Other" is appended only when the
     * `frontend/reasons_enable_other` flag is on. Returns empty (sans
     * placeholder) when the admin has cleared both — caller must render the
     * row without a dropdown in that case.
     *
     * @return array<int, array{value:string, label:string}>
     */
    public function getReasonOptions(): array
    {
        $reasons = $this->reasonsConfig->getReasons();
        $opts = [];
        if ($reasons === [] && !$this->reasonsConfig->isOtherEnabled()) {
            return [];
        }
        $opts[] = ['value' => '', 'label' => (string) __('— Select a reason —')];
        foreach ($reasons as $code => $label) {
            $opts[] = ['value' => $code, 'label' => (string) __($label)];
        }
        if ($this->reasonsConfig->isOtherEnabled()) {
            $opts[] = ['value' => ReasonsConfigReader::RESERVED_CODE_OTHER, 'label' => (string) __('Other')];
        }
        return $opts;
    }

    /**
     * Is reasons enabled.
     *
     * @return bool
     */
    public function isReasonsEnabled(): bool
    {
        return $this->getReasonOptions() !== [];
    }

    /**
     * Is other reason enabled.
     *
     * @return bool
     */
    public function isOtherReasonEnabled(): bool
    {
        return $this->reasonsConfig->isOtherEnabled();
    }

    /**
     * Reports whether the order item is flagged as sealed-hygiene or sealed-AV
     * so the form can render the seal-broken question. Returns 'hygiene' for
     * Art. 16(e), 'av' for Art. 16(i), or null when no seal gate applies.
     */
    public function getSealKind(int $orderItemId): ?string
    {
        $orderItem = $this->getOrderItem($orderItemId);
        if ($orderItem === null) {
            return null;
        }
        $product = $orderItem->getProduct();
        if (!$product instanceof \Magento\Catalog\Api\Data\ProductInterface) {
            return null;
        }
        $hygiene = (int) ($product->getCustomAttribute('is_sealed_hygiene')?->getValue() ?? $product->getData('is_sealed_hygiene'));
        if ($hygiene === 1) {
            return 'hygiene';
        }
        $av = (int) ($product->getCustomAttribute('is_sealed_av')?->getValue() ?? $product->getData('is_sealed_av'));
        if ($av === 1) {
            return 'av';
        }
        return null;
    }

    /**
     * Format price.
     *
     * @param float $amount
     * @param string $currencyCode
     * @return string
     */
    public function formatPrice(float $amount, string $currencyCode = 'EUR'): string
    {
        $order = $this->resolveOrder();
        $code = $order !== null ? (string) $order->getOrderCurrencyCode() : $currencyCode;
        return (string) $this->priceCurrency->format(
            $amount,
            false,
            PriceCurrencyInterface::DEFAULT_PRECISION,
            null,
            $code,
        );
    }

    /**
     * Resolve order.
     *
     * @return ?OrderInterface
     */
    private function resolveOrder(): ?OrderInterface
    {
        if ($this->orderResolved) {
            return $this->resolvedOrder;
        }
        $this->orderResolved = true;
        $this->resolvedOrder = $this->doResolveOrder();
        return $this->resolvedOrder;
    }

    /**
     * Do resolve order.
     *
     * @return ?OrderInterface
     */
    private function doResolveOrder(): ?OrderInterface
    {
        $magicToken = (string) $this->request->getParam('t');
        if ($magicToken !== '') {
            $orderEntityId = $this->magicLinkService->resolveOrder($magicToken);
            if ($orderEntityId !== null) {
                try {
                    return $this->orderRepository->get($orderEntityId);
                } catch (NoSuchEntityException | InputException) {
                    return null;
                }
            }
        }

        $queryOrderId = trim((string) $this->request->getParam('order_id', ''));
        if ($queryOrderId === '' || !ctype_digit($queryOrderId)) {
            return null;
        }

        try {
            $order = $this->orderRepository->get((int) $queryOrderId);
        } catch (NoSuchEntityException | InputException) {
            return null;
        }

        // Logged-in customers may only withdraw from their own orders. Anonymous
        // visitors fall through — the customer-email check on Submit is the
        // authoritative guard (anti-enumeration response is identical).
        if ($this->customerSession->isLoggedIn()
            && (int) $order->getCustomerId() !== (int) $this->customerSession->getCustomerId()) {
            return null;
        }

        return $order;
    }
}
