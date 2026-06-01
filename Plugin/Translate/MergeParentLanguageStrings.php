<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Plugin\Translate;

use MageMe\EUWithdrawal\Model\Locale\LocaleFallbackResolver;
use MageMe\EUWithdrawal\Model\Locale\TranslatePackDictionary;
use Magento\Framework\Translate;

/**
 * Plugin: after `Magento\Framework\Translate::getData()` returns the
 * active locale's translation map, walk the LocaleFallbackResolver chain
 * and merge parent-language CSV strings for any keys still untranslated.
 *
 * Effect: a Magento store at locale "de_AT" transparently sees German
 * (de_DE) strings without us shipping a separate i18n/de_AT.csv. Active-
 * locale strings always win — this plugin only fills gaps.
 *
 * The underlying `LocaleFallbackResolver::resolve()` and
 * `TranslatePackDictionary::loadFor()` are already memoized per-process,
 * so the merge itself is cheap on repeated calls.
 */
class MergeParentLanguageStrings
{
    /**
     * Constructor.
     *
     * @param LocaleFallbackResolver $resolver
     * @param TranslatePackDictionary $dict
     */
    public function __construct(
        private readonly LocaleFallbackResolver $resolver,
        private readonly TranslatePackDictionary $dict,
    ) {
    }

    /**
     * After get data.
     *
     * @param Translate $subject
     * @param array<string, string> $result
     * @return array<string, string>
     */
    public function afterGetData(Translate $subject, array $result): array
    {
        $active = (string) $subject->getLocale();
        if ($active === '' || $active === 'en_US') {
            return $result;
        }

        $chain = $this->resolver->resolve($active);
        $merged = $result;

        foreach (array_slice($chain, 1) as $parent) {
            if ($parent === 'en_US') {
                break;
            }
            $parentDict = $this->dict->loadFor($parent);
            if ($parentDict === []) {
                continue;
            }
            foreach ($parentDict as $en => $tr) {
                $existing = $merged[$en] ?? null;
                if ($existing === null || $existing === $en) {
                    $merged[$en] = $tr;
                }
            }
        }

        return $merged;
    }
}
