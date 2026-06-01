<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model;

use MageMe\EUWithdrawal\Api\Data\EligibilityRequestInterface;
use MageMe\EUWithdrawal\Api\Data\EligibilityRequestInterfaceFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Store\Model\ScopeInterface;

class EligibilityRequestBuilder
{
    public const XML_CONTRACT_TYPE = 'mageme_eu_withdrawal/general/default_contract_type';

    /**
     * Constructor.
     *
     * @param EligibilityRequestInterfaceFactory $factory
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        private readonly EligibilityRequestInterfaceFactory $factory,
        private readonly ScopeConfigInterface $scopeConfig,
    ) {
    }

    /**
     * @param array<string, bool> $customerDeclarations
     */
    public function build(
        OrderInterface $order,
        ?int $storeId = null,
        ?\DateTimeImmutable $submittedAt = null,
        array $customerDeclarations = [],
    ): EligibilityRequestInterface {
        $resolvedStoreId = $storeId ?? (int) $order->getStoreId();
        $contractType = (string) $this->scopeConfig->getValue(
            self::XML_CONTRACT_TYPE,
            ScopeInterface::SCOPE_STORE,
            $resolvedStoreId,
        );

        return $this->factory->create([
            'order' => $order,
            'contractType' => $contractType,
            'storeId' => $resolvedStoreId,
            'submittedAt' => $submittedAt ?? new \DateTimeImmutable(),
            'customerDeclarations' => $customerDeclarations,
        ]);
    }
}
