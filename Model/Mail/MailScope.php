<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Mail;

use Magento\Framework\App\Area;
use Magento\Framework\Locale\ResolverInterface as LocaleResolver;
use Magento\Framework\Phrase;
use Magento\Framework\Phrase\RendererInterface;
use Magento\Framework\TranslateInterface;
use Magento\Store\Model\App\Emulation;
use Psr\Log\LoggerInterface;

/**
 * Renders and sends one email inside a single store + locale scope.
 *
 * Queue consumers run in the `global` CLI area, which — unlike cron — never
 * loads the translate part of an area, so `Phrase` keeps a non-translating
 * renderer. Magento binds the translating one from `Emulation` since 2.4.8
 * only; binding it here keeps emails translated on 2.4.5–2.4.7 as well.
 *
 * Re-entrant: a nested call reuses the open scope. Magento allows one level
 * of emulation, and a second `stopEnvironmentEmulation()` would tear down the
 * outer one.
 *
 * One scope covers one email: rendering a template ends the emulation
 * (`Email\Model\AbstractTemplate::cancelDesignConfig()`), so a second email
 * needs a second scope.
 */
class MailScope
{
    /**
     * @var int
     */
    private int $depth = 0;

    /**
     * @var bool
     */
    private bool $emulationLeftOpen = false;

    /**
     * Constructor.
     *
     * @param Emulation $emulation
     * @param LocaleResolver $localeResolver
     * @param TranslateInterface $translate
     * @param RendererInterface $phraseRenderer
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly Emulation $emulation,
        private readonly LocaleResolver $localeResolver,
        private readonly TranslateInterface $translate,
        private readonly RendererInterface $phraseRenderer,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Run a callback with the store emulated and the given locale applied.
     *
     * @param int $storeId
     * @param string $locale Empty keeps the emulated store's own locale.
     * @param callable $callback
     * @return mixed
     */
    public function run(int $storeId, string $locale, callable $callback)
    {
        if ($this->depth > 0) {
            return $callback();
        }

        if ($this->emulationLeftOpen) {
            // Magento keeps its emulation flagged as active when a teardown
            // fails, which would turn the next start into a silent no-op and
            // render this email inside the previous store's scope. If the
            // scope still refuses to close, this email must not be sent.
            $this->emulation->stopEnvironmentEmulation();
            $this->emulationLeftOpen = false;
        }

        $previousRenderer = Phrase::getRenderer();
        $this->depth++;

        try {
            $this->emulation->startEnvironmentEmulation($storeId, Area::AREA_FRONTEND, true);
            Phrase::setRenderer($this->phraseRenderer);
            if ($locale !== '') {
                $this->localeResolver->setLocale($locale);
                $this->translate->setLocale($locale);
                $this->translate->loadData(Area::AREA_FRONTEND, true);
            }

            return $callback();
        } finally {
            // A consumer keeps processing after a failed message, so the scope
            // has to come back clean however this run ended.
            $this->closeScope();
            Phrase::setRenderer($previousRenderer);
            $this->depth--;
        }
    }

    /**
     * Close the emulation, keeping a teardown failure away from the caller.
     *
     * Whether the scope came back cleanly says nothing about whether the email
     * was sent, so a failure here must not replace the outcome the caller
     * already has — a queue consumer would file a delivered receipt as failed
     * and send it a second time. The scope stays flagged as open instead, and
     * the next run clears it before opening its own.
     *
     * @return void
     */
    private function closeScope(): void
    {
        try {
            $this->emulationLeftOpen = true;
            $this->emulation->stopEnvironmentEmulation();
            $this->emulationLeftOpen = false;
        } catch (\Throwable $e) {
            $this->logger->error(
                'EU Withdrawal: could not close the email scope: ' . $e->getMessage(),
                ['exception' => $e],
            );
        }
    }
}
