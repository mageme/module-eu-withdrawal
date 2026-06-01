<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Controller\Withdraw\Waiver;

use MageMe\EUWithdrawal\Exception\WaiverTextHashMismatchException;
use MageMe\EUWithdrawal\Model\Waiver\WaiverEventReader;
use MageMe\EUWithdrawal\Model\Waiver\WaiverEventWriter;
use MageMe\EUWithdrawal\Model\Waiver\WaiverTextHasher;
use MageMe\EUWithdrawal\Model\Waiver\WaiverTextResolver;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Store\Model\StoreManagerInterface;

class Save implements HttpPostActionInterface
{
    /**
     * Constructor.
     *
     * @param CheckoutSession $checkoutSession
     * @param WaiverEventWriter $writer
     * @param WaiverTextResolver $resolver
     * @param WaiverTextHasher $hasher
     * @param StoreManagerInterface $storeManager
     * @param HttpRequest $request
     * @param JsonFactory $jsonFactory
     */
    public function __construct(
        private readonly CheckoutSession $checkoutSession,
        private readonly WaiverEventWriter $writer,
        private readonly WaiverTextResolver $resolver,
        private readonly WaiverTextHasher $hasher,
        private readonly StoreManagerInterface $storeManager,
        private readonly HttpRequest $request,
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
        $json = $this->jsonFactory->create();
        $body = json_decode((string) $this->request->getContent(), true) ?: [];
        $consent = !empty($body['consent_express']);
        $lossAck = !empty($body['loss_ack']);
        if (!$consent || !$lossAck) {
            return $json->setHttpResponseCode(400)->setData(['error' => 'both_checkboxes_required']);
        }

        $quote = $this->checkoutSession->getQuote();
        $ownItemIds = [];
        foreach ($quote->getAllVisibleItems() as $quoteItem) {
            $ownItemIds[(int) $quoteItem->getItemId()] = true;
        }
        $locale = (string) $this->storeManager->getStore()->getConfig('general/locale/code');
        $jurisdiction = \strtoupper(\substr((string) ($quote->getBillingAddress()?->getCountryId() ?? ''), 0, 2));
        $jurisdictionKey = $jurisdiction !== '' ? $jurisdiction : '__eu_generic__';
        $texts = $this->resolver->resolve($locale, $jurisdictionKey);
        $expectedHash = $this->hasher->hash($texts['consent'], $texts['acknowledgment'], $locale, $jurisdictionKey);
        $snapshotPayload = $texts['consent'] . "\n\n---\n\n" . $texts['acknowledgment'];

        foreach ((array) ($body['items'] ?? []) as $item) {
            $providedHash = (string) ($item['waiver_text_hash'] ?? '');
            if (!hash_equals($expectedHash, $providedHash)) {
                throw new WaiverTextHashMismatchException(
                    __('Hash mismatch for quote item %1', $item['quote_item_id'] ?? '?')
                );
            }
            $quoteItemId = (int) ($item['quote_item_id'] ?? 0);
            if ($quoteItemId <= 0) {
                continue;
            }
            if (!isset($ownItemIds[$quoteItemId])) {
                continue;
            }

            $base = [
                'order_id' => 0,
                'quote_item_id' => $quoteItemId,
                'consent_value' => 1,
                'waiver_text_snapshot' => $snapshotPayload,
                'waiver_text_hash' => $expectedHash,
                'locale' => $locale,
                'jurisdiction' => $jurisdiction ?: null,
                'ip' => (string) $this->request->getClientIp(),
                'user_agent' => (string) $this->request->getServerValue('HTTP_USER_AGENT', ''),
            ];
            $this->writer->upsert($base + ['event_type' => WaiverEventReader::EVT_AFFIRM]);
            $this->writer->write($base + ['event_type' => WaiverEventReader::EVT_CONSENT]);
            $this->writer->write($base + ['event_type' => WaiverEventReader::EVT_LOSS]);
        }
        return $json->setData(['ok' => true]);
    }
}
