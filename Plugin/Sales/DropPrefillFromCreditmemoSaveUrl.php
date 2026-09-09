<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Plugin\Sales;

use Magento\Sales\Block\Adminhtml\Order\Creditmemo\Create\Form;

/**
 * Removes the `creditmemo[...]` prefill from the New Credit Memo form's submit
 * URL. StartCreditMemo opens the form with the prefill in the query string and
 * core builds the save URL with `_current`, which copies that query onto the
 * POST. Core then reads `creditmemo` via getParam(), where the query wins over
 * the posted form — so the admin's qty, shipping and adjustment edits would be
 * replaced by the prefill. Stripping the key here makes the submitted form the
 * only source. Every other query component and the fragment are kept verbatim.
 */
class DropPrefillFromCreditmemoSaveUrl
{
    private const PREFILL_KEY = 'creditmemo';

    /**
     * After get save url.
     *
     * @param Form $subject
     * @param string $result
     * @return string
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterGetSaveUrl(Form $subject, string $result): string
    {
        $queryStart = strpos($result, '?');
        if ($queryStart === false) {
            return $result;
        }

        $fragment = '';
        $fragmentStart = strpos($result, '#', $queryStart);
        if ($fragmentStart !== false) {
            $fragment = substr($result, $fragmentStart);
            $result = substr($result, 0, $fragmentStart);
        }

        $kept = array_filter(
            explode('&', substr($result, $queryStart + 1)),
            static fn (string $component): bool => !self::isPrefillComponent($component),
        );

        $base = substr($result, 0, $queryStart);
        $query = implode('&', $kept);

        return ($query === '' ? $base : $base . '?' . $query) . $fragment;
    }

    /**
     * Whether a raw query component is `creditmemo` or `creditmemo[...]`.
     *
     * @param string $component
     * @return bool
     */
    private static function isPrefillComponent(string $component): bool
    {
        $rawKey = explode('=', $component, 2)[0];
        $key = urldecode($rawKey);

        return $key === self::PREFILL_KEY || str_starts_with($key, self::PREFILL_KEY . '[');
    }
}
