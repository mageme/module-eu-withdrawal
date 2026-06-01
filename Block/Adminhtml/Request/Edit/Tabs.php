<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Block\Adminhtml\Request\Edit;

use Magento\Backend\Block\Widget\Tabs as WidgetTabs;

class Tabs extends WidgetTabs
{
    /**
     * Construct.
     *
     * @return void
     */
    protected function _construct(): void
    {
        parent::_construct();
        $this->setId('mageme_eu_withdrawal_request_edit_tabs');
        $this->setDestElementId('edit_form');
        $this->setTitle((string) __('Request Details'));
        // CSP-friendly tabs template: stock Magento_Backend::widget/tabs.phtml
        // emits un-nonced inline scripts which Hyvä's admin CSP blocks.
        $this->setTemplate('MageMe_EUWithdrawal::widget/tabs.phtml');
    }

    /**
     * Before to html.
     *
     * @return self
     */
    protected function _beforeToHtml(): self
    {
        $this->addTab('general', [
            'label'   => __('Information'),
            'content' => $this->getLayout()->createBlock(
                \MageMe\EUWithdrawal\Block\Adminhtml\Request\Tab\General::class
            )->toHtml(),
            'active'  => true,
        ]);
        // Audit tab is provided by the Pro `MageMe_EUWithdrawalAudit`
        // add-on via layout-XML uiComponent injection. When that module
        // is not installed the lookup returns null and the tab is
        // silently skipped — same pattern as the precontract_proof tab.
        $auditBlock = $this->getLayout()->getBlock('mageme_eu_withdrawal_audit_listing');
        if ($auditBlock !== null) {
            $this->addTab('audit', [
                'label'   => __('Audit'),
                'content' => $auditBlock->toHtml(),
            ]);
        }

        // Pre-Contract Proof tab is provided by the Pro
        // `MageMe_EUWithdrawalAnnexI` add-on via layout-XML block injection.
        // When that module is not installed the lookup returns null and the
        // tab is silently skipped — the base module's Tabs widget never knows the Pro
        // class name.
        $proofBlock = $this->getLayout()->getBlock('mageme_eu_withdrawal_precontract_proof');
        if ($proofBlock !== null) {
            $this->addTab('precontract_proof', [
                'label'   => __('Pre-Contract Proof'),
                'content' => $proofBlock->toHtml(),
            ]);
        }

        return parent::_beforeToHtml();
    }
}
