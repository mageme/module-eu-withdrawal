<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Block\Sales\Order;

use MageMe\EUWithdrawal\Api\Data\RequestInterface;
use MageMe\EUWithdrawal\Model\Customer\CustomerIdentityFactory;
use MageMe\EUWithdrawal\Model\Customer\OrderWithdrawalHistoryService;
use MageMe\EUWithdrawal\Model\Customer\WithdrawalRequestView;
use MageMe\EUWithdrawal\Model\Frontend\Dto\PerOrderEligibility;
use MageMe\EUWithdrawal\Model\Frontend\FooterLinkLabelResolver;
use MageMe\EUWithdrawal\Model\Frontend\OrderEligibilityResolver;
use MageMe\EUWithdrawal\Model\Frontend\ReasonsConfigReader;
use MageMe\EUWithdrawal\Model\ModuleConfig;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Helper\Image as ImageHelper;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\Phrase;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\Registry;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Sales\Api\Data\OrderInterface;

/**
 * Storefront withdrawal section rendered above the items table on the
 * customer order-view page. Combines the deadline / start-CTA card with a
 * per-order request history list (status pill + inline expand + self-cancel).
 *
 * Replaces the legacy {@see WithdrawBanner} block — the new design supports
 * the partial-withdrawal flow where multiple non-overlapping requests can
 * exist on a single order. See `projects/eu-withdrawal/_plans/2026-04-28-storefront-withdrawals-section.md`.
 */
class WithdrawalsSection extends Template
{
    /** @var array<int, \Magento\Sales\Api\Data\OrderItemInterface>|null */
    private ?array $orderItemsByOid = null;

    /**
     * Constructor.
     *
     * @param Context $context
     * @param OrderEligibilityResolver $eligibilityResolver
     * @param OrderWithdrawalHistoryService $historyService
     * @param CustomerIdentityFactory $identityFactory
     * @param FooterLinkLabelResolver $labelResolver
     * @param ReasonsConfigReader $reasonsConfig
     * @param Registry $registry
     * @param TimezoneInterface $timezone
     * @param PriceCurrencyInterface $priceCurrency
     * @param FormKey $formKey
     * @param ProductRepositoryInterface $productRepository
     * @param ImageHelper $imageHelper
     * @param ModuleConfig $moduleConfig
     * @param array $data
     */
    public function __construct(
        Context $context,
        private readonly OrderEligibilityResolver $eligibilityResolver,
        private readonly OrderWithdrawalHistoryService $historyService,
        private readonly CustomerIdentityFactory $identityFactory,
        private readonly FooterLinkLabelResolver $labelResolver,
        private readonly ReasonsConfigReader $reasonsConfig,
        private readonly Registry $registry,
        private readonly TimezoneInterface $timezone,
        private readonly PriceCurrencyInterface $priceCurrency,
        private readonly FormKey $formKey,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly ImageHelper $imageHelper,
        private readonly ModuleConfig $moduleConfig,
        array $data = [],
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Returns the latest admin/customer status-change note + legal-basis for
     * a request, or null when none was supplied. Drives the "Note from our
     * team" / "Reason for denial" / "Customer cancellation note" callout
     * inside the inline-detail panel.
     *
     * @return array{label: string, note: string, legal_basis: string}|null
     */
    public function getStatusReason(WithdrawalRequestView $request): ?array
    {
        // First-class request state (StatusMachine persists it); the Pro audit
        // log keeps the immutable history but is not required to display this.
        $note       = (string) ($request->statusChangeNote ?? '');
        $legalBasis = (string) ($request->statusChangeLegalBasis ?? '');
        $to         = $request->status;
        $adminId    = (string) ($request->statusChangeActor ?? '');
        if ($note === '' && $legalBasis === '') {
            return null;
        }
        $label = match ($to) {
            RequestInterface::STATUS_DENIED    => (string) __('Reason for denial'),
            RequestInterface::STATUS_CANCELLED => $adminId === 'customer-self'
                ? (string) __('Your cancellation note')
                : (string) __('Note from our team'),
            default     => (string) __('Note from our team'),
        };
        return [
            'label'       => $label,
            'note'        => $note,
            'legal_basis' => $legalBasis,
        ];
    }

    /**
     * Get order.
     *
     * @return ?OrderInterface
     */
    public function getOrder(): ?OrderInterface
    {
        $order = $this->registry->registry('current_order');
        return $order instanceof OrderInterface ? $order : null;
    }

    /**
     * Get eligibility.
     *
     * @return PerOrderEligibility
     */
    public function getEligibility(): PerOrderEligibility
    {
        $order = $this->getOrder();
        if ($order === null) {
            return new PerOrderEligibility(
                eligible: false,
                deadlineIsoUtc: null,
                ineligibleReason: null,
                existingRequestId: null,
                existingRequestStatus: null,
            );
        }
        return $this->eligibilityResolver->resolve((int) $order->getEntityId());
    }

    /**
     * @return WithdrawalRequestView[]
     */
    public function getRequests(): array
    {
        $order = $this->getOrder();
        if ($order === null) {
            return [];
        }
        return $this->historyService->listForOrder(
            (int) $order->getEntityId(),
            $this->identityFactory->create(),
        );
    }

    /**
     * 'eligible'        — deadline known; "Start withdrawal" + dated message
     * 'eligible_pending' — withdrawal right exists but the 14-day clock has
     *                     not started yet (Art. 9(2)(b) — for goods the clock
     *                     starts at delivery). Shows the Start button without
     *                     a date.
     * 'pending'         — order not eligible because it isn't shipped/delivered
     *                     (informational card; no button)
     * 'expired'         — 14-day window has ended
     * 'excluded'        — Art. 16 exclusion / no eligible items
     * 'submitted'       — capacity consumed by an existing pending/submitted/
     *                     approved request; the order WAS eligible. The
     *                     request history list below carries per-request
     *                     status detail.
     * null              — order not relevant; suppress card
     */
    public function getCardState(): ?string
    {
        $e = $this->getEligibility();
        if ($e->hasRemainingCapacity) {
            return $e->deadlineIsoUtc !== null ? 'eligible' : 'eligible_pending';
        }
        return match ($e->ineligibleReason) {
            \MageMe\EUWithdrawal\Model\Frontend\Dto\PerOrderEligibility::REASON_NOT_SHIPPED_YET => 'pending',
            \MageMe\EUWithdrawal\Model\Frontend\Dto\PerOrderEligibility::REASON_PERIOD_EXPIRED  => 'expired',
            \MageMe\EUWithdrawal\Model\Frontend\Dto\PerOrderEligibility::REASON_ALREADY_IN_PROGRESS => 'submitted',
            \MageMe\EUWithdrawal\Model\Frontend\Dto\PerOrderEligibility::REASON_ART_16_EXCLUDED,
            \MageMe\EUWithdrawal\Model\Frontend\Dto\PerOrderEligibility::REASON_NO_ELIGIBLE_ITEMS => 'excluded',
            \MageMe\EUWithdrawal\Model\Frontend\Dto\PerOrderEligibility::REASON_OUT_OF_REGION => null,
            default => null,
        };
    }

    /**
     * Should render.
     *
     * @return bool
     */
    public function shouldRender(): bool
    {
        if (!$this->moduleConfig->isEnabled()) {
            return false;
        }
        return $this->getCardState() !== null || $this->getRequests() !== [];
    }

    /**
     * Should show deadline card.
     *
     * @return bool
     */
    public function shouldShowDeadlineCard(): bool
    {
        return $this->getCardState() !== null;
    }

    /**
     * Should show start button.
     *
     * @return bool
     */
    public function shouldShowStartButton(): bool
    {
        $state = $this->getCardState();
        return $state === 'eligible' || $state === 'eligible_pending';
    }

    /**
     * Get card heading.
     *
     * @return string
     */
    public function getCardHeading(): string
    {
        return match ($this->getCardState()) {
            'eligible', 'eligible_pending' => (string) __('EU right of withdrawal'),
            'pending'   => (string) __('Withdrawal right pending delivery'),
            'expired'   => (string) __('Withdrawal window has ended'),
            'submitted' => (string) __('Withdrawal already requested'),
            'excluded'  => (string) __('Not eligible for withdrawal'),
            default     => '',
        };
    }

    /**
     * Get card message.
     *
     * @return string
     */
    public function getCardMessage(): string
    {
        return match ($this->getCardState()) {
            'eligible_pending' => (string) __('You can withdraw from this contract any time — the 14-day deadline begins counting once the order is delivered.'),
            'pending'          => (string) __('Your 14-day withdrawal window will begin once this order is delivered.'),
            'expired'          => (string) __('The 14-day window for withdrawing from this contract has ended.'),
            'submitted'        => (string) __('A withdrawal request for this order has been submitted. See the request details below.'),
            'excluded'         => (string) __('This order is not eligible for withdrawal under EU consumer law (Art. 16 Directive 2011/83/EU).'),
            default            => '',
        };
    }

    /**
     * Conditional caveats for sealed-hygiene, sealed-AV and digital items.
     * The headline message says "you can withdraw any time within 14 days
     * after delivery", but for these item types the right is conditional:
     *  - sealed hygiene/AV: only as long as the seal stays intact (Art. 16(e)/(i))
     *  - digital content:    only until the customer starts using it (Art. 16(m))
     *
     * Without these caveats, customers expect a guaranteed 14-day return
     * and feel misled when the merchant denies the request post-seal-break.
     *
     * @return list<array{label: string, basis: string}>
     */
    public function getConditionalCaveats(): array
    {
        $order = $this->getOrder();
        if ($order === null) {
            return [];
        }
        $flags = [
            'is_perishable'      => 'perishable',
            'is_custom_made'     => 'custom_made',
            'is_sealed_hygiene'  => 'sealed_hygiene',
            'is_sealed_av'       => 'sealed_av',
            'is_digital_content' => 'digital',
        ];
        $present = [];
        foreach ($order->getItems() ?? [] as $oi) {
            if ($oi->getParentItemId()) {
                continue;
            }
            try {
                $product = $this->productRepository->get((string) $oi->getSku());
            } catch (\Throwable) {
                continue;
            }
            foreach ($flags as $attr => $key) {
                $value = $product->getCustomAttribute($attr)?->getValue() ?? $product->getData($attr);
                if ((int) $value === 1) {
                    $present[$key] = true;
                }
            }
        }
        $messages = [
            'perishable' => [
                (string) __('This order contains a perishable item that cannot be returned (food with short shelf life, fresh flowers, etc.).'),
                'Art. 16(d) Directive 2011/83/EU',
            ],
            'custom_made' => [
                (string) __('This order contains a custom-made or personalised item that cannot be returned.'),
                'Art. 16(c) Directive 2011/83/EU',
            ],
            'sealed_hygiene' => [
                (string) __('This order contains a sealed hygiene item. You keep the right of withdrawal only while the seal stays intact — once unsealed after delivery, the right is lost.'),
                'Art. 16(e) Directive 2011/83/EU',
            ],
            'sealed_av' => [
                (string) __('This order contains a sealed audio / video / software item. You keep the right of withdrawal only while the seal stays intact — once unsealed after delivery, the right is lost.'),
                'Art. 16(i) Directive 2011/83/EU',
            ],
            'digital' => [
                (string) __('This order contains digital content. Once you start using or downloading the content, you lose the right of withdrawal (you consented to this at checkout).'),
                'Art. 16(m) Directive 2011/83/EU',
            ],
        ];
        $out = [];
        foreach ($messages as $key => [$label, $basis]) {
            if (isset($present[$key])) {
                $out[] = ['label' => $label, 'basis' => $basis];
            }
        }
        return $out;
    }

    /**
     * Get deadline iso utc.
     *
     * @return ?string
     */
    public function getDeadlineIsoUtc(): ?string
    {
        return $this->getEligibility()->deadlineIsoUtc;
    }

    /**
     * Get deadline display.
     *
     * @return ?string
     */
    public function getDeadlineDisplay(): ?string
    {
        $iso = $this->getDeadlineIsoUtc();
        if ($iso === null) {
            return null;
        }
        try {
            return (string) $this->timezone->formatDateTime(
                new \DateTimeImmutable($iso),
                \IntlDateFormatter::MEDIUM,
                \IntlDateFormatter::SHORT,
            );
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Get start url.
     *
     * @return string
     */
    public function getStartUrl(): string
    {
        $order = $this->getOrder();
        // increment_id is what `Model\Lookup\OrderLookupByIncrementId` expects.
        if ($order === null) {
            return $this->getUrl('withdraw-contract');
        }
        return $this->getUrl('withdraw-contract', ['_query' => ['order_id' => (int) $order->getEntityId()]]);
    }

    /**
     * Get cancel url.
     *
     * @return string
     */
    public function getCancelUrl(): string
    {
        return $this->getUrl('withdraw-contract/withdraw/cancel');
    }

    /**
     * Same-page return URL the cancel form posts so the customer lands back
     * on the order view after the redirect (instead of the generic
     * /withdraw-contract landing). Customer\Controller\Cancel validates
     * same-host before honouring it.
     */
    public function getCurrentPageUrl(): string
    {
        $order = $this->getOrder();
        if ($order === null) {
            return $this->getUrl('sales/order/history');
        }
        return $this->getUrl('sales/order/view', ['order_id' => (int) $order->getEntityId()]);
    }

    /**
     * Get start label.
     *
     * @return string
     */
    public function getStartLabel(): string
    {
        return $this->labelResolver->step1Label();
    }

    /**
     * Get form key value.
     *
     * @return string
     */
    public function getFormKeyValue(): string
    {
        return $this->formKey->getFormKey();
    }

    /**
     * Renders "2 items · Refund $64.67" subtitle for the row summary.
     */
    public function getRequestSubtitle(WithdrawalRequestView $request): string
    {
        $itemCount = count($request->items);
        $refund = $this->priceCurrency->format(
            (float) $request->refundTotal,
            false,
            PriceCurrencyInterface::DEFAULT_PRECISION,
            (int) ($this->getOrder()?->getStoreId() ?? 0) ?: null,
            $request->currency !== '' ? $request->currency : null,
        );
        return (string) __('%1 item(s) · Refund %2', $itemCount, $refund);
    }

    /**
     * Get submitted at display.
     *
     * @param WithdrawalRequestView $request
     * @return string
     */
    public function getSubmittedAtDisplay(WithdrawalRequestView $request): string
    {
        try {
            $dt = new \DateTimeImmutable($request->submittedAt, new \DateTimeZone('UTC'));
            return (string) $this->timezone->formatDate(
                $this->timezone->date($dt),
                \IntlDateFormatter::MEDIUM,
            );
        } catch (\Throwable) {
            return $request->submittedAt;
        }
    }

    /**
     * @return array{label: string, bg: string, fg: string, hidden: bool}
     */
    public function getStatusBadge(string $status): array
    {
        return match ($status) {
            RequestInterface::STATUS_PENDING       => ['label' => (string) __('In progress'),           'bg' => '#fef3c7', 'fg' => '#92400e', 'hidden' => false],
            RequestInterface::STATUS_APPROVED        => ['label' => (string) __('Refund issued'),         'bg' => '#dcfce7', 'fg' => '#166534', 'hidden' => false],
            RequestInterface::STATUS_DENIED          => ['label' => (string) __('Denied'),                'bg' => '#fee2e2', 'fg' => '#991b1b', 'hidden' => false],
            RequestInterface::STATUS_CANCELLED       => ['label' => (string) __('Cancelled'),             'bg' => '#e5e7eb', 'fg' => '#374151', 'hidden' => false],
            RequestInterface::STATUS_ANONYMISED      => ['label' => '',                                    'bg' => '',         'fg' => '',         'hidden' => true],
            default           => ['label' => ucwords(str_replace('_', ' ', $status)), 'bg' => '#f3f4f6', 'fg' => '#374151', 'hidden' => false],
        };
    }

    /**
     * @param array{order_item_id:int,sku:string,name:string,qty:int,refund_amount:string,eligibility:string,reason_code:?string,reason_text:?string} $item
     */
    public function getItemReasonDisplay(array $item): string
    {
        $code    = (string) ($item['reason_code'] ?? '');
        $text    = trim((string) ($item['reason_text'] ?? ''));
        $storeId = (int) ($this->getOrder()?->getStoreId() ?? 0) ?: null;
        if ($code === '' && $text === '') {
            return '';
        }
        if ($code === ReasonsConfigReader::RESERVED_CODE_OTHER) {
            return $text !== '' ? $text : $this->reasonsConfig->resolveLabel($code, $storeId);
        }
        if ($code !== '') {
            return $this->reasonsConfig->resolveLabel($code, $storeId);
        }
        return $text;
    }

    /**
     * @param array{order_item_id:int,refund_amount:string} $item
     */
    public function formatItemRefund(array $item): string
    {
        return (string) $this->priceCurrency->format(
            (float) $item['refund_amount'],
            false,
            PriceCurrencyInterface::DEFAULT_PRECISION,
            (int) ($this->getOrder()?->getStoreId() ?? 0) ?: null,
        );
    }

    /**
     * Get product image url.
     *
     * @param int $orderItemId
     * @return ?string
     */
    public function getProductImageUrl(int $orderItemId): ?string
    {
        $orderItem = $this->getOrderItemById($orderItemId);
        if ($orderItem === null) {
            return null;
        }
        try {
            $product = $this->productRepository->getById((int) $orderItem->getProductId());
            return $this->imageHelper
                ->init($product, 'cart_page_product_thumbnail')
                ->setImageFile((string) $product->getImage())
                ->getUrl();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Get order item by id.
     *
     * @param int $orderItemId
     * @return ?\Magento\Sales\Api\Data\OrderItemInterface
     */
    private function getOrderItemById(int $orderItemId): ?\Magento\Sales\Api\Data\OrderItemInterface
    {
        if ($this->orderItemsByOid === null) {
            $this->orderItemsByOid = [];
            $order = $this->getOrder();
            if ($order !== null) {
                foreach (($order->getItems() ?? []) as $oi) {
                    $this->orderItemsByOid[(int) $oi->getItemId()] = $oi;
                }
            }
        }
        return $this->orderItemsByOid[$orderItemId] ?? null;
    }

    /**
     * Get cancel confirm message.
     *
     * @param WithdrawalRequestView $request
     * @return Phrase
     */
    public function getCancelConfirmMessage(WithdrawalRequestView $request): Phrase
    {
        return __(
            'Cancel withdrawal %1? Your refund will not be processed.',
            $request->incrementId,
        );
    }
}
