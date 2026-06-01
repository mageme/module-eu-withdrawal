<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Frontend;

use Magento\Framework\Module\Dir\Reader as ModuleDirReader;
use Magento\Framework\Config\ValidationStateInterface;
use Magento\Framework\Config\Dom;
use Magento\Framework\Config\Dom\ValidationException;

class ButtonLabelsConfigReader
{
    /** @var array<string, array{step1: string, step2: string, sidebar: string, _fallback: bool}>|null */
    private ?array $cache = null;

    /**
     * Constructor.
     *
     * @param ModuleDirReader $dirReader
     */
    public function __construct(
        private readonly ModuleDirReader $dirReader,
    ) {
    }

    /** @return array<string, array{step1: string, step2: string, sidebar: string, _fallback: bool}> */
    public function read(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }
        $etcDir  = $this->dirReader->getModuleDir('etc', 'MageMe_EUWithdrawal');
        $xmlPath = $etcDir . '/button_labels.xml';
        $xsdPath = $etcDir . '/button_labels.xsd';
        if (!is_file($xmlPath)) {
            throw new \RuntimeException('button_labels.xml missing at ' . $xmlPath);
        }
        $xml = file_get_contents($xmlPath);
        if ($xml === false) {
            throw new \RuntimeException('button_labels.xml unreadable at ' . $xmlPath);
        }

        // Magento's Dom skips XSD validation in production mode when the injected
        // ValidationStateInterface returns false. Our whitelist is legally frozen and
        // must fail loudly on structural drift regardless of deploy mode, so we pin
        // an always-validating state locally rather than reading it from DI.
        try {
            $dom = new Dom($xml, $this->alwaysValidatingState(), [], null, $xsdPath);
        } catch (ValidationException $e) {
            throw new \RuntimeException(
                'button_labels.xml failed XSD validation: ' . $e->getMessage(),
                0,
                $e,
            );
        }

        $this->cache = $this->parse($dom->getDom());
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

    /**
     * Get fallback code.
     *
     * @return string
     */
    public function getFallbackCode(): string
    {
        foreach ($this->read() as $code => $row) {
            if (!empty($row['_fallback'])) {
                return $code;
            }
        }
        throw new \RuntimeException('button_labels.xml: no locale marked fallback="true"');
    }

    /**
     * @return array<string, array{step1: string, step2: string, sidebar: string, _fallback: bool}>
     */
    private function parse(\DOMDocument $dom): array
    {
        $direct = [];
        $fallback = null;
        foreach ($dom->getElementsByTagName('locale') as $node) {
            /** @var \DOMElement $node */
            $code = $node->getAttribute('code');
            if ($code === '') {
                throw new \RuntimeException('button_labels.xml: <locale> missing code');
            }
            if ($node->getAttribute('fallback') === 'true') {
                $fallback = $code;
            }
            $direct[$code] = [
                'step1'     => $this->child($node, 'step1'),
                'step2'     => $this->child($node, 'step2'),
                'sidebar'   => $this->child($node, 'sidebar'),
                '_fallback' => false, // backfilled below after we know fallback code
            ];
        }
        if ($fallback === null) {
            throw new \RuntimeException('button_labels.xml: no locale marked fallback="true"');
        }
        if (isset($direct[$fallback])) {
            $direct[$fallback]['_fallback'] = true;
        }
        return $direct;
    }

    /**
     * Child.
     *
     * @param \DOMElement $node
     * @param string $tag
     * @return string
     */
    private function child(\DOMElement $node, string $tag): string
    {
        $nodes = $node->getElementsByTagName($tag);
        if ($nodes->length === 0) {
            throw new \RuntimeException(sprintf('button_labels.xml: %s missing <%s>', $node->getAttribute('code'), $tag));
        }
        return trim((string) $nodes->item(0)->textContent);
    }
}
