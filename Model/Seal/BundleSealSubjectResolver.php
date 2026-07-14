<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Seal;

use MageMe\EUWithdrawal\Api\Seal\SealKindResolverInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderItemInterface;

class BundleSealSubjectResolver
{
    private const TYPE_BUNDLE = 'bundle';
    private const TYPE_CONFIGURABLE = 'configurable';

    /** Parent types whose selected line a sealed child gates. */
    private const GATING_PARENT_TYPES = [self::TYPE_BUNDLE, self::TYPE_CONFIGURABLE];

    public function __construct(
        private readonly SealKindResolverInterface $sealKind,
    ) {
    }

    /**
     * @param array<int, bool> $selectedLineIds lineOrderItemId => selected? A sealed
     *        bundle child gates its parent line when that parent line is selected
     *        (the parent is the returnable unit); otherwise it gates itself.
     * @return BundleSealSubject[]
     */
    public function resolve(OrderInterface $order, array $selectedLineIds = []): array
    {
        $storeId = (int) $order->getStoreId();

        $byId = [];
        foreach ($order->getItems() ?? [] as $item) {
            $byId[(int) $item->getItemId()] = $item;
        }

        $subjects = [];
        foreach ($byId as $item) {
            /** @var OrderItemInterface $item */
            $kind = $this->sealKind->resolve((int) $item->getProductId(), $storeId);
            if (!$kind->isSealed()) {
                continue;
            }
            $parentId = $item->getParentItemId() !== null ? (int) $item->getParentItemId() : null;
            $parent = $parentId !== null ? ($byId[$parentId] ?? null) : null;
            $gatesParent = $parent !== null
                && in_array($parent->getProductType(), self::GATING_PARENT_TYPES, true)
                && (($selectedLineIds[(int) $parent->getItemId()] ?? false) === true);
            $lineId = $gatesParent ? (int) $parent->getItemId() : (int) $item->getItemId();
            $subjects[] = new BundleSealSubject((int) $item->getItemId(), $lineId, $kind);
        }
        return $subjects;
    }
}
