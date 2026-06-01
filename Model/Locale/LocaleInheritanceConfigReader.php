<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Locale;

use MageMe\EUWithdrawal\Model\Locale\Exception\LocaleFallbackException;
use Magento\Framework\Config\Dom;
use Magento\Framework\Config\Dom\ValidationException;
use Magento\Framework\Config\ValidationStateInterface;
use Magento\Framework\Module\Dir\Reader as ModuleDirReader;

/**
 * Parses etc/locale_inheritance.xml into a flat map
 * `regional_code => parent_code` consumed by LocaleFallbackResolver.
 *
 * Cached per-process. Validates against `etc/locale_inheritance.xsd` on
 * every read; throws `LocaleFallbackException` on missing or malformed
 * file.
 */
class LocaleInheritanceConfigReader
{
    /** @var array<string, string>|null */
    private ?array $cache = null;

    /**
     * Constructor.
     *
     * @param ModuleDirReader $dirReader
     */
    public function __construct(private readonly ModuleDirReader $dirReader)
    {
    }

    /**
     * Read.
     *
     * @return array<string, string>  regional_code => parent_code
     * @throws LocaleFallbackException
     */
    public function read(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }
        $etcDir = $this->dirReader->getModuleDir('etc', 'MageMe_EUWithdrawal');
        $xmlPath = $etcDir . '/locale_inheritance.xml';
        $xsdPath = $etcDir . '/locale_inheritance.xsd';
        if (!is_file($xmlPath)) {
            throw new LocaleFallbackException(
                __('locale_inheritance.xml missing at %1', $xmlPath),
            );
        }
        $xml = file_get_contents($xmlPath);
        if ($xml === false) {
            throw new LocaleFallbackException(
                __('locale_inheritance.xml unreadable at %1', $xmlPath),
            );
        }

        // Magento's Dom skips XSD validation in production mode when the injected
        // ValidationStateInterface returns false. A misconfigured locale_inheritance.xml
        // would silently route every regional locale to en_US instead of its proper
        // parent, so we pin an always-validating state locally rather than reading it
        // from DI.
        try {
            $dom = new Dom($xml, $this->alwaysValidatingState(), [], null, $xsdPath);
        } catch (ValidationException $e) {
            throw new LocaleFallbackException(
                __('locale_inheritance.xml failed XSD validation: %1', $e->getMessage()),
                $e,
            );
        }

        $map = [];
        foreach ($dom->getDom()->getElementsByTagName('locale') as $node) {
            /** @var \DOMElement $node */
            $code = $node->getAttribute('code');
            $parent = $node->getAttribute('parent');
            if ($code !== '' && $parent !== '') {
                $map[$code] = $parent;
            }
        }
        $this->cache = $map;
        return $this->cache;
    }

    /**
     * Always validating state.
     *
     * @return ValidationStateInterface
     */
    private function alwaysValidatingState(): ValidationStateInterface
    {
        return new class implements ValidationStateInterface {
            /**
             * Is validation required.
             *
             * @return bool
             */
            public function isValidationRequired(): bool
            {
                return true;
            }
        };
    }
}
