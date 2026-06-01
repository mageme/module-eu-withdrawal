<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Waiver;

use Magento\Framework\Module\Dir\Reader;

class XmlWaiverTextLoader implements WaiverTextLoaderInterface
{
    /** @var array<string, array{consent:string,acknowledgment:string}>|null */
    private ?array $cache = null;

    /**
     * Constructor.
     *
     * @param Reader $moduleReader
     */
    public function __construct(
        private readonly Reader $moduleReader,
    ) {
    }

    /**
     * Load.
     *
     * @param string $locale
     * @param string $jurisdiction
     * @return ?array
     */
    public function load(string $locale, string $jurisdiction): ?array
    {
        $map = $this->read();
        return $map[$locale . '|' . $jurisdiction] ?? null;
    }

    /** @return array<string, array{consent:string,acknowledgment:string}> */
    private function read(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }
        $etcDir = $this->moduleReader->getModuleDir('etc', 'MageMe_EUWithdrawal');
        $file = $etcDir . '/waiver_templates.xml';
        $out = [];
        if (is_file($file)) {
            $xml = simplexml_load_file($file);
            if ($xml !== false) {
                foreach ($xml->template as $t) {
                    $loc = (string) $t['locale'];
                    $jur = (string) $t['jurisdiction'];
                    $out[$loc . '|' . $jur] = [
                        'consent' => trim((string) $t->consent),
                        'acknowledgment' => trim((string) $t->acknowledgment),
                    ];
                }
            }
        }
        return $this->cache = $out;
    }
}
