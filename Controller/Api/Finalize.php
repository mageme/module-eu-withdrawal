<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Controller\Api;

use MageMe\EUWithdrawal\Api\ItemRepositoryInterface;
use MageMe\EUWithdrawal\Api\RequestRepositoryInterface;
use MageMe\EUWithdrawal\Exception\ItemCapacityExceededException;
use MageMe\EUWithdrawal\Exception\NoEligibleItemsException;
use MageMe\EUWithdrawal\Model\Frontend\ReasonsConfigReader;
use MageMe\EUWithdrawal\Model\Customer\CustomerIdentityFactory;
use MageMe\EUWithdrawal\Model\Lookup\OrderLookupByIncrementId;
use MageMe\EUWithdrawal\Model\Request\CreateRequestInput;
use MageMe\EUWithdrawal\Model\Request\RequestCreator;
use MageMe\EUWithdrawal\Model\Security\AntiEnumeration;
use MageMe\EUWithdrawal\Model\Security\ResponseTimer;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface as HttpRequestInterface;
use Magento\Framework\Controller\Result\Json as JsonResult;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Data\Form\FormKey\Validator as FormKeyValidator;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\Locale\ResolverInterface as LocaleResolverInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * JSON endpoint for the SPA flow. Reads a POST (JSON or form-encoded) with
 * `{orderId, items:{id:qty}, reason?, email?, name?}` and creates a
 * withdrawal request via the same RequestCreator path as the legacy Submit
 * controller. Replies with a structured JSON payload the JS app renders as
 * panel 4.
 *
 * CSRF: form_key accepted from header `X-Magento-Form-Key` OR from body key
 * `form_key` / `formKey`. Validated via Magento's FormKey validator.
 */
class Finalize implements HttpPostActionInterface, CsrfAwareActionInterface
{
    /**
     * Constructor.
     *
     * @param HttpRequestInterface $request
     * @param JsonFactory $jsonFactory
     * @param RequestCreator $requestCreator
     * @param AntiEnumeration $antiEnumeration
     * @param CustomerSession $customerSession
     * @param StoreManagerInterface $storeManager
     * @param ResponseTimer $responseTimer
     * @param FormKeyValidator $formKeyValidator
     * @param OrderLookupByIncrementId $orderLookup
     * @param ItemRepositoryInterface $itemRepository
     * @param PriceCurrencyInterface $priceCurrency
     * @param UrlInterface $urlBuilder
     * @param LoggerInterface $logger
     * @param CustomerIdentityFactory $identityFactory
     * @param RequestRepositoryInterface $requestRepository
     * @param ReasonsConfigReader $reasonsConfig
     */
    public function __construct(
        private readonly HttpRequestInterface $request,
        private readonly JsonFactory $jsonFactory,
        private readonly RequestCreator $requestCreator,
        private readonly AntiEnumeration $antiEnumeration,
        private readonly CustomerSession $customerSession,
        private readonly StoreManagerInterface $storeManager,
        private readonly ResponseTimer $responseTimer,
        private readonly FormKeyValidator $formKeyValidator,
        private readonly OrderLookupByIncrementId $orderLookup,
        private readonly ItemRepositoryInterface $itemRepository,
        private readonly PriceCurrencyInterface $priceCurrency,
        private readonly UrlInterface $urlBuilder,
        private readonly LoggerInterface $logger,
        private readonly CustomerIdentityFactory $identityFactory,
        private readonly RequestRepositoryInterface $requestRepository,
        private readonly ReasonsConfigReader $reasonsConfig,
        private readonly LocaleResolverInterface $localeResolver,
    ) {
    }

    /**
     * Execute.
     *
     * @return ResultInterface
     */
    public function execute(): ResultInterface
    {
        $this->responseTimer->start();
        $result = $this->jsonFactory->create();
        $payload = $this->readPayload();

        $orderIncrementId = trim((string) ($payload['orderId'] ?? $payload['order_id'] ?? ''));
        $email = trim((string) ($payload['email'] ?? ''));
        $name = trim((string) ($payload['name'] ?? ''));
        $itemsRaw = $payload['items'] ?? [];
        $itemReasonsRaw = $payload['itemReasons'] ?? [];

        if ($this->customerSession->isLoggedIn()) {
            $customer = $this->customerSession->getCustomer();
            $email = (string) $customer->getEmail();
            $name = trim(($customer->getFirstname() ?? '') . ' ' . ($customer->getLastname() ?? ''));
        } else {
            // Guest flow: pull name/email from the bound order (verified via
            // magic-link token OR Lookup-session). Refuses to trust whatever
            // the JS payload contained — server is authoritative.
            $identity = $this->identityFactory->create();
            $guestOrder = null;
            if ($identity->boundOrderEntityId !== null && $orderIncrementId !== '') {
                $byId = $this->orderLookup->find($orderIncrementId);
                if ($byId !== null && (int) $byId->getEntityId() === $identity->boundOrderEntityId) {
                    $guestOrder = $byId;
                }
            }
            if ($guestOrder !== null) {
                $email = (string) $guestOrder->getCustomerEmail();
                $name = trim(($guestOrder->getCustomerFirstname() ?? '')
                    . ' ' . ($guestOrder->getCustomerLastname() ?? ''));
            }
        }

        if ($orderIncrementId === '' || $email === '' || $name === '') {
            return $this->uniformFail($result);
        }

        $items = $this->normalizeItems($itemsRaw);

        // Honour the per-item seal-broken declaration server-side, mirroring the
        // form Submit controller: the JS zeroes the qty when "seal broken" is
        // ticked, but a crafted/replayed JSON POST could still carry the item.
        // This is the Art. 16(e)/(i) legal backstop for the SPA path.
        $sealBroken = $this->parseSealBroken($payload);
        if ($sealBroken !== []) {
            $items = array_diff_key($items, $sealBroken);
        }

        $itemReasons = $this->normalizeItemReasons($itemReasonsRaw, array_keys($items));

        try {
            $input = new CreateRequestInput(
                orderIncrementId: $orderIncrementId,
                customerName: $name,
                customerEmail: $email,
                reasonText: null,
                jurisdiction: 'EU',
                locale: (string) $this->localeResolver->getLocale(),
                ip: (string) $this->request->getServer('REMOTE_ADDR', ''),
                userAgent: (string) $this->request->getServer('HTTP_USER_AGENT', ''),
                customerId: $this->customerSession->isLoggedIn() ? (int) $this->customerSession->getCustomerId() : null,
                items: $items,
                itemReasons: $itemReasons,
                referrerHost: $this->resolveReferrerHost(),
            );
            $created = $this->antiEnumeration->process(
                $input,
                fn (CreateRequestInput $i) => $this->requestCreator->create($i),
            );
        } catch (NoEligibleItemsException | ItemCapacityExceededException) {
            return $this->fail($result, (string) __('Some items are no longer eligible. Please reload and try again.'));
        } catch (\Throwable $e) {
            $this->logger->error('EUWithdrawal Finalize API failed: ' . $e->getMessage());
            return $this->fail($result, (string) __('We could not process your request. Please try again.'));
        }

        if ($created === null || !$created->isSuccess()) {
            return $this->uniformFail($result);
        }

        return $this->success($result, (int) $created->getRequestId(), $orderIncrementId, $email);
    }

    private function resolveReferrerHost(): ?string
    {
        $referer = (string) $this->request->getServer('HTTP_REFERER', '');
        if ($referer === '') {
            return null;
        }
        $host = parse_url($referer, PHP_URL_HOST);
        return is_string($host) && $host !== '' ? $host : null;
    }

    /**
     * Success.
     *
     * @param JsonResult $result
     * @param int $requestId
     * @param string $orderIncrementId
     * @param string $customerEmail
     * @return JsonResult
     */
    private function success(JsonResult $result, int $requestId, string $orderIncrementId, string $customerEmail): JsonResult
    {
        $order = $this->orderLookup->find($orderIncrementId);
        $currency = $order !== null ? (string) $order->getOrderCurrencyCode() : 'EUR';
        $items = [];
        $itemsTotal = 0.0;

        try {
            $withdrawalItems = $this->itemRepository->getByRequest($requestId);
            foreach ($withdrawalItems as $wi) {
                $refund = (float) $wi->getRefundAmount();
                $itemsTotal += $refund;
                $orderItem = $order?->getItemById((int) $wi->getOrderItemId());
                $items[] = [
                    'name' => $orderItem !== null ? (string) $orderItem->getName() : (string) __('Item'),
                    'qty' => (int) $wi->getQty(),
                    'priceFormatted' => $this->formatPrice($refund, $currency),
                ];
            }
        } catch (\Throwable $e) {
            $this->logger->warning('EUWithdrawal Finalize summary hydration failed: ' . $e->getMessage());
        }

        // Pull the real (prefix + padded) increment_id plus the frozen
        // shipping + order-level adjustment from the persisted request, so the
        // summary total matches RefundCalculator, the receipt and the order
        // view. RequestCreator already applies the merchant prefix at insert
        // time, so increment_id is authoritative; falls back to the numeric id.
        $incrementId = (string) $requestId;
        $shippingRefund = 0.0;
        $orderAdjustment = 0.0;
        try {
            $request = $this->requestRepository->get($requestId);
            $candidate = (string) $request->getIncrementId();
            if ($candidate !== '') {
                $incrementId = $candidate;
            }
            $shippingRefund = (float) $request->getShippingRefund();
            $orderAdjustment = (float) $request->getOrderAdjustmentRefund();
        } catch (\Throwable) {
            // keep numeric fallback + zero shipping/adjustment
        }

        $this->responseTimer->pad(200);
        return $result->setData([
            'ok' => true,
            'requestId' => $requestId,
            'incrementId' => $incrementId,
            'customerEmail' => $customerEmail,
            'totalRefundFormatted' => $this->formatPrice($itemsTotal + $shippingRefund + $orderAdjustment, $currency),
            'currency' => $currency,
            'items' => $items,
            'viewReturnUrl' => $order !== null ? $this->getOrderViewUrl((int) $order->getEntityId()) : '',
        ]);
    }

    /**
     * Fail.
     *
     * @param JsonResult $result
     * @param string $message
     * @return JsonResult
     */
    private function fail(JsonResult $result, string $message): JsonResult
    {
        $this->responseTimer->pad(200);
        return $result->setData(['ok' => false, 'error' => $message]);
    }

    /**
     * Uniform fail.
     *
     * @param JsonResult $result
     * @return JsonResult
     */
    private function uniformFail(JsonResult $result): JsonResult
    {
        $this->responseTimer->pad(200);
        return $result->setData([
            'ok' => false,
            'error' => (string) __('We could not find a matching order for that email.'),
        ]);
    }

    /**
     * Parse the per-item seal-broken declaration from the JSON payload. Accepts
     * `itemSeal` (the SPA key) and `item_seal_opened` (form-parity key); a value
     * of 1 means the customer declared the seal broken. Returns a set of oids.
     *
     * @param array<string, mixed> $payload
     * @return array<int, true>
     */
    private function parseSealBroken(array $payload): array
    {
        $out = [];
        foreach (['itemSeal', 'item_seal_opened'] as $key) {
            $raw = $payload[$key] ?? null;
            if (!is_array($raw)) {
                continue;
            }
            foreach ($raw as $oid => $value) {
                if (is_numeric($oid) && (int) $value === 1) {
                    $out[(int) $oid] = true;
                }
            }
        }
        return $out;
    }

    /**
     * @param mixed $itemsRaw
     * @return array<int, int>
     */
    private function normalizeItems($itemsRaw): array
    {
        if (!is_array($itemsRaw)) {
            return [];
        }
        $out = [];
        foreach ($itemsRaw as $key => $value) {
            $id = (int) $key;
            $qty = (int) $value;
            if ($id > 0 && $qty > 0) {
                $out[$id] = $qty;
            }
        }
        return $out;
    }

    /**
     * @param mixed $raw
     * @param int[] $allowedOids
     * @return array<int, array{code: ?string, text: ?string}>
     */
    private function normalizeItemReasons($raw, array $allowedOids): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $allowedCodes = $this->reasonsConfig->getAllowedCodes();
        $allowed = array_flip($allowedOids);
        $out = [];
        foreach ($raw as $key => $value) {
            $oid = (int) $key;
            if ($oid <= 0 || !isset($allowed[$oid]) || !is_array($value)) {
                continue;
            }
            $code = isset($value['code']) && is_string($value['code']) ? trim($value['code']) : '';
            $text = isset($value['text']) && is_string($value['text']) ? trim($value['text']) : '';
            if ($code !== '' && !isset($allowedCodes[$code])) {
                $code = '';
            }
            if (strlen($text) > 500) {
                $text = substr($text, 0, 500);
            }
            if ($code === '' && $text === '') {
                continue;
            }
            $out[$oid] = [
                'code' => $code !== '' ? $code : null,
                'text' => $text !== '' ? $text : null,
            ];
        }
        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function readPayload(): array
    {
        $contentType = (string) $this->request->getHeader('Content-Type');
        if (stripos($contentType, 'application/json') !== false) {
            $raw = (string) $this->request->getContent();
            if ($raw === '') {
                return [];
            }
            try {
                $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
                return is_array($decoded) ? $decoded : [];
            } catch (\JsonException) {
                return [];
            }
        }
        $post = $this->request->getPost();
        return $post !== null ? (array) $post->toArray() : [];
    }

    /**
     * Format price.
     *
     * @param float $amount
     * @param string $currency
     * @return string
     */
    private function formatPrice(float $amount, string $currency): string
    {
        return (string) $this->priceCurrency->format(
            $amount,
            false,
            PriceCurrencyInterface::DEFAULT_PRECISION,
            null,
            $currency,
        );
    }

    /**
     * Get order view url.
     *
     * @param int $orderEntityId
     * @return string
     */
    private function getOrderViewUrl(int $orderEntityId): string
    {
        return $this->urlBuilder->getUrl('sales/order/view', ['order_id' => $orderEntityId]);
    }

    /**
     * Create csrf validation exception.
     *
     * @param \Magento\Framework\App\RequestInterface $request
     * @return ?InvalidRequestException
     */
    public function createCsrfValidationException(\Magento\Framework\App\RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    /**
     * Validate for csrf.
     *
     * @param \Magento\Framework\App\RequestInterface $request
     * @return ?bool
     */
    public function validateForCsrf(\Magento\Framework\App\RequestInterface $request): ?bool
    {
        $headerKey = (string) $this->request->getHeader('X-Magento-Form-Key');
        $bodyKey = '';
        $payload = $this->readPayload();
        $bodyKey = (string) ($payload['formKey'] ?? $payload['form_key'] ?? '');
        $presented = $headerKey !== '' ? $headerKey : $bodyKey;
        if ($presented === '') {
            return false;
        }
        $request->setParam('form_key', $presented);
        return $this->formKeyValidator->validate($request);
    }
}
