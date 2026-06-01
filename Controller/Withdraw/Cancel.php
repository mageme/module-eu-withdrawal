<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Controller\Withdraw;

use MageMe\EUWithdrawal\Api\Data\RequestInterface;
use MageMe\EUWithdrawal\Api\RequestRepositoryInterface;
use MageMe\EUWithdrawal\Api\Request\StatusMachineInterface;
use MageMe\EUWithdrawal\Model\Customer\CustomerIdentityFactory;
use MageMe\EUWithdrawal\Model\Request\Exception\InvalidTransitionException;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface as HttpRequest;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Message\ManagerInterface as MessageManager;
use Magento\Sales\Api\OrderRepositoryInterface;

/**
 * POST /withdraw-contract/withdraw/cancel/
 *
 * Cancels a withdrawal request that the caller is entitled to cancel.
 * Auth is session-based (logged-in customer) OR magic-link-token-based
 * (anonymous visitor following a magic-link). The only cancellable source
 * status is `submitted`; anything else gets a generic info redirect.
 * Ownership failure returns the same "not found"-shaped redirect as any
 * other failure — no state is leaked.
 */
class Cancel implements HttpPostActionInterface, CsrfAwareActionInterface
{
    private const CANCELLABLE_STATUSES = [
        RequestInterface::STATUS_PENDING,
    ];

    /**
     * Constructor.
     *
     * @param HttpRequest $request
     * @param RedirectFactory $redirectFactory
     * @param MessageManager $messageManager
     * @param CustomerIdentityFactory $identityFactory
     * @param RequestRepositoryInterface $requestRepository
     * @param OrderRepositoryInterface $orderRepository
     * @param StatusMachineInterface $statusMachine
     */
    public function __construct(
        private readonly HttpRequest $request,
        private readonly RedirectFactory $redirectFactory,
        private readonly MessageManager $messageManager,
        private readonly CustomerIdentityFactory $identityFactory,
        private readonly RequestRepositoryInterface $requestRepository,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly StatusMachineInterface $statusMachine,
    ) {
    }

    /**
     * Execute.
     *
     * @return ResultInterface
     */
    public function execute(): ResultInterface
    {
        $redirect = $this->redirectFactory->create();
        $requestId = (int) $this->request->getPost('request_id');
        $token = (string) $this->request->getParam('t');
        $redirectParams = [];
        if ($token !== '') {
            $redirectParams['_query'] = ['t' => $token];
        }
        // Optional same-host return URL — when the cancel was triggered from
        // the customer order-view section, the form posts the page URL so the
        // redirect lands the customer back on the order view rather than the
        // generic /withdraw-contract index.
        $rawReturnUrl = trim((string) $this->request->getPost('return_url', ''));
        $sameHostReturnUrl = $this->resolveSafeReturnUrl($rawReturnUrl);

        $who = $this->identityFactory->create();

        try {
            $requestEntity = $this->requestRepository->get($requestId);
        } catch (NoSuchEntityException) {
            $this->messageManager->addErrorMessage(__('Withdrawal not found.'));
            return $redirect->setPath('withdraw-contract', $redirectParams);
        }

        try {
            $order = $this->orderRepository->get($requestEntity->getOrderId());
        } catch (NoSuchEntityException) {
            $this->messageManager->addErrorMessage(__('Withdrawal not found.'));
            return $redirect->setPath('withdraw-contract', $redirectParams);
        }

        if (!$who->canSeeRequest($order, (int) $requestEntity->getOrderId())) {
            $this->messageManager->addErrorMessage(__('Withdrawal not found.'));
            return $redirect->setPath('withdraw-contract', $redirectParams);
        }

        if (!in_array((string) $requestEntity->getStatus(), self::CANCELLABLE_STATUSES, true)) {
            $this->messageManager->addNoticeMessage(
                __('This withdrawal can no longer be cancelled online. Contact us if you need help.'),
            );
            return $this->redirectToIndex($redirect, (int) $order->getEntityId(), $redirectParams);
        }

        try {
            $this->statusMachine->transition(
                $requestEntity,
                RequestInterface::STATUS_CANCELLED,
                [
                    // Sentinel value consumed by `Observer\StatusChangeNotifier` to pick
                    // the self-cancel email template instead of the admin-cancel one.
                    // See `Model\Mail\StatusChangeNotifier::ACTOR_CUSTOMER_SELF`.
                    'admin_id'    => 'customer-self',
                    'customer_id' => $who->customerId,
                    'ip'          => (string) $this->request->getClientIp(),
                    'user_agent'  => (string) $this->request->getServer('HTTP_USER_AGENT'),
                    // No `note` for customer-initiated self-cancels: there is no
                    // input field in the storefront JS confirm prompt, so a
                    // static literal "cancelled by customer" would just be noise
                    // on the order-view detail card.
                ],
            );
        } catch (InvalidTransitionException) {
            $this->messageManager->addErrorMessage(__('Withdrawal not found.'));
            return $redirect->setPath('withdraw-contract', $redirectParams);
        }

        $this->messageManager->addSuccessMessage(
            __('Withdrawal #%1 cancelled.', $requestEntity->getIncrementId() ?? (string) $requestId),
        );
        if ($sameHostReturnUrl !== null) {
            return $redirect->setUrl($sameHostReturnUrl);
        }
        return $this->redirectToIndex($redirect, (int) $order->getEntityId(), $redirectParams);
    }

    /**
     * Accept the return URL only if it points back to the same host the
     * request came from. Anything else is dropped silently — anti-open-redirect.
     */
    private function resolveSafeReturnUrl(string $rawReturnUrl): ?string
    {
        if ($rawReturnUrl === '') {
            return null;
        }
        $parts = parse_url($rawReturnUrl);
        if ($parts === false || empty($parts['host'])) {
            // Relative URL (no host) — internal by definition, accept.
            return $rawReturnUrl;
        }
        $currentHost = (string) $this->request->getServer('HTTP_HOST');
        if ($currentHost !== '' && strcasecmp($parts['host'], $currentHost) === 0) {
            return $rawReturnUrl;
        }
        return null;
    }

    /**
     * Redirect to index.
     *
     * @param \Magento\Framework\Controller\Result\Redirect $redirect
     * @param ?int $orderEntityId
     * @param array $baseParams
     * @return ResultInterface
     */
    private function redirectToIndex(
        \Magento\Framework\Controller\Result\Redirect $redirect,
        ?int $orderEntityId,
        array $baseParams,
    ): ResultInterface {
        $params = $baseParams;
        if ($orderEntityId) {
            $params['_query'] = array_merge($params['_query'] ?? [], ['order_id' => $orderEntityId]);
        }
        return $redirect->setPath('withdraw-contract', $params);
    }

    /**
     * Create csrf validation exception.
     *
     * @param HttpRequest $request
     * @return ?InvalidRequestException
     */
    public function createCsrfValidationException(HttpRequest $request): ?InvalidRequestException
    {
        return null;
    }

    /**
     * Validate for csrf.
     *
     * @param HttpRequest $request
     * @return ?bool
     */
    public function validateForCsrf(HttpRequest $request): ?bool
    {
        return null; // Magento form_key default applies
    }
}
