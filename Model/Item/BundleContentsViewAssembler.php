<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Item;

use Magento\Sales\Api\Data\OrderInterface;

/**
 * Turns a bundle's components into the one shape every surface renders.
 *
 * Server-rendered screens (emails, admin, success page, account) call
 * `rowsFor()` with the quantity and refund a filed request recorded; it also
 * allocates the line's refund across the parts, which the admin screen shows as
 * the value each part contributed. The storefront gets `previewFor()`, which
 * carries names and quantities only — the customer-facing lists quote one
 * refund, on the bundle line itself.
 */
class BundleContentsViewAssembler
{
    public function __construct(
        private readonly BundleContentsResolver $contents,
        private readonly BundleContentsAllocator $allocator,
    ) {
    }

    /**
     * Component quantities read as counts, not measurements: "2", not "2.0000".
     * A genuinely fractional ratio keeps its decimals, trailing zeros trimmed.
     *
     * @param float $qty
     * @return string
     */
    public function formatQty(float $qty): string
    {
        if (abs($qty - round($qty)) < 0.0001) {
            return (string) (int) round($qty);
        }
        return rtrim(rtrim(number_format($qty, 4, '.', ''), '0'), '.');
    }

    /**
     * Component rows for one concrete withdrawal.
     *
     * @param OrderInterface $order
     * @param int $lineOrderItemId
     * @param float $returnedQty Bundles withdrawn.
     * @param float $lineGross Gross refund of the bundle line, which the parts add up to.
     * @return array<int, array{
     *     order_item_id: int,
     *     name: string,
     *     sku: string,
     *     qty: float,
     *     amount: ?float,
     *     physical: bool,
     *     seal_kind: ?string
     * }>
     */
    public function rowsFor(
        OrderInterface $order,
        int $lineOrderItemId,
        float $returnedQty,
        float $lineGross,
    ): array {
        $lines = $this->contents->resolve($order, $lineOrderItemId);
        if ($lines === []) {
            return [];
        }

        $rows = [];
        foreach ($this->allocator->allocate($lines, $returnedQty, $lineGross) as $allocation) {
            $rows[] = [
                'order_item_id' => $allocation->line->orderItemId,
                'name'          => $allocation->line->name,
                'sku'           => $allocation->line->sku,
                'qty'           => $allocation->qty,
                'amount'        => $allocation->amount,
                'physical'      => $allocation->line->physical,
                'seal_kind'     => $allocation->line->sealed?->kind->questionKind(),
            ];
        }
        return $rows;
    }

    /**
     * Storefront preview payload: what is inside the bundle and how many of
     * each go back per bundle returned. Null when the line holds nothing to
     * break out.
     *
     * Deliberately carries no money. The consumer can only withdraw the bundle
     * whole, so the one refund figure belongs on the bundle line; quoting a
     * price beside a part the consumer cannot return on its own invites exactly
     * the misunderstanding this list exists to prevent.
     *
     * @param OrderInterface $order
     * @param RemainingItemState $state
     * @return null|array{
     *     rows: array<int, array{
     *         order_item_id: int,
     *         name: string,
     *         sku: string,
     *         qty_per_unit: float,
     *         physical: bool,
     *         seal_kind: ?string
     *     }>
     * }
     */
    public function previewFor(OrderInterface $order, RemainingItemState $state): ?array
    {
        $lines = $this->contents->resolve($order, (int) $state->orderItemId);
        if ($lines === []) {
            return null;
        }

        $rows = [];
        foreach ($lines as $line) {
            $rows[] = [
                'order_item_id' => $line->orderItemId,
                'name'          => $line->name,
                'sku'           => $line->sku,
                'qty_per_unit'  => $line->qtyPerParentUnit,
                'physical'      => $line->physical,
                'seal_kind'     => $line->sealed?->kind->questionKind(),
            ];
        }

        return ['rows' => $rows];
    }
}
