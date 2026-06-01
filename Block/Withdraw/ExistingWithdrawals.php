<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Block\Withdraw;

use MageMe\EUWithdrawal\Api\Data\RequestInterface;
use MageMe\EUWithdrawal\Model\Customer\CustomerIdentityFactory;
use MageMe\EUWithdrawal\Model\Customer\OrderWithdrawalHistoryService;
use MageMe\EUWithdrawal\Model\Customer\WithdrawalRequestView;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;

class ExistingWithdrawals extends Template
{
    /** @var WithdrawalRequestView[]|null */
    private ?array $cachedRequests = null;

    /**
     * Constructor.
     *
     * @param Context $context
     * @param CustomerIdentityFactory $identityFactory
     * @param OrderWithdrawalHistoryService $historyService
     * @param array $data
     */
    public function __construct(
        Context $context,
        private readonly CustomerIdentityFactory $identityFactory,
        private readonly OrderWithdrawalHistoryService $historyService,
        array $data = [],
    ) {
        parent::__construct($context, $data);
    }

    /** @return WithdrawalRequestView[] */
    public function getRequests(): array
    {
        if ($this->cachedRequests !== null) {
            return $this->cachedRequests;
        }
        $orderEntityId = $this->resolveOrderEntityId();
        if ($orderEntityId === null) {
            return $this->cachedRequests = [];
        }
        $who = $this->identityFactory->create();
        return $this->cachedRequests = $this->historyService->listForOrder($orderEntityId, $who);
    }

    /**
     * Is applicable.
     *
     * @return bool
     */
    public function isApplicable(): bool
    {
        return $this->getRequests() !== [];
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
     * Get magic token.
     *
     * @return ?string
     */
    public function getMagicToken(): ?string
    {
        $t = (string) $this->_request->getParam('t', '');
        return $t === '' ? null : $t;
    }

    /**
     * Get status label.
     *
     * @param string $status
     * @return string
     */
    public function getStatusLabel(string $status): string
    {
        return match ($status) {
            RequestInterface::STATUS_PENDING       => (string) __('In progress'),
            RequestInterface::STATUS_APPROVED        => (string) __('Approved'),
            RequestInterface::STATUS_DENIED          => (string) __('Denied'),
            RequestInterface::STATUS_CANCELLED       => (string) __('Cancelled'),
            RequestInterface::STATUS_ANONYMISED      => (string) __('Anonymised'),
            default                                  => $status,
        };
    }

    /**
     * Resolve order entity id.
     *
     * @return ?int
     */
    private function resolveOrderEntityId(): ?int
    {
        $queryOrderId = trim((string) $this->_request->getParam('order_id', ''));
        if ($queryOrderId !== '' && ctype_digit($queryOrderId)) {
            return (int) $queryOrderId;
        }
        // No direct order param, but a magic-link token may resolve to one.
        return $this->identityFactory->create()->boundOrderEntityId;
    }
}
