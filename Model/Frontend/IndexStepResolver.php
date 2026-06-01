<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Frontend;

use MageMe\EUWithdrawal\Api\Token\MagicLinkServiceInterface;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\RequestInterface;

class IndexStepResolver
{
    /**
     * Constructor.
     *
     * @param RequestInterface $request
     * @param CustomerSession $customerSession
     * @param MagicLinkServiceInterface $magicLinkService
     */
    public function __construct(
        private readonly RequestInterface $request,
        private readonly CustomerSession $customerSession,
        private readonly MagicLinkServiceInterface $magicLinkService,
    ) {
    }

    /**
     * Get mode.
     *
     * @return string
     */
    public function getMode(): string
    {
        $token = (string) $this->request->getParam('t');
        if ($token !== '' && $this->magicLinkService->resolveOrder($token) !== null) {
            return 'step2';
        }
        $orderId = trim((string) $this->request->getParam('order_id', ''));
        if ($orderId !== '') {
            return 'step2';
        }
        $this->customerSession->start();
        return $this->customerSession->isLoggedIn() ? 'step1b' : 'step1a';
    }
}
