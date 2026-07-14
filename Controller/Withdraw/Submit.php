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
use Magento\Framework\Locale\ResolverInterface as LocaleResolverInterface;
use Magento\Framework\Message\ManagerInterface as MessageManager;

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
     * @param LocaleResolverInterface $localeResolver
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
        private readonly LocaleResolverInterface $localeResolver,
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

        // The per-item seal declaration is parsed and forwarded to RequestCreator,
        // which performs the authoritative line-wide exclusion (Art. 16(e)/(i)).
        // The notice below is a display-only hint; it does not mutate $items.
        $sealAnswers = $this->parseSealAnswers();
        $openedInSelection = [];
        foreach ($sealAnswers as $oid => $opened) {
            if ($opened && isset($items[$oid])) {
                $openedInSelection[$oid] = true;
            }
        }
        if ($openedInSelection !== []) {
            $this->messageManager->addNoticeMessage(
                __('%1 item(s) were excluded from the withdrawal because the seal is broken (Art. 16(e)/(i) Directive 2011/83/EU).', count($openedInSelection))
            );
        }

        if ($hasItemSelector && $items === []) {
            $this->messageManager->addErrorMessage(__('Please select at least one item to withdraw.'));
            $this->responseTimer->pad(200);
            return $redirect->setPath('withdraw-contract');
        }

        $itemReasons = $this->parseItemReasons(array_keys($items));

        $input = new CreateRequestInput(
            orderIncrementId: $orderId,
            customerName: $name,
            customerEmail: $email,
            reasonText: null,
            jurisdiction: 'EU',
            locale: (string) $this->localeResolver->getLocale(),
            ip: (string) $this->request->getClientIp(),
            userAgent: (string) $this->request->getServer('HTTP_USER_AGENT'),
            customerId: $this->customerSession->isLoggedIn()
                ? (int) $this->customerSession->getCustomerId()
                : null,
            items: $items,
            itemReasons: $itemReasons,
            referrerHost: $this->resolveReferrerHost(),
            sealAnswers: $sealAnswers,
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
            if (mb_strlen($text) > 500) {
                $text = mb_substr($text, 0, 500);
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
     * Parse `item_seal_opened[oid]` POST radios into subject_oid => opened?
     * 1 = opened, 0 = intact. Non 0/1 values are ignored.
     *
     * @return array<int, bool>
     */
    private function parseSealAnswers(): array
    {
        $raw = $this->request->getPost('item_seal_opened');
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $oid => $value) {
            if (!is_numeric($oid) || !is_scalar($value)) {
                continue;
            }
            if ($value === 1 || $value === '1' || $value === true) {
                $out[(int) $oid] = true;
            } elseif ($value === 0 || $value === '0' || $value === false) {
                $out[(int) $oid] = false;
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
