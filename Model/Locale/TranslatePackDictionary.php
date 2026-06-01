<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Locale;

use Magento\Framework\Module\Dir\Reader as ModuleDirReader;

/**
 * Loads a single locale's i18n CSV into a `[en_source => translation]` map.
 * Used by Plugin\Translate\MergeParentLanguageStrings to merge parent-
 * language strings into the active dictionary when the active locale's
 * CSV is missing or incomplete.
 *
 * Caches per-locale per-process. Returns empty array for missing files —
 * the merge plugin treats absent files as "no parent strings to merge."
 */
class TranslatePackDictionary
{
    private const MODULE_NAME = 'MageMe_EUWithdrawal';

    /** @var array<string, array<string, string>> */
    private array $cache = [];

    /**
     * Constructor.
     *
     * @param ModuleDirReader $dirReader
     */
    public function __construct(private readonly ModuleDirReader $dirReader)
    {
    }

    /**
     * Load for.
     *
     * @param string $locale
     * @return array<string, string>
     */
    public function loadFor(string $locale): array
    {
        if (isset($this->cache[$locale])) {
            return $this->cache[$locale];
        }
        $base = $this->dirReader->getModuleDir('', self::MODULE_NAME);
        $path = $base . '/i18n/' . $locale . '.csv';
        if (!is_file($path)) {
            $this->cache[$locale] = [];
            return $this->cache[$locale];
        }
        $rows = [];
        $fh = fopen($path, 'rb');
        if ($fh !== false) {
            while (($row = fgetcsv($fh, 0, ',', '"', '\\')) !== false) {
                if (count($row) >= 2) {
                    $rows[(string) $row[0]] = (string) $row[1];
                }
            }
            fclose($fh);
        }
        $this->cache[$locale] = $rows;
        return $rows;
    }
}
