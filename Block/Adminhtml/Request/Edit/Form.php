<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Block\Adminhtml\Request\Edit;

use Magento\Backend\Block\Widget\Form\Generic;

class Form extends Generic
{
    /**
     * The request view is read-only + tab-driven (General / Items / Audit / Comms);
     * no form fields to render here. Empty form satisfies Container's child-Form
     * expectation so the page renders. Tab contents come from sibling blocks.
     */
    protected function _prepareForm()
    {
        $form = $this->_formFactory->create([
            'data' => [
                'id'     => 'edit_form',
                'action' => '#',
                'method' => 'post',
            ],
        ]);
        $form->setUseContainer(true);
        $this->setForm($form);
        return parent::_prepareForm();
    }
}
