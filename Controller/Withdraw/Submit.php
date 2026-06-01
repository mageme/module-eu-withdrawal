<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Controller\Withdraw;

use MageMe\EUWithdrawal\Exception\ItemCapacityExceededException;
use MageMe\EUWithdrawal\Exception\NoEligibleItemsException;
use MageMe\EUWithdrawal\Model\Frontend\ReasonsConfigReader;
use MageMe\EUWithdrawal\Model\Request\CreateRequestInput;
use MageMe\EUWithdrawal\Model\Request\RequestCreator;
use MageMe\EUWithdrawal\Model\Security\AntiEnumeration;
use MageMe\EUWithdrawal\Model\Security\ResponseTimer;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Message\ManagerInterface as MessageManager;
use Magento\Store\Model\StoreManagerInterface;

class Submit implements HttpPostActionInterface, CsrfAwareActionInterface
{
    /**
     * Constructor.
     *
     * @param RequestInterface $request
     * @param RedirectFactory $redirectFactory
     * @param MessageManager $messageManager
     * @param RequestCreator $requestCreator
     * @param AntiEnumeration $antiEnumeration
     * @param CustomerSession $customerSession
     * @param StoreManagerInterface $storeManager
     * @param ResponseTimer $responseTimer
     * @param ReasonsConfigReader $reasonsConfig
     */
    public function __construct(
        private readonly RequestInterface $request,
        private readonly RedirectFactory $redirectFactory,
        private readonly MessageManager $messageManager,
        private readonly RequestCreator $requestCreator,
        private readonly AntiEnumeration $antiEnumeration,
        private readonly CustomerSession $customerSession,
        private readonly StoreManagerInterface $storeManager,
        private readonly ResponseTimer $responseTimer,
        private readonly ReasonsConfigReader $reasonsConfig,
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
        $redirect = $this->redirectFactory->create();
        $name = trim((string) $this->request->getPost('name'));
        $orderId = trim((string) $this->request->getPost('order_id'));
        $email = trim((string) $this->request->getPost('email'));

        if ($name === '' || $orderId === '' || $email === '') {
            $this->messageManager->addErrorMessage(__('Please fill in all required fields.'));
            $this->responseTimer->pad(200);
            return $redirect->setPath('withdraw-contract');
        }

        $items = $this->parseItems();
        $hasItemSelector = $this->request->getPost('items_selected') !== null
            || $this->request->getPost('items') !== null;

        // Honour the per-item seal-broken declaration server-side so a curl
        // POST that bypasses the form JS still gets the items dropped under
        // Art. 16(e)/(i). The form's JS already zeroes the qty when the
        // customer ticks "seal broken", but this is the legal backstop.
        $sealBroken = $this->parseSealBroken();
        if ($sealBroken !== []) {
            $excluded = array_intersect_key($items, $sealBroken);
            if ($excluded !== []) {
                $items = array_diff_key($items, $sealBroken);
                $this->messageManager->addNoticeMessage(
                    __('%1 item(s) were excluded from the withdrawal because the seal is broken (Art. 16(e)/(i) Directive 2011/83/EU).', count($excluded))
                );
            }
        }

        if ($hasItemSelector && $items === []) {
            $this->messageManager->addErrorMessage(__('Please select at least one item to withdraw.'));
            $this->responseTimer->pad(200);
            return $redirect->setPath('withdraw-contract');
        }

        $itemReasons = $this->parseItemReasons(array_keys($items));

        $store = $this->storeManager->getStore();
        $input = new CreateRequestInput(
            orderIncrementId: $orderId,
            customerName: $name,
            customerEmail: $email,
            reasonText: null,
            jurisdiction: (string) $store->getCode(),
            locale: (string) $store->getCode(),
            ip: (string) $this->request->getClientIp(),
            userAgent: (string) $this->request->getServer('HTTP_USER_AGENT'),
            customerId: $this->customerSession->isLoggedIn()
                ? (int) $this->customerSession->getCustomerId()
                : null,
            items: $items,
            itemReasons: $itemReasons,
            referrerHost: $this->resolveReferrerHost(),
        );

        try {
            $response = $this->antiEnumeration->handle(
                $input,
                fn (CreateRequestInput $i) => $this->requestCreator->create($i),
            );
        } catch (ItemCapacityExceededException) {
            $this->messageManager->addErrorMessage(
                __('One or more items are no longer available for withdrawal. Please refresh and try again.'),
            );
            $this->responseTimer->pad(200);
            return $redirect->setPath('withdraw-contract');
        } catch (NoEligibleItemsException) {
            $this->messageManager->addErrorMessage(
                __('None of the selected items are eligible for withdrawal.'),
            );
            $this->responseTimer->pad(200);
            return $redirect->setPath('withdraw-contract');
        }

        $params = $response->queryParams();
        $this->responseTimer->pad(200);
        return $redirect->setPath(
            $response->redirectPath(),
            $params === [] ? [] : ['_query' => $params],
        );
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
     * Parse items[] POST field. Requires both items[oid]=qty and items_selected[oid]=1
     * when item_selector was rendered (defensive: unchecked checkbox omits its qty from
     * submission anyway, but paranoid double-check prevents qty-injection for unchecked
     * items). Absent items_selected → fallback: treat items[] as authoritative.
     *
     * @return array<int, int> order_item_id => qty
     */
    private function parseItems(): array
    {
        $rawItems = $this->request->getPost('items');
        if (!is_array($rawItems)) {
            return [];
        }
        $selected = $this->request->getPost('items_selected');
        $selectedMap = is_array($selected) ? $selected : null;

        $out = [];
        foreach ($rawItems as $oidKey => $qtyValue) {
            if (!is_numeric($oidKey)) {
                continue;
            }
            $oid = (int) $oidKey;
            if ($oid <= 0) {
                continue;
            }
            if ($selectedMap !== null && !isset($selectedMap[$oidKey])) {
                continue;
            }
            if (!is_numeric($qtyValue)) {
                continue;
            }
            $qty = (int) $qtyValue;
            if ($qty <= 0) {
                continue;
            }
            $out[$oid] = $qty;
        }
        return $out;
    }

    /**
     * @param int[] $allowedOids order_item_ids that survived parseItems(); reasons for any
     *                           other oid are dropped (defence against oid-injection).
     * @return array<int, array{code: ?string, text: ?string}>
     */
    private function parseItemReasons(array $allowedOids): array
    {
        $codes = $this->request->getPost('item_reason_code');
        $texts = $this->request->getPost('item_reason_text');
        if (!is_array($codes) && !is_array($texts)) {
            return [];
        }
        $allowedCodes = $this->reasonsConfig->getAllowedCodes();
        $allowed = array_flip($allowedOids);
        $out = [];
        foreach ($allowedOids as $oid) {
            $code = is_array($codes) ? ($codes[(string) $oid] ?? $codes[$oid] ?? null) : null;
            $text = is_array($texts) ? ($texts[(string) $oid] ?? $texts[$oid] ?? null) : null;
            $code = is_string($code) ? trim($code) : '';
            $text = is_string($text) ? trim($text) : '';
            if ($code === '' && $text === '') {
                continue;
            }
            if ($code !== '' && !isset($allowedCodes[$code])) {
                $code = '';
            }
            if (strlen($text) > 500) {
                $text = substr($text, 0, 500);
            }
            if (!isset($allowed[$oid])) {
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
     * Parse `item_seal_opened[oid]` POST radios. Returns a set of oids the
     * customer declared as seal-broken. Used to drop those items from the
     * submission per Art. 16(e)/(i) — the form-side JS already prevents the
     * qty from being non-zero for these, this is the server backstop.
     *
     * @return array<int, true>
     */
    private function parseSealBroken(): array
    {
        $raw = $this->request->getPost('item_seal_opened');
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $oid => $value) {
            if (!is_numeric($oid)) {
                continue;
            }
            if ((int) $value === 1) {
                $out[(int) $oid] = true;
            }
        }
        return $out;
    }

    /**
     * Create csrf validation exception.
     *
     * @param RequestInterface $request
     * @return ?InvalidRequestException
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    /**
     * Validate for csrf.
     *
     * @param RequestInterface $request
     * @return ?bool
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return null;
    }
}
