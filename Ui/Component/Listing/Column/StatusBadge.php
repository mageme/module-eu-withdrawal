<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Ui\Component\Listing\Column;

use MageMe\EUWithdrawal\Api\Data\RequestInterface;
use MageMe\EUWithdrawal\Model\Request\Source\Status as StatusSource;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

/**
 * Wraps the request status cell in a coloured pill. Palette mirrors the
 * customer-facing Hyva storefront badge (sales/order/withdrawals_section.phtml)
 * so the same status reads identically on the front and in admin.
 *
 * The column declares `bodyTmpl=ui/grid/cells/html` in the listing XML,
 * so the unsanitised HTML returned here is rendered verbatim. To keep the
 * select filter intact, this class only rewrites the *display* value; the
 * filter dropdown still uses the `<options>` source defined in the column.
 */
class StatusBadge extends Column
{
    private array $labelByStatus;

    /**
     * Constructor.
     *
     * @param ContextInterface $context
     * @param UiComponentFactory $uiComponentFactory
     * @param StatusSource $statusSource
     * @param array $components
     * @param array $data
     */
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        StatusSource $statusSource,
        array $components = [],
        array $data = [],
    ) {
        parent::__construct($context, $uiComponentFactory, $components, $data);
        $this->labelByStatus = [];
        foreach ($statusSource->toOptionArray() as $option) {
            $this->labelByStatus[(string) ($option['value'] ?? '')] = (string) ($option['label'] ?? '');
        }
    }

    /**
     * Prepare data source.
     *
     * @param array $dataSource
     * @return array
     */
    public function prepareDataSource(array $dataSource): array
    {
        if (!isset($dataSource['data']['items'])) {
            return $dataSource;
        }
        $name = $this->getData('name');
        foreach ($dataSource['data']['items'] as &$item) {
            $status = isset($item[$name]) ? (string) $item[$name] : '';
            $item[$name] = $this->renderBadge($status);
        }
        return $dataSource;
    }

    /**
     * Render badge HTML. CSS-class suffix is derived from the whitelisted
     * status list to defend against unexpected values in the column.
     *
     * @param string $status
     * @return string
     */
    private function renderBadge(string $status): string
    {
        $known = in_array($status, RequestInterface::ALL_STATUSES, true);
        $suffix = $known ? $status : 'unknown';
        $label  = $this->labelByStatus[$status] ?? $status;
        return sprintf(
            '<span class="mageme-eu-w-status-badge mageme-eu-w-status-badge--%s">%s</span>',
            htmlspecialchars($suffix, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            htmlspecialchars($label, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        );
    }
}
