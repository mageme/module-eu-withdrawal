<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Block\Withdraw;

use MageMe\EUWithdrawal\Api\EligibilityEngineInterface;
use MageMe\EUWithdrawal\Api\Seal\SealKindResolverInterface;
use MageMe\EUWithdrawal\Exception\NoDeliveryInfoException;
use MageMe\EUWithdrawal\Model\EligibilityRequestBuilder;
use MageMe\EUWithdrawal\Model\Item\ExclusionReason;
use MageMe\EUWithdrawal\Model\Frontend\ReasonsConfigReader;
use MageMe\EUWithdrawal\Model\Frontend\TaxDisplayConfig;
use MageMe\EUWithdrawal\Model\Item\ItemAmountResolver;
use MageMe\EUWithdrawal\Model\Item\OrderPartialStateCalculator;
use MageMe\EUWithdrawal\Model\Item\RemainingItemState;
use MageMe\EUWithdrawal\Model\Item\ReturnGroupBuilder;
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
     * @param TaxDisplayConfig $taxDisplay
     * @param ReturnGroupBuilder $returnGroupBuilder
     * @param ItemAmountResolver $itemAmounts
     * @param SealKindResolverInterface $sealKindResolver
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
        private readonly TaxDisplayConfig $taxDisplay,
        private readonly ReturnGroupBuilder $returnGroupBuilder,
        private readonly ItemAmountResolver $itemAmounts,
        private readonly SealKindResolverInterface $sealKindResolver,
        array $data = [],
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Whether the store displays sales prices including tax.
     *
     * @deprecated The withdrawal preview no longer varies its basis with this
     *     setting; it always quotes the gross refund. Retained because released
     *     Hyvä companions call it through method_exists().
     * @return bool
     */
    public function isInclTaxDisplay(): bool
    {
        return $this->taxDisplay->showsGrossFigures();
    }

    /**
     * Gross per-unit price for the items table — the amount actually refunded,
     * matching the confirmation page and the receipt. Independent of the
     * store's sales-price display setting: that setting governs how a sale is
     * presented, while this figure is the refund the consumer is owed.
     *
     * @param RemainingItemState $state
     * @return float
     */
    public function getUnitDisplayPrice(RemainingItemState $state): float
    {
        $net = (float) $state->unitDisplayPrice;
        $oi = $this->getOrderItem((int) $state->orderItemId);
        if ($oi === null) {
            return $net;
        }
        $ordered = (float) $oi->getQtyOrdered();
        if ($ordered <= 0.0) {
            return $net;
        }
        $unitTax = round((float) $state->rowTaxAmount / $ordered, 4, PHP_ROUND_HALF_EVEN);
        return round($net + $unitTax, 4, PHP_ROUND_HALF_EVEN);
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
     * Display SKU for a row. A bundle parent's order-item SKU is the base SKU
     * concatenated with every child SKU (long and noisy); show the bundle
     * product's own SKU instead. All other item types keep their order-item SKU.
     *
     * @param int $orderItemId
     * @param string $fallback
     * @return string
     */
    public function getDisplaySku(int $orderItemId, string $fallback): string
    {
        $item = $this->getOrderItem($orderItemId);
        if ($item === null || $item->getProductType() !== 'bundle') {
            return $fallback;
        }
        $product = $item->getProduct();
        $sku = $product !== null ? (string) $product->getSku() : '';

        return $sku !== '' ? $sku : $fallback;
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
        $productId = (int) $orderItem->getProductId();
        if ($productId <= 0) {
            return null;
        }
        $order = $this->resolveOrder();
        if ($order === null) {
            return null;
        }
        return $this->sealKindResolver->resolve($productId, (int) $order->getStoreId())->questionKind();
    }

    /**
     * The seal subject for a returnable line: the order-item id the answer must be
     * keyed by, and the question kind. A configurable's sealed simple variant — or,
     * for a bundle returned as a single unit, the first sealed component — keys the
     * answer by that child's own order-item id (which still gates this parent line
     * server-side), so the whole bundle carries one seal question; a self-sealed
     * line keys by itself. Null when nothing on the line is sealed.
     *
     * @param int $lineOrderItemId
     * @return array{subjectItemId: int, kind: string}|null
     */
    public function getLineSeal(int $lineOrderItemId): ?array
    {
        $ownKind = $this->getSealKind($lineOrderItemId);
        if ($ownKind !== null) {
            return ['subjectItemId' => $lineOrderItemId, 'kind' => $ownKind];
        }

        $line = $this->getOrderItem($lineOrderItemId);
        $order = $this->resolveOrder();
        if ($line === null || $order === null
            || !in_array($line->getProductType(), ['configurable', 'bundle'], true)) {
            return null;
        }

        $storeId = (int) $order->getStoreId();
        foreach ($order->getAllItems() as $child) {
            if ((int) ($child->getParentItemId() ?? 0) !== $lineOrderItemId) {
                continue;
            }
            $productId = (int) $child->getProductId();
            $kind = $productId > 0
                ? $this->sealKindResolver->resolve($productId, $storeId)->questionKind()
                : null;
            if ($kind !== null) {
                return ['subjectItemId' => (int) $child->getItemId(), 'kind' => $kind];
            }
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

    /** @return \MageMe\EUWithdrawal\Model\Item\ReturnGroup[] */
    public function getReturnGroups(): array
    {
        $states = $this->getStates();
        $order = $this->resolveOrder();
        if ($states === null || $order === null) {
            return [];
        }
        return $this->returnGroupBuilder->build($order, $states);
    }

    /**
     * Currency-formatted per-unit price of an informational bundle child, on the
     * store's sales-price display basis.
     *
     * @deprecated Released Hyvä companions call this and render its result
     *     beside rows they price net themselves. Changing its basis would print
     *     gross contents under net rows. New templates use the Gross variant.
     * @see self::formatItemDisplayPriceGross()
     * @param int $orderItemId
     * @return string
     */
    public function formatItemDisplayPrice(int $orderItemId): string
    {
        return $this->formatBundleChildUnitPrice($orderItemId, $this->isInclTaxDisplay());
    }

    /**
     * Currency-formatted gross per-unit price of an informational bundle child —
     * the amount that will actually be refunded for it.
     *
     * @param int $orderItemId
     * @return string
     */
    public function formatItemDisplayPriceGross(int $orderItemId): string
    {
        return $this->formatBundleChildUnitPrice($orderItemId, true);
    }

    /**
     * Format bundle child unit price.
     *
     * @param int $orderItemId
     * @param bool $gross
     * @return string
     */
    private function formatBundleChildUnitPrice(int $orderItemId, bool $gross): string
    {
        $order = $this->resolveOrder();
        $oi = $this->getOrderItem($orderItemId);
        if ($order === null || $oi === null) {
            return '';
        }
        $ordered = (float) $oi->getQtyOrdered();
        if ($ordered <= 0.0) {
            return $this->formatPrice(0.0);
        }
        $amounts = $this->itemAmounts->resolve($order, $oi);
        $unit = round($amounts->net() / $ordered, 4, PHP_ROUND_HALF_EVEN);
        if ($gross) {
            $unitTax = round($amounts->taxTotal() / $ordered, 4, PHP_ROUND_HALF_EVEN);
            $unit = round($unit + $unitTax, 4, PHP_ROUND_HALF_EVEN);
        }
        return $this->formatPrice($unit);
    }

    /** Comma-joined selected-option values for an order item (configurable/custom options), or ''. */
    public function getOptionSummary(int $orderItemId): string
    {
        $opts = $this->getOrderItemOptions($orderItemId);
        return implode(', ', array_map(static fn (array $o) => $o['value'], $opts));
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
