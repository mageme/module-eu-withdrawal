<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Ui\Component\Listing\Column;

use MageMe\EUWithdrawal\Model\Reimbursement\DueStateResolver;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

/**
 * Advisory, read-only reimbursement due-state cell. The state — not applicable,
 * paid, withheld, overdue or on track — is derived by DueStateResolver, the same
 * service the request edit screen uses, so the grid and the edit page always agree.
 * Rendered as plain translated text.
 */
class DueState extends Column
{
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        private readonly DueStateResolver $dueStateResolver,
        array $components = [],
        array $data = [],
    ) {
        parent::__construct($context, $uiComponentFactory, $components, $data);
    }

    public function prepareDataSource(array $dataSource): array
    {
        if (!isset($dataSource['data']['items'])) {
            return $dataSource;
        }
        $name = (string) $this->getData('name');
        foreach ($dataSource['data']['items'] as &$item) {
            $item[$name] = $this->dueStateResolver->resolve(
                (string) ($item['status'] ?? ''),
                (string) ($item['created_at'] ?? ''),
                (int) ($item['refund_creditmemo_id'] ?? 0),
                isset($item['reimbursement_withheld_at']) ? (string) $item['reimbursement_withheld_at'] : null,
                isset($item['reimbursement_paid_at']) ? (string) $item['reimbursement_paid_at'] : null,
            )['label'];
        }
        return $dataSource;
    }
}
