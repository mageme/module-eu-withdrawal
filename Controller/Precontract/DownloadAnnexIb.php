<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Controller\Precontract;

use MageMe\EUWithdrawal\Api\Data\Precontract\SnapshotResolverInterface;
use MageMe\EUWithdrawal\Model\ModuleConfig;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Controller\Result\ForwardFactory;
use Magento\Framework\Locale\ResolverInterface as LocaleResolver;
use Magento\Store\Model\StoreManagerInterface;

/**
 * GET /<vanity>/precontract/download_annex_ib.
 * Returns the rendered Annex I(B) model withdrawal form as text/plain
 * with a Content-Disposition: attachment header. No display-event
 * side-effect (download is post-display).
 */
class DownloadAnnexIb implements HttpGetActionInterface
{
    /**
     * Constructor.
     *
     * @param ModuleConfig $moduleConfig
     * @param SnapshotResolverInterface $snapshotResolver
     * @param StoreManagerInterface $storeManager
     * @param LocaleResolver $localeResolver
     * @param RawFactory $rawFactory
     * @param ForwardFactory $forwardFactory
     */
    public function __construct(
        private readonly ModuleConfig $moduleConfig,
        private readonly SnapshotResolverInterface $snapshotResolver,
        private readonly StoreManagerInterface $storeManager,
        private readonly LocaleResolver $localeResolver,
        private readonly RawFactory $rawFactory,
        private readonly ForwardFactory $forwardFactory,
    ) {
    }

    /**
     * Execute.
     *
     * @return ResultInterface
     */
    public function execute(): ResultInterface
    {
        if (!$this->moduleConfig->isEnabled()) {
            return $this->forwardFactory->create()->forward('noroute');
        }

        $locale = (string) $this->localeResolver->getLocale();
        $snapshot = $this->snapshotResolver->getOrCreateForCurrent($locale);

        $result = $this->rawFactory->create();
        $result->setHeader('Content-Type', 'text/plain; charset=utf-8', true);
        $result->setHeader('Content-Disposition', 'attachment; filename="model-withdrawal-form.txt"', true);
        $result->setHeader('X-Robots-Tag', 'noindex, nofollow', true);
        $result->setContents($snapshot->getAnnexIbText());
        return $result;
    }
}
