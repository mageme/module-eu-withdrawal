<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Item;

/**
 * Splits a bundle line's refund across the components inside it.
 *
 * The split is largest-remainder in minor currency units: every component gets
 * the whole part of its share, and the units left over by rounding go to the
 * components with the largest fractional parts, ties broken by order-item id.
 * The parts therefore add up to the bundle line exactly, and the result does
 * not depend on the order the components arrive in — a plain per-component
 * round would leave a stray unit, and pushing that unit onto whichever row
 * happened to be last would move money around when the order of
 * `getAllItems()` changed.
 */
class BundleContentsAllocator
{
    /** Minor currency units the split reconciles in — the display precision. */
    private const PRECISION = 2;

    private const EPSILON = 0.000001;

    /**
     * @param BundleContentLine[] $lines Ordered by order-item id.
     * @param float $returnedQty Bundles being withdrawn.
     * @param float $lineGross Gross refund of the bundle line, which the parts
     *        must add up to.
     * @return BundleContentAllocation[]
     */
    public function allocate(array $lines, float $returnedQty, float $lineGross): array
    {
        if ($lines === []) {
            return [];
        }

        $shares = $this->shares($lines, $lineGross);

        $out = [];
        foreach (array_values($lines) as $i => $line) {
            $out[] = new BundleContentAllocation(
                line: $line,
                qty: $line->qtyPerParentUnit * $returnedQty,
                amount: $shares[$i],
            );
        }
        return $out;
    }

    /**
     * Gross share per component, or all-null when there is nothing to split.
     *
     * @param BundleContentLine[] $lines
     * @param float $lineGross
     * @return array<int, ?float> Indexed positionally over $lines.
     */
    private function shares(array $lines, float $lineGross): array
    {
        $lines = array_values($lines);
        $none = array_fill(0, count($lines), null);

        $weights = [];
        $totalWeight = 0.0;
        foreach ($lines as $line) {
            if (!$line->priced) {
                return $none;
            }
            $weights[] = $line->rowGross();
            $totalWeight += $line->rowGross();
        }
        if (abs($totalWeight) < self::EPSILON) {
            return $none;
        }

        $factor = 10 ** self::PRECISION;
        $units = (int) round($lineGross * $factor, 0, PHP_ROUND_HALF_EVEN);

        // Whole units first; the remainders decide who gets the rest. Σfloor is
        // never above $units, so the leftover is in [0, count) and only ever
        // handed out, never taken back.
        $whole = [];
        $remainders = [];
        $allocated = 0;
        foreach ($weights as $i => $weight) {
            $raw = $units * $weight / $totalWeight;
            $floor = (int) floor($raw);
            $whole[$i] = $floor;
            $remainders[$i] = $raw - $floor;
            $allocated += $floor;
        }

        $order = array_keys($whole);
        usort($order, function (int $a, int $b) use ($remainders, $lines): int {
            return [$remainders[$b], $lines[$a]->orderItemId] <=> [$remainders[$a], $lines[$b]->orderItemId];
        });
        foreach (array_slice($order, 0, max(0, $units - $allocated)) as $i) {
            $whole[$i]++;
        }

        return array_map(static fn (int $u): float => $u / $factor, $whole);
    }
}
