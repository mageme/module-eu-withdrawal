<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Plugin\Checkout;

use MageMe\EUWithdrawal\Block\Checkout\PrecontractInfo;
use MageMe\EUWithdrawal\Model\Frontend\RouteResolver;
use Magento\Checkout\Block\Checkout\LayoutProcessor as Subject;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\LayoutInterface;
use Psr\Log\LoggerInterface;

/**
 * Injects the precontract uiComponent into the payment step's jsLayout.
 * Position: as a child of `payment` step, sortOrder 250 (after standard
 * payment-method renderers and the Place Order button group, matching
 * the mockup which places the block visually below Apply Discount Code).
 */
class PrecontractLayoutPlugin
{
    /**
     * Constructor.
     *
     * @param UrlInterface $url
     * @param LayoutInterface $layout
     * @param LoggerInterface $logger
     * @param bool $enableProofLog Default: false. The Pro
     *        `MageMe_EUWithdrawalAnnexI` add-on flips this to true via
     *        `etc/di.xml` `<argument>` override so the JS uiComponent
     *        receives a non-empty `logEndpointUrl` and posts a display
     *        event on every render. When false, the URL is omitted from
     *        the jsLayout config and the JS guard
     *        `if (!this.logEndpointUrl) return` skips the fetch.
     */
    public function __construct(
        private readonly UrlInterface $url,
        private readonly LayoutInterface $layout,
        private readonly LoggerInterface $logger,
        private readonly bool $enableProofLog = false,
    ) {
    }

    /**
     * After process.
     *
     * @param Subject $subject
     * @param array $jsLayout
     * @return array
     */
    public function afterProcess(Subject $subject, array $jsLayout): array
    {
        try {
            /** @var PrecontractInfo $block */
            $block = $this->layout->createBlock(PrecontractInfo::class);
            if (!$block->isAvailable()) {
                return $jsLayout;
            }
            $snapshot = $block->getSnapshot();
            if ($snapshot === null) {
                return $jsLayout;
            }

            // Snapshot stores Annex I(A) as joined text (sections separated by "\n\n").
            // Split for KO foreach display.
            $sections = array_values(array_filter(array_map(
                fn(string $s) => trim($s),
                preg_split('/\n{2,}/', $snapshot->getAnnexIaText()) ?: [],
            )));
            $iaSections = array_map(
                fn(int $i, string $text) => ['id' => 'section_' . $i, 'text' => $text],
                array_keys($sections),
                array_values($sections),
            );

            // Inject the block into the payment step only. Per Art. 6(1)(h)
            // CRD the disclosure must precede the moment the consumer is
            // bound — in Magento that is the Place Order click on the
            // payment step. Showing it earlier (e.g. on shipping step) is
            // legally redundant and risks duplicate registrations.
            $jsLayout['components']['checkout']['children']['steps']['children']
                     ['billing-step']['children']['payment']['children']['mageme_eu_withdrawal_precontract'] = [
                'component'   => 'MageMe_EUWithdrawal/js/view/checkout/precontract',
                'sortOrder'   => 250,
                'displayArea' => 'afterMethods',
                'config'      => [
                    'logEndpointUrl'      => $this->enableProofLog
                        ? $this->url->getUrl(RouteResolver::CANONICAL_FRONT_NAME . '/precontract/logdisplay')
                        : '',
                    'downloadAnnexIbUrl'  => $this->url->getUrl(RouteResolver::CANONICAL_FRONT_NAME . '/precontract/downloadannexib'),
                    'snapshotVersion'     => $snapshot->getVersion(),
                    'publishedAt'         => $block->getFormattedPublishedAt(),
                    'annexIaSections'     => $iaSections,
                    'isExpanded'          => false,
                    'heading'             => (string) __('Right of Withdrawal — Required Information'),
                    'subhead'             => (string) __('Before placing your order, please review the conditions, time limit, and procedures for exercising your %1-day right of withdrawal. This information forms part of your contract.', $snapshot->getPeriodDays()),
                    'accordionTitle'      => (string) __('Conditions, Time Limit and Procedures for Withdrawal'),
                    'downloadLabel'       => (string) __('Download Model Withdrawal Form (Annex I(B))'),
                    'downloadHint'        => (string) __('Optional legal template — not required to use; you can also exercise the right of withdrawal by any unequivocal statement.'),
                    'footerText'          => (string) __(
                        'Withdrawal Information v%1 — Issued %2',
                        $snapshot->getVersion(),
                        $block->getFormattedPublishedAt(),
                    ),
                ],
            ];
        } catch (\Throwable $e) {
            $this->logger->warning('PrecontractLayoutPlugin: ' . $e->getMessage(), ['exception' => $e]);
        }
        return $jsLayout;
    }
}
