<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Rule\Preset;

use MageMe\EUWithdrawal\Api\Data\EligibilityDecisionInterface;
use MageMe\EUWithdrawal\Api\Data\EligibilityRequestInterface;
use MageMe\EUWithdrawal\Model\Waiver\WaiverEventReader;
use MageMe\EUWithdrawal\Model\Waiver\WaiverTextResolver;
use MageMe\EUWithdrawal\Model\Waiver\WaiverTextHasher;
use MageMe\EUWithdrawal\Service\DigitalContentDetector;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\StoreManagerInterface;

class DigitalWaiverPreset extends AbstractPreset
{
    public const CODE = 'preset_digital_waiver';
    public const CONFIG_PATH = 'mageme_eu_withdrawal/eligibility/digital/enabled';

    /**
     * Constructor.
     *
     * @param ScopeConfigInterface $scopeConfig
     * @param WaiverEventReader $reader
     * @param WaiverTextResolver $resolver
     * @param WaiverTextHasher $hasher
     * @param DigitalContentDetector $detector
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        private readonly WaiverEventReader $reader,
        private readonly WaiverTextResolver $resolver,
        private readonly WaiverTextHasher $hasher,
        private readonly DigitalContentDetector $detector,
        private readonly StoreManagerInterface $storeManager,
    ) {
        parent::__construct($scopeConfig);
    }

    /**
     * Get code.
     *
     * @return string
     */
    public function getCode(): string
    {
        return self::CODE;
    }

    /**
     * Get config path.
     *
     * @return string
     */
    protected function getConfigPath(): string
    {
        return self::CONFIG_PATH;
    }

    /**
     * Do evaluate.
     *
     * @param EligibilityRequestInterface $request
     * @param EligibilityDecisionInterface $current
     * @return EligibilityDecisionInterface
     */
    protected function doEvaluate(
        EligibilityRequestInterface $request,
        EligibilityDecisionInterface $current,
    ): EligibilityDecisionInterface {
        $decision = $current->withApplied(self::CODE);

        $product = $request->getCurrentProduct();
        $item = $request->getCurrentItem();
        if ($product === null || $item === null) {
            return $decision;
        }
        if (!$this->detector->isDigitalProduct($product)) {
            return $decision;
        }

        $order = $request->getOrder();
        $orderId = (int) $order->getEntityId();
        $orderItemId = (int) $item->getItemId();
        if ($orderId === 0 || $orderItemId === 0) {
            return $decision;
        }

        if (!$this->reader->hasBothConsents($orderId, $orderItemId)) {
            return $decision;
        }
        if (!$this->reader->hasConfirmationSent($orderId, $orderItemId)) {
            return $decision;
        }
        if (!$this->reader->hasPerformanceStarted($orderId, $orderItemId)) {
            return $decision;
        }

        $events = $this->reader->findEventsForOrder($orderId)[$orderItemId] ?? [];
        $storedHash = $this->firstNonEmpty($events, 'waiver_text_hash');
        if ($storedHash === null) {
            return $decision;
        }
        $locale = (string) $this->storeManager->getStore($order->getStoreId())->getConfig('general/locale/code');
        $jurisdiction = strtoupper(substr((string) ($order->getBillingAddress()?->getCountryId() ?? ''), 0, 2));
        $jurisdictionKey = $jurisdiction !== '' ? $jurisdiction : '__eu_generic__';
        $snap = $this->resolver->resolve($locale, $jurisdictionKey);
        $expected = $this->hasher->hash($snap['consent'], $snap['acknowledgment'], $locale, $jurisdictionKey);
        if (!hash_equals($expected, $storedHash)) {
            return $decision;
        }

        return $decision->withDeny('art_16_m_digital_waiver', 'Art. 16(m)');
    }

    /** @param list<array<string,mixed>> $events */
    private function firstNonEmpty(array $events, string $column): ?string
    {
        foreach ($events as $e) {
            if (!empty($e[$column])) {
                return (string) $e[$column];
            }
        }
        return null;
    }
}
