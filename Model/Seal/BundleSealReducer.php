<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Seal;

use MageMe\EUWithdrawal\Exception\IncompleteSealDeclarationException;
use Magento\Framework\Phrase;

class BundleSealReducer
{
    /**
     * @param BundleSealSubject[] $subjects
     * @param array<int, bool> $answers subject orderItemId => opened?
     * @param array<int, bool> $selectedLineIds line orderItemId => selected? (only true entries count as selected)
     * @return int[] line orderItemIds to exclude
     * @throws IncompleteSealDeclarationException when $requireComplete and the set is not exact
     */
    public function excludedLineItemIds(
        array $subjects,
        array $answers,
        array $selectedLineIds,
        bool $requireComplete = false,
    ): array {
        // Expected answer set = subjects whose line is actually selected.
        $expected = [];
        foreach ($subjects as $s) {
            if (($selectedLineIds[$s->lineOrderItemId] ?? false) === true) {
                $expected[$s->orderItemId] = $s;
            }
        }

        if ($requireComplete) {
            $this->assertExact($expected, $answers);
        }

        $excluded = [];
        foreach ($expected as $oid => $s) {
            if (($answers[$oid] ?? null) === true) {
                $excluded[$s->lineOrderItemId] = true;
            }
        }
        return array_keys($excluded);
    }

    /**
     * @param array<int, BundleSealSubject> $expected
     * @param array<int, bool> $answers
     * @throws IncompleteSealDeclarationException
     */
    private function assertExact(array $expected, array $answers): void
    {
        foreach ($answers as $oid => $value) {
            if (!is_bool($value)) {
                throw new IncompleteSealDeclarationException(new Phrase('Malformed seal answer.'));
            }
            if (!isset($expected[(int) $oid])) {
                throw new IncompleteSealDeclarationException(new Phrase('Seal answer references an unknown item.'));
            }
        }
        foreach (array_keys($expected) as $oid) {
            if (!array_key_exists($oid, $answers)) {
                throw new IncompleteSealDeclarationException(
                    new Phrase('Please answer the seal question for every sealed item.')
                );
            }
        }
    }
}
