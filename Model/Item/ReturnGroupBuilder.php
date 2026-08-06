<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Item;

use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderItemInterface;

/**
 * Organises the flat returnable states into display groups. Presentation only:
 * it re-uses the already-computed states and the order graph, performs no
 * eligibility or pricing business logic, and decides only structure. What sits
 * inside a whole-bundle line — and whether its parts carry quotable amounts —
 * comes from BundleContentsResolver, the one source every other surface reads.
 */
class ReturnGroupBuilder
{
    private const TYPE_BUNDLE = 'bundle';

    public function __construct(
        private readonly BundleReturnModeResolver $bundleReturnMode,
        private readonly ProductNameDecoder $productName,
        private readonly BundleContentsResolver $bundleContents,
    ) {
    }

    /**
     * @param array<int, RemainingItemState> $states states keyed by order_item_id, order preserved
     * @return ReturnGroup[]
     */
    public function build(OrderInterface $order, array $states): array
    {
        $perComponent = $this->bundleReturnMode->isPerComponent($order);

        $groups = [];
        /** @var array<int, int> $bundleGroupIndex parentItemId => index in $groups */
        $bundleGroupIndex = [];

        foreach ($states as $state) {
            $oi = $order->getItemById($state->orderItemId);
            if ($oi === null) {
                $groups[] = new ReturnGroup(ReturnGroup::TYPE_STANDALONE, null, null, [
                    new ReturnRow(selectable: true, state: $state),
                ]);
                continue;
            }

            // Per-component: a priced bundle child is the returnable unit; group under its bundle parent.
            $parentId = $oi->getParentItemId() !== null ? (int) $oi->getParentItemId() : null;
            if ($perComponent && $parentId !== null) {
                $parent = $order->getItemById($parentId);
                if ($parent !== null && $parent->getProductType() === self::TYPE_BUNDLE) {
                    if (!isset($bundleGroupIndex[$parentId])) {
                        $bundleGroupIndex[$parentId] = count($groups);
                        $groups[] = new ReturnGroup(
                            ReturnGroup::TYPE_BUNDLE,
                            $this->headerLabel($parent),
                            (string) $parent->getSku(),
                            [],
                            (int) $parent->getItemId(),
                        );
                    }
                    $g = $groups[$bundleGroupIndex[$parentId]];
                    $groups[$bundleGroupIndex[$parentId]] = new ReturnGroup(
                        $g->type, $g->headerLabel, $g->headerSku,
                        [...$g->rows, new ReturnRow(selectable: true, state: $state)],
                        $g->headerOrderItemId,
                    );
                    continue;
                }
            }

            // The selectable unit is the bundle parent itself (whole-bundle mode, or a
            // fixed-price bundle in per-component mode whose 0-priced children are never
            // broken out): render it as a bundle group with inert content children.
            if ($oi->getProductType() === self::TYPE_BUNDLE) {
                $groups[] = new ReturnGroup(
                    ReturnGroup::TYPE_BUNDLE, null, (string) $oi->getSku(),
                    [new ReturnRow(selectable: true, state: $state), ...$this->contentRows($order, $oi)],
                );
                continue;
            }

            $groups[] = new ReturnGroup(ReturnGroup::TYPE_STANDALONE, null, null, [
                new ReturnRow(selectable: true, state: $state),
            ]);
        }

        return $groups;
    }

    /**
     * Inert content rows for a whole-bundle parent — the goods that physically
     * travel back inside it.
     *
     * @return ReturnRow[]
     */
    private function contentRows(OrderInterface $order, OrderItemInterface $parent): array
    {
        $rows = [];
        foreach ($this->bundleContents->resolve($order, (int) $parent->getItemId()) as $content) {
            $rows[] = new ReturnRow(
                selectable: false,
                orderItemId: $content->orderItemId,
                label: $content->name,
                optionLabel: null,
                priced: $content->priced,
                sealed: $content->sealed,
                qtyPerParentUnit: $content->qtyPerParentUnit,
                physical: $content->physical,
            );
        }
        return $rows;
    }

    private function headerLabel(OrderItemInterface $parent): string
    {
        return $this->productName->decode((string) $parent->getName());
    }
}
