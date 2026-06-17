<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Block\Withdraw;

use MageMe\EUWithdrawal\Api\Data\RequestInterface as WithdrawalRequestInterface;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use MageMe\EUWithdrawal\Model\Order\ShipmentExistenceChecker;
use MageMe\EUWithdrawal\Api\Token\MagicLinkServiceInterface;
use MageMe\EUWithdrawal\Model\Refund\RefundCalculator;

class Form extends Template
{
    /**
     * Constructor.
     *
     * @param Context $context
     * @param CustomerSession $customerSession
     * @param RequestInterface $request
     * @param MagicLinkServiceInterface $magicLinkService
     * @param OrderRepositoryInterface $orderRepository
     * @param ShipmentExistenceChecker $shipmentChecker
     * @param RefundCalculator $refundCalculator
     * @param array $data
     */
    public function __construct(
        Context $context,
        private readonly CustomerSession $customerSession,
        private readonly RequestInterface $request,
        private readonly MagicLinkServiceInterface $magicLinkService,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly ShipmentExistenceChecker $shipmentChecker,
        private readonly RefundCalculator $refundCalculator,
        array $data = [],
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Order-level item base + gross adjustment gap for the given order, so the
     * JS sidebar can distribute the same order-level adjustment the server
     * applies. Returns ['base' => float, 'gap' => float].
     *
     * @param OrderInterface $order
     * @return array{base: float, gap: float}
     */
    public function getOrderLevelGap(OrderInterface $order): array
    {
        return $this->refundCalculator->orderLevelGap($order);
    }

    /**
     * Get form action.
     *
     * @return string
     */
    public function getFormAction(): string
    {
        return $this->getUrl('withdraw-contract/withdraw/submit');
    }

    /**
     * True when at least one order item is eligible for withdrawal. Returns
     * false when every line is either fully withdrawn, held by a draft, or
     * otherwise excluded — the sidebar uses this to surface a "nothing left
     * to return" notice instead of the generic empty-selection hint.
     */
    public function hasAnyEligibleItem(): bool
    {
        $child = $this->getChildBlock('item_selector');
        if (!$child instanceof ItemSelector) {
            return false;
        }
        $states = $child->getStates();
        if ($states === null) {
            return false;
        }
        foreach ($states as $state) {
            if ($state->isEligible()) {
                return true;
            }
        }
        return false;
    }

    /**
     * Map of order_item_id → list of pending/open requests covering that item,
     * used by the item-selector template to render ONE "Return requested —
     * Cancel" strip per active request (an item can legitimately be covered
     * by several unconfirmed requests at once when the customer re-submits
     * without cancelling the previous draft).
     *
     * @return array<int, array<int, array{requestId:int, incrementId:string, status:string, cancellable:bool, qty:int}>>
     */
    public function getPendingRequestsByOrderItemId(): array
    {
        $existing = $this->getChildBlock('existing_withdrawals');
        if (!$existing instanceof ExistingWithdrawals) {
            return [];
        }
        // Only in-flight (submitted) requests surface here — "approved" is
        // final, never show a cancel strip for it.
        $openStatuses = [
            WithdrawalRequestInterface::STATUS_PENDING,
        ];
        $map = [];
        foreach ($existing->getRequests() as $req) {
            if (!in_array($req->status, $openStatuses, true)) {
                continue;
            }
            foreach ($req->items as $item) {
                $oid = (int) ($item['order_item_id'] ?? 0);
                if ($oid <= 0) {
                    continue;
                }
                $map[$oid][] = [
                    'requestId' => $req->requestId,
                    'incrementId' => $req->incrementId,
                    'status' => $req->status,
                    'cancellable' => $req->cancellable,
                    'qty' => (int) ($item['qty'] ?? 0),
                ];
            }
        }
        return $map;
    }

    /**
     * Get cancel action url.
     *
     * @return string
     */
    public function getCancelActionUrl(): string
    {
        return $this->getUrl('withdraw-contract/withdraw/cancel');
    }

    /**
     * Admin-configured notice text for step 3 (Review & confirm). Merchants
     * fill it in system.xml under "Review & Confirm Page" — e.g. describing
     * the prepaid return label, who pays shipping, etc.
     */
    public function getReturnShippingNotice(): string
    {
        $raw = (string) $this->_scopeConfig->getValue(
            'mageme_eu_withdrawal/frontend/review/shipping_info_text',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
        );
        return trim($raw);
    }

    /**
     * Return-policy link rendered beside the shipping notice. Accepts either
     * absolute URLs (http/https) or store-relative paths, which are turned
     * into full URLs via UrlInterface.
     */
    public function getReturnPolicyUrl(): string
    {
        $raw = trim((string) $this->_scopeConfig->getValue(
            'mageme_eu_withdrawal/frontend/review/return_policy_url',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
        ));
        if ($raw === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $raw) === 1) {
            return $raw;
        }
        return $this->getUrl(ltrim($raw, '/'));
    }

    /**
     * Is customer logged in.
     *
     * @return bool
     */
    public function isCustomerLoggedIn(): bool
    {
        return $this->customerSession->isLoggedIn();
    }

    /**
     * @return array{name: string, email: string, order_id: string}
     */
    public function getPrefilledValues(): array
    {
        $values = ['name' => '', 'email' => '', 'order_id' => ''];

        $magicToken = (string) $this->request->getParam('t');
        if ($magicToken !== '') {
            $orderEntityId = $this->magicLinkService->resolveOrder($magicToken);
            if ($orderEntityId !== null) {
                try {
                    $order = $this->orderRepository->get($orderEntityId);
                    $values['name'] = trim(($order->getCustomerFirstname() ?? '')
                        . ' '
                        . ($order->getCustomerLastname() ?? ''));
                    $values['email'] = (string) $order->getCustomerEmail();
                    $values['order_id'] = (string) $order->getIncrementId();
                    return $values;
                } catch (NoSuchEntityException | InputException) {
                    // order gone or malformed id: fall through to logged-in defaults
                }
            }
        }

        if ($this->isCustomerLoggedIn()) {
            $customer = $this->customerSession->getCustomer();
            $values['name'] = trim(($customer->getFirstname() ?? '') . ' ' . ($customer->getLastname() ?? ''));
            $values['email'] = (string) $customer->getEmail();
        }

        // URL `?order_id=` carries the entity_id (Magento convention). Resolve
        // to the order so we can put the user-facing increment_id into the
        // form's "order number" input.
        $order = $this->resolveOrderFromQuery();
        if ($order !== null && !$this->isOrderForeign($order)) {
            $values['order_id'] = (string) $order->getIncrementId();
        }
        return $values;
    }

    /**
     * True when the customer is viewing their own order that has no shipments
     * yet. Per Art. 9(1) CRD, the right to withdraw exists from contract
     * conclusion; the 14-day period only starts at delivery (Art. 9(2)(b)).
     * The template uses this to show an info banner explaining the open-period
     * state before the item selector.
     *
     * Returns false when the customer isn't logged in, when the requested
     * order doesn't exist or isn't theirs, or when shipments already exist.
     */
    public function isAwaitingDelivery(): bool
    {
        if (!$this->isCustomerLoggedIn()) {
            return false;
        }
        $order = $this->resolveOrderFromQuery();
        if ($order === null) {
            return false;
        }
        if ((int) $order->getCustomerId() !== (int) $this->customerSession->getCustomerId()) {
            return false;
        }
        return !$this->shipmentChecker->hasShipments((int) $order->getEntityId());
    }

    /**
     * Logged-in customer with a resolved order_id: identity (name/email/order_id)
     * is knowable server-side, so the form hides those fields and renders only
     * the item selector + optional reason. Reduces visual noise for known users.
     */
    public function isIdentitySimplified(): bool
    {
        if (!$this->isCustomerLoggedIn()) {
            return false;
        }
        $values = $this->getPrefilledValues();
        return $values['name'] !== '' && $values['email'] !== '' && $values['order_id'] !== '';
    }

    /**
     * Before to html.
     */
    protected function _beforeToHtml()
    {
        $order = $this->resolveOrderForChildren();
        if ($order !== null) {
            foreach (['order_meta', 'return_summary', 'item_selector', 'photo_step'] as $alias) {
                $child = $this->getChildBlock($alias);
                if ($child) {
                    $child->setData('order', $order);
                }
            }
        }
        return parent::_beforeToHtml();
    }

    /**
     * Resolve order for children.
     *
     * @return ?OrderInterface
     */
    private function resolveOrderForChildren(): ?OrderInterface
    {
        $order = $this->resolveOrderFromQuery();
        if ($order !== null) {
            return $order;
        }
        $magicToken = (string) $this->request->getParam('t');
        if ($magicToken !== '') {
            $resolved = $this->magicLinkService->resolveOrder($magicToken);
            if ($resolved !== null) {
                try {
                    return $this->orderRepository->get($resolved);
                } catch (\Throwable) {
                    return null;
                }
            }
        }
        return null;
    }

    /**
     * Resolve the order pointed to by the `?order_id=<entity_id>` query param.
     * Returns null when the param is absent, malformed, or refers to a missing
     * entity.
     *
     * @return ?OrderInterface
     */
    private function resolveOrderFromQuery(): ?OrderInterface
    {
        $queryOrderId = trim((string) $this->request->getParam('order_id', ''));
        if ($queryOrderId === '' || !ctype_digit($queryOrderId)) {
            return null;
        }
        try {
            return $this->orderRepository->get((int) $queryOrderId);
        } catch (NoSuchEntityException | InputException) {
            return null;
        }
    }

    /**
     * Is order foreign to the currently logged-in customer.
     *
     * @param OrderInterface $order
     * @return bool
     */
    private function isOrderForeign(OrderInterface $order): bool
    {
        if (!$this->isCustomerLoggedIn()) {
            return false;
        }
        return (int) $order->getCustomerId() !== (int) $this->customerSession->getCustomerId();
    }
}
