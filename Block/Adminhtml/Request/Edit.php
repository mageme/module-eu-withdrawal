<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Block\Adminhtml\Request;

use MageMe\EUWithdrawal\Api\Data\RequestInterface;
use MageMe\EUWithdrawal\Model\Adminhtml\PostApprovalActionPolicy;
use Magento\Backend\Block\Widget\Context;
use Magento\Backend\Block\Widget\Form\Container;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Registry;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;

class Edit extends Container
{
    protected $_coreRegistry = null;

    /**
     * Constructor.
     *
     * @param Context $context
     * @param Registry $registry
     * @param FormKey $formKeyGenerator
     * @param OrderRepositoryInterface $orderRepository
     * @param PostApprovalActionPolicy $actionPolicy
     * @param array $data
     */
    public function __construct(
        Context $context,
        Registry $registry,
        private readonly FormKey $formKeyGenerator,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly PostApprovalActionPolicy $actionPolicy,
        array $data = [],
    ) {
        $this->_coreRegistry = $registry;
        parent::__construct($context, $data);
    }

    /**
     * Construct.
     *
     * @return void
     */
    protected function _construct(): void
    {
        $this->_objectId = 'request_id';
        $this->_blockGroup = 'MageMe_EUWithdrawal';
        $this->_controller = 'adminhtml_request';
        parent::_construct();

        $this->buttonList->remove('save');
        $this->buttonList->remove('reset');
        $this->buttonList->remove('delete');

        $request = $this->getCurrentRequest();
        if ($request === null) {
            return;
        }
        $status = $request->getStatus();
        $id = (int) $request->getRequestId();
        $formKey = $this->formKeyGenerator->getFormKey();

        // Post-approval action button. If a credit memo has already been
        // linked to this request (via `Observer\LinkCreditMemoToRequest`),
        // swap the action button for a "View Credit Memo" link so the admin
        // can audit the refund without being able to accidentally issue a
        // duplicate one. New withdrawal requests on the same order get
        // their own button — the link is per-request, not per-order.
        //
        // For unpaid orders (no captured payment → no invoice → credit memo
        // not possible) the credit-memo button is replaced with native
        // Magento order cancellation. If the order can be neither
        // credit-memo'd nor cancelled (already cancelled, fully refunded,
        // closed, or missing) no action button is offered.
        if ($status === RequestInterface::STATUS_APPROVED) {
            $linkedCreditmemoId = (int) $request->getData('refund_creditmemo_id');
            if ($linkedCreditmemoId > 0) {
                $this->buttonList->add(
                    'view_credit_memo',
                    [
                        'label'   => __('View Credit Memo'),
                        'class'   => '',
                        'onclick' => sprintf(
                            "setLocation('%s')",
                            $this->getUrl(
                                'sales/order_creditmemo/view',
                                ['creditmemo_id' => $linkedCreditmemoId],
                            ),
                        ),
                    ],
                    -1,
                );
            } else {
                $action = $this->actionPolicy->resolve($this->resolveOrder($request));
                if ($action === PostApprovalActionPolicy::CREDITMEMO) {
                    $this->buttonList->add(
                        'issue_credit_memo',
                        [
                            'label'   => __('Issue Credit Memo'),
                            'class'   => 'primary',
                            'onclick' => sprintf(
                                "setLocation('%s')",
                                $this->getUrl(
                                    'mageme_eu_withdrawal/request/startCreditMemo',
                                    ['request_id' => $id],
                                ),
                            ),
                        ],
                        -1,
                    );
                } elseif ($action === PostApprovalActionPolicy::CANCEL) {
                    $this->buttonList->add(
                        'cancel_order',
                        [
                            'label'   => __('Cancel Order'),
                            'class'   => 'primary',
                            'onclick' => $this->buildPostJs(
                                $this->getUrl(
                                    'sales/order/cancel',
                                    ['order_id' => (int) $request->getOrderId()],
                                ),
                                $formKey,
                                (string) __('Cancel the linked order? No payment has been captured, so there is nothing to refund — the order will be cancelled in Magento.'),
                            ),
                        ],
                        -1,
                    );
                }
            }
        }

        if ($status === RequestInterface::STATUS_PENDING) {
            $this->buttonList->add(
                'approve_request',
                [
                    'label'   => __('Approve'),
                    'class'   => 'primary',
                    'onclick' => $this->buildPostJs(
                        $this->getUrl('*/*/approve', ['request_id' => $id]),
                        $formKey,
                        (string) __('Approve this withdrawal request?'),
                    ),
                ],
                -1,
            );
            $this->buttonList->add(
                'deny_request',
                [
                    'label'   => __('Deny'),
                    'class'   => '',
                    'onclick' => $this->buildPromptPostJs(
                        $this->getUrl('*/*/deny', ['request_id' => $id]),
                        $formKey,
                        (string) __('Legal reason for denial (min 10 chars):'),
                        'denial_reason',
                        10,
                    ),
                ],
                -1,
            );
            $this->buttonList->add(
                'cancel_request',
                [
                    'label'   => __('Cancel Request'),
                    'class'   => '',
                    'onclick' => $this->buildPromptPostJs(
                        $this->getUrl('*/*/cancel', ['request_id' => $id]),
                        $formKey,
                        (string) __('Cancel this withdrawal? Add an optional note for the customer (visible in their cancellation email):'),
                        'note',
                        0,
                    ),
                ],
                -1,
            );
        }

        // Resend receipt: available in any non-terminal state once a request
        // has been confirmed (receipt queue fires on confirm, so before that
        // there's nothing to resend).
        if ($request->getConfirmedAt()) {
            $this->buttonList->add(
                'resend_receipt',
                [
                    'label'   => __('Resend Receipt'),
                    'class'   => '',
                    'onclick' => $this->buildPostJs(
                        $this->getUrl('mageme_eu_withdrawal/receipt/resend', ['request_id' => $id]),
                        $formKey,
                        (string) __('Send the receipt email again? The customer will receive another copy.'),
                    ),
                ],
                -1,
            );
        }
    }

    /**
     * Get header text.
     *
     * @return \Magento\Framework\Phrase
     */
    public function getHeaderText(): \Magento\Framework\Phrase
    {
        $request = $this->getCurrentRequest();
        $display = $request
            ? ($request->getIncrementId() ?? sprintf('%09d', (int) $request->getRequestId()))
            : '—';
        return __('Withdrawal Request #%1', $display);
    }

    /**
     * Get current request.
     *
     * @return ?\MageMe\EUWithdrawal\Api\Data\RequestInterface
     */
    private function getCurrentRequest(): ?\MageMe\EUWithdrawal\Api\Data\RequestInterface
    {
        $row = $this->_coreRegistry->registry('mageme_eu_withdrawal_current_request');
        return $row instanceof RequestInterface ? $row : null;
    }

    /**
     * Resolve order.
     *
     * @param RequestInterface $request
     * @return ?Order
     */
    private function resolveOrder(RequestInterface $request): ?Order
    {
        $orderId = (int) $request->getOrderId();
        if ($orderId <= 0) {
            return null;
        }
        try {
            $order = $this->orderRepository->get($orderId);
        } catch (NoSuchEntityException) {
            return null;
        }
        return $order instanceof Order ? $order : null;
    }

    /**
     * Build post js.
     *
     * @param string $url
     * @param string $formKey
     * @param string $confirmMsg
     * @return string
     */
    private function buildPostJs(string $url, string $formKey, string $confirmMsg): string
    {
        // Inline POST-via-form for top-bar action buttons. Magento admin container
        // buttons expose only onclick; <a href> would require GET which our POST-only
        // controllers reject. Inline onclick attributes are not script-src-CSP blocked.
        $url = addslashes($url);
        $formKey = addslashes($formKey);
        $confirmMsg = addslashes($confirmMsg);
        return "if(confirm('{$confirmMsg}')){var f=document.createElement('form');f.method='POST';f.action='{$url}';var k=document.createElement('input');k.type='hidden';k.name='form_key';k.value='{$formKey}';f.appendChild(k);document.body.appendChild(f);f.submit();}";
    }

    /**
     * Build prompt post js.
     *
     * @param string $url
     * @param string $formKey
     * @param string $promptMsg
     * @param string $fieldName
     * @param int $minLength
     * @return string
     */
    private function buildPromptPostJs(
        string $url,
        string $formKey,
        string $promptMsg,
        string $fieldName,
        int $minLength,
    ): string {
        $url = addslashes($url);
        $formKey = addslashes($formKey);
        $promptMsg = addslashes($promptMsg);
        $field = addslashes($fieldName);
        $minLengthMsg = addslashes((string) __('Minimum %1 characters required.', $minLength));
        // minLength=0 → field is optional (empty input still submits, no length nag).
        return "var r=prompt('{$promptMsg}');if(r===null){return;}if(r.length<{$minLength}){alert('{$minLengthMsg}');return;}var f=document.createElement('form');f.method='POST';f.action='{$url}';var k=document.createElement('input');k.type='hidden';k.name='form_key';k.value='{$formKey}';f.appendChild(k);var v=document.createElement('input');v.type='hidden';v.name='{$field}';v.value=r;f.appendChild(v);document.body.appendChild(f);f.submit();";
    }
}
