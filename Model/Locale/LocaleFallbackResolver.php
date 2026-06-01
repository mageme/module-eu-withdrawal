<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Locale;

/**
 * Resolves the locale fallback chain for any caller (Annex I, button
 * labels, waiver, snapshot, Translate plugin). Walks regional → base-
 * language → en_US, capped to en_US as final fallback.
 *
 * Algorithm:
 *   1. Empty / malformed input → ['en_US'].
 *   2. Loop: append cursor; if cursor has explicit parent in
 *      etc/locale_inheritance.xml, follow it. Else stop.
 *   3. Always append en_US if not yet in the chain.
 *   4. Cycle detection: a code seen twice ends the loop.
 */
class LocaleFallbackResolver
{
    private const FALLBACK = 'en_US';

    /** @var array<string, list<string>> */
    private array $cache = [];

    /**
     * Constructor.
     *
     * @param LocaleInheritanceConfigReader $reader
     */
    public function __construct(
        private readonly LocaleInheritanceConfigReader $reader,
    ) {
    }

    /**
     * Resolve.
     *
     * @param string $locale
     * @return list<string>
     */
    public function resolve(string $locale): array
    {
        $key = trim($locale);
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $chain = $this->build($key);
        $this->cache[$key] = $chain;
        return $chain;
    }

    /**
     * Build.
     *
     * @param string $locale
     * @return list<string>
     */
    private function build(string $locale): array
    {
        if ($locale === '' || !preg_match('/^[a-z]{2,3}_[A-Z]{2}$/', $locale)) {
            return [self::FALLBACK];
        }

        $inheritance = $this->reader->read();
        $chain = [];
        $seen = [];
        $cursor = $locale;

        while ($cursor !== '' && !isset($seen[$cursor])) {
            $seen[$cursor] = true;
            $chain[] = $cursor;
            if (isset($inheritance[$cursor])) {
                $cursor = $inheritance[$cursor];
                continue;
            }
            break;
        }

        if (!in_array(self::FALLBACK, $chain, true)) {
            $chain[] = self::FALLBACK;
        }
        return $chain;
    }
}
