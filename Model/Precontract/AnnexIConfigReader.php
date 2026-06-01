<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Precontract;

use MageMe\EUWithdrawal\Model\Locale\LocaleFallbackResolver;
use MageMe\EUWithdrawal\Model\Precontract\Exception\AnnexIConfigException;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\Filesystem;
use Magento\Framework\Module\Dir as ModuleDir;
use Magento\Framework\Phrase;
use Magento\Framework\View\DesignInterface;
use Psr\Log\LoggerInterface;

/**
 * Loads the Annex I "Information on the right of withdrawal" XML for a locale.
 *
 * Resolution order, per chain entry from LocaleFallbackResolver:
 * 1. **Theme override** — walks the active storefront theme's inheritance chain
 *    looking for `app/design/frontend/<Vendor>/<Theme>/MageMe_EUWithdrawal/precontract/annex_i_<locale>.xml`.
 *    First match wins; theme inheritance is respected so a child theme's file
 *    overrides its parent's.
 * 2. **Module bundled** — `app/code/MageMe/EUWithdrawal/view/frontend/precontract/annex_i_<locale>.xml`.
 *
 * The reader does not consume any admin DB textarea — those were dropped in
 * 0.12.8 (file-only override; admin can no longer accidentally save broken XML).
 *
 * Returned structure:
 *   ['ia_sections' => [['id'=>..., 'text'=>...], ...], 'ib_text' => string]
 *
 * When the chain entry that resolves is NOT the originally requested locale,
 * dispatches `mageme_eu_withdrawal_audit_pre_contract_locale_fallback` so the
 * merchant has a defensibility trail. Caches per-locale per-process.
 */
class AnnexIConfigReader
{
    /** @var array<string, array{ia_sections: array<int, array{id: string, text: string}>, ib_text: string}> */
    private array $cache = [];

    /** @var string|null Cached absolute path to <MAGENTO_DIR>/app/design. */
    private ?string $designRootPath = null;

    /** @var string|null Cached absolute path to module's view/frontend/precontract dir. */
    private ?string $moduleViewPath = null;

    /**
     * Constructor.
     *
     * @param ModuleDir $moduleDir
     * @param LocaleFallbackResolver $fallbackResolver
     * @param EventManager $eventManager
     * @param LoggerInterface $logger
     * @param DesignInterface $design
     * @param Filesystem $filesystem
     */
    public function __construct(
        private readonly ModuleDir $moduleDir,
        private readonly LocaleFallbackResolver $fallbackResolver,
        private readonly EventManager $eventManager,
        private readonly LoggerInterface $logger,
        private readonly DesignInterface $design,
        private readonly Filesystem $filesystem,
    ) {
    }

    /**
     * Read.
     *
     * @param string $locale
     * @return array{ia_sections: array<int, array{id: string, text: string}>, ib_text: string}
     * @throws AnnexIConfigException
     */
    public function read(string $locale): array
    {
        if (isset($this->cache[$locale])) {
            return $this->cache[$locale];
        }

        $chain = $this->fallbackResolver->resolve($locale);
        $usedLocale = null;
        $rawXml = null;

        foreach ($chain as $candidate) {
            $candidatePath = $this->resolvePath($candidate);
            if ($candidatePath !== null) {
                $contents = file_get_contents($candidatePath);
                if ($contents === false) {
                    continue;
                }
                $usedLocale = $candidate;
                $rawXml = $contents;
                break;
            }
        }

        if ($rawXml === null || $usedLocale === null) {
            throw new AnnexIConfigException(
                new Phrase(
                    'No Annex I source found for locale %1 along chain %2',
                    [$locale, implode(' → ', $chain)]
                )
            );
        }

        if ($usedLocale !== $locale) {
            $this->dispatchFallbackAudit($locale, $usedLocale, $chain);
        }

        $xml = simplexml_load_string($rawXml);
        if ($xml === false || !isset($xml->annex_ia, $xml->annex_ib)) {
            throw new AnnexIConfigException(
                new Phrase('Annex I source for locale %1 is malformed', [$usedLocale])
            );
        }

        $iaSections = [];
        foreach ($xml->annex_ia->section as $section) {
            $iaSections[] = [
                'id'   => (string) $section['id'],
                'text' => (string) $section,
            ];
        }

        $result = [
            'ia_sections' => $iaSections,
            'ib_text'     => trim((string) $xml->annex_ib),
        ];
        $this->cache[$locale] = $result;
        return $result;
    }

    /**
     * Resolve path.
     *
     * Walks the active storefront theme inheritance chain (child → parent → ...)
     * looking for `<theme-dir>/MageMe_EUWithdrawal/precontract/annex_i_<locale>.xml`.
     * Falls back to the module's bundled
     * `view/frontend/precontract/annex_i_<locale>.xml` when no theme override
     * matches.
     *
     * @param string $locale
     * @return ?string
     */
    private function resolvePath(string $locale): ?string
    {
        $relative = '/MageMe_EUWithdrawal/precontract/annex_i_' . $locale . '.xml';

        try {
            $theme = $this->design->getDesignTheme();
        } catch (\Throwable) {
            $theme = null;
        }
        $designRoot = $this->getDesignRootPath();
        while ($theme !== null && $designRoot !== null) {
            $themePath = (string) $theme->getThemePath();
            if ($themePath !== '') {
                $candidate = $designRoot . '/frontend/' . $themePath . $relative;
                if (is_file($candidate)) {
                    return $candidate;
                }
            }
            $parent = $theme->getParentTheme();
            $theme = $parent === $theme ? null : $parent;
        }

        $moduleViewPath = $this->getModuleViewPath();
        if ($moduleViewPath === null) {
            return null;
        }
        $bundled = $moduleViewPath . '/annex_i_' . $locale . '.xml';
        return is_file($bundled) ? $bundled : null;
    }

    /**
     * Get design root path.
     *
     * @return string|null
     */
    private function getDesignRootPath(): ?string
    {
        if ($this->designRootPath !== null) {
            return $this->designRootPath;
        }
        try {
            $appDir = $this->filesystem->getDirectoryRead(DirectoryList::APP);
            $this->designRootPath = $appDir->getAbsolutePath('design');
        } catch (\Throwable $e) {
            $this->logger->warning('AnnexI: cannot resolve design root: ' . $e->getMessage());
            return null;
        }
        return $this->designRootPath;
    }

    /**
     * Get module view path.
     *
     * @return string|null
     */
    private function getModuleViewPath(): ?string
    {
        if ($this->moduleViewPath !== null) {
            return $this->moduleViewPath;
        }
        try {
            $base = $this->moduleDir->getDir('MageMe_EUWithdrawal', ModuleDir::MODULE_VIEW_DIR);
            $this->moduleViewPath = $base . '/frontend/precontract';
        } catch (\Throwable $e) {
            $this->logger->warning('AnnexI: cannot resolve module view path: ' . $e->getMessage());
            return null;
        }
        return $this->moduleViewPath;
    }

    /**
     * Dispatch fallback audit.
     *
     * @param string $requested
     * @param string $used
     * @param list<string> $chain
     * @return void
     */
    private function dispatchFallbackAudit(string $requested, string $used, array $chain): void
    {
        try {
            $this->logger->info(
                sprintf(
                    'AnnexI locale fallback: requested=%s used=%s chain=%s',
                    $requested,
                    $used,
                    implode('→', $chain)
                )
            );
            $this->eventManager->dispatch(
                'mageme_eu_withdrawal_audit_pre_contract_locale_fallback',
                [
                    'requested_locale' => $requested,
                    'used_locale'      => $used,
                    'chain'            => $chain,
                ]
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'AnnexI fallback audit dispatch failed: ' . $e->getMessage(),
                ['exception' => $e]
            );
        }
    }
}
