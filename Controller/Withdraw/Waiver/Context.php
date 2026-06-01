<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Controller\Withdraw\Waiver;

use MageMe\EUWithdrawal\Model\Waiver\WaiverTextHasher;
use MageMe\EUWithdrawal\Model\Waiver\WaiverTextResolver;
use MageMe\EUWithdrawal\Service\DigitalContentDetector;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Store\Model\StoreManagerInterface;

class Context implements HttpGetActionInterface
{
    /**
     * Constructor.
     *
     * @param CheckoutSession $checkoutSession
     * @param DigitalContentDetector $detector
     * @param WaiverTextResolver $resolver
     * @param WaiverTextHasher $hasher
     * @param StoreManagerInterface $storeManager
     * @param JsonFactory $jsonFactory
     */
    public function __construct(
        private readonly CheckoutSession $checkoutSession,
        private readonly DigitalContentDetector $detector,
        private readonly WaiverTextResolver $resolver,
        private readonly WaiverTextHasher $hasher,
        private readonly StoreManagerInterface $storeManager,
        private readonly JsonFactory $jsonFactory,
    ) {
    }

    /**
     * Execute.
     *
     * @return ResultInterface
     */
    public function execute(): ResultInterface
    {
        $quote = $this->checkoutSession->getQuote();
        $digital = $this->detector->filterDigitalItems($quote->getAllVisibleItems());
        $locale = (string) $this->storeManager->getStore()->getConfig('general/locale/code');
        $jurisdiction = \strtoupper(\substr((string) ($quote->getBillingAddress()?->getCountryId() ?? ''), 0, 2));
        $jurisdictionKey = $jurisdiction !== '' ? $jurisdiction : '__eu_generic__';

        $texts = $this->resolver->resolve($locale, $jurisdictionKey);
        $hash = $this->hasher->hash($texts['consent'], $texts['acknowledgment'], $locale, $jurisdictionKey);

        $items = [];
        foreach ($digital as $qi) {
            $items[] = [
                'quote_item_id' => (int) $qi->getItemId(),
                'sku' => (string) $qi->getSku(),
                'name' => (string) $qi->getName(),
                'qty' => (float) $qi->getQty(),
                'consent_text' => $texts['consent'],
                'acknowledgment_text' => $texts['acknowledgment'],
                'waiver_text_hash' => $hash,
                'locale' => $locale,
                'jurisdiction' => $jurisdiction ?: null,
            ];
        }
        return $this->jsonFactory->create()->setData([
            'items' => $items,
            'locale' => $locale,
            'jurisdiction' => $jurisdiction ?: null,
        ]);
    }
}
