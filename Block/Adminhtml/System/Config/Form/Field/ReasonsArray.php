<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Block\Adminhtml\System\Config\Form\Field;

use Magento\Config\Block\System\Config\Form\Field\FieldArray\AbstractFieldArray;

/**
 * Dynamic grid for the per-item return-reason dropdown.
 *
 * Renders two columns — `code` (machine value) and `label` (visible text) —
 * inside the standard Magento system-config FieldArray widget. Persisted as
 * a serialized array via the matching ArraySerialized backend model.
 */
class ReasonsArray extends AbstractFieldArray
{
    /**
     * Prepare to render.
     *
     * @return void
     */
    protected function _prepareToRender(): void
    {
        $this->addColumn('code', [
            'label' => __('Code'),
            'class' => 'required-entry validate-code',
        ]);
        $this->addColumn('label', [
            'label' => __('Label'),
            'class' => 'required-entry',
        ]);
        $this->_addAfter = false;
        $this->_addButtonLabel = (string) __('Add Reason');
    }
}
