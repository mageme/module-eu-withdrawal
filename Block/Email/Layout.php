<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Block\Email;

use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\Template;
use Magento\Store\Model\ScopeInterface;

/**
 * Shared layout block for branded transactional emails (notification, approved,
 * denied, cancelled-admin, cancelled-self, receipt). The sender renders header
 * and footer once per email and passes the resulting HTML to the per-event
 * template as `{{var email_header_html|raw}}` / `{{var email_footer_html|raw}}`.
 *
 * Header / footer markup lives in two phtml partials (templates/email/{header,footer}.phtml)
 * to keep designer-friendly HTML out of PHP. Branding inputs come from the
 * standard Magento configuration tree (general/store_information, design/email/logo,
 * trans_email/ident_support) plus a small `mageme_eu_withdrawal/email_branding`
 * group for USP and social URLs.
 */
class Layout extends Template
{
    private const CONFIG_USP_BASE    = 'mageme_eu_withdrawal/notifications/branding/usp_';
    private const CONFIG_SOCIAL_BASE = 'mageme_eu_withdrawal/notifications/branding/social/social_';

    /**
     * Get store name.
     *
     * @return string
     */
    public function getStoreName(): string
    {
        $name = (string) $this->_scopeConfig->getValue(
            'general/store_information/name',
            ScopeInterface::SCOPE_STORE,
            $this->resolveStoreId(),
        );
        if ($name !== '') {
            return $name;
        }
        return (string) $this->_storeManager->getStore($this->resolveStoreId())->getName();
    }

    /**
     * Get store url.
     *
     * @return string
     */
    public function getStoreUrl(): string
    {
        return $this->_storeManager->getStore($this->resolveStoreId())->getBaseUrl();
    }

    /**
     * Get logo url.
     *
     * @return ?string
     */
    public function getLogoUrl(): ?string
    {
        $logo = (string) $this->_scopeConfig->getValue(
            'design/email/logo',
            ScopeInterface::SCOPE_STORE,
            $this->resolveStoreId(),
        );
        if ($logo === '') {
            return null;
        }
        $base = $this->_storeManager->getStore($this->resolveStoreId())
            ->getBaseUrl(UrlInterface::URL_TYPE_MEDIA);
        return rtrim($base, '/') . '/logo/email/' . $logo;
    }

    /**
     * Get support email.
     *
     * @return string
     */
    public function getSupportEmail(): string
    {
        return (string) $this->_scopeConfig->getValue(
            'trans_email/ident_support/email',
            ScopeInterface::SCOPE_STORE,
            $this->resolveStoreId(),
        );
    }

    /**
     * Get store address.
     *
     * @return string
     */
    public function getStoreAddress(): string
    {
        $parts = array_filter([
            (string) $this->_scopeConfig->getValue('general/store_information/street_line1', ScopeInterface::SCOPE_STORE, $this->resolveStoreId()),
            (string) $this->_scopeConfig->getValue('general/store_information/city', ScopeInterface::SCOPE_STORE, $this->resolveStoreId()),
            (string) $this->_scopeConfig->getValue('general/store_information/postcode', ScopeInterface::SCOPE_STORE, $this->resolveStoreId()),
        ]);
        return implode(', ', $parts);
    }

    /**
     * Catalogue of bundled USP icons. Codes match
     * `Model\Config\Source\UspIcon::ICONS` so the admin dropdown stays in
     * sync with the rendering map. SVGs are stroke-based monochrome at
     * 24×24 viewBox, rendered at 22×22 in the email at #1a1a1a.
     */
    private const USP_SVGS = [
        'truck'   => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#1a1a1a" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M1 3h15v13H1z"/><path d="M16 8h4l3 3v5h-7z"/><circle cx="5.5" cy="18.5" r="2"/><circle cx="18.5" cy="18.5" r="2"/></svg>',
        'return'  => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#1a1a1a" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7h11a6 6 0 0 1 0 12H8"/><polyline points="7 3 3 7 7 11"/></svg>',
        'shield'  => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#1a1a1a" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2 4 5v6c0 5 3.5 9 8 11 4.5-2 8-6 8-11V5l-8-3z"/><polyline points="9 12 11 14 15 10"/></svg>',
        'lock'    => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#1a1a1a" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>',
        'gift'    => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#1a1a1a" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 12 20 22 4 22 4 12"/><rect x="2" y="7" width="20" height="5"/><line x1="12" y1="22" x2="12" y2="7"/><path d="M12 7H7.5a2.5 2.5 0 0 1 0-5C11 2 12 7 12 7zm0 0h4.5a2.5 2.5 0 0 0 0-5C13 2 12 7 12 7z"/></svg>',
        'star'    => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#1a1a1a" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15 9 22 9.5 17 14.5 18.5 22 12 18 5.5 22 7 14.5 2 9.5 9 9"/></svg>',
        'headset' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#1a1a1a" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 14v-3a9 9 0 0 1 18 0v3"/><path d="M21 16a3 3 0 0 1-3 3h-1v-6h1a3 3 0 0 1 3 3z"/><path d="M3 16a3 3 0 0 0 3 3h1v-6H6a3 3 0 0 0-3 3z"/></svg>',
        'clock'   => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#1a1a1a" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>',
        'globe'   => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#1a1a1a" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15 15 0 0 1 0 20 15 15 0 0 1 0-20z"/></svg>',
        'leaf'    => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#1a1a1a" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M21 4c-9 0-15 6-15 14 0 1 0 2 .5 3C13 21 21 13 21 4z"/><path d="M6 21C8 14 13 9 20 7"/></svg>',
        'tag'     => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#1a1a1a" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M20.6 13.4 12 22l-9-9V3h10z"/><circle cx="7.5" cy="7.5" r="1.5"/></svg>',
        'package' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#1a1a1a" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.7l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.7l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.3 7 12 12 20.7 7"/><line x1="12" y1="22" x2="12" y2="12"/></svg>',
    ];

    /**
     * @return array<int, array{title: string, subtitle: string, icon: string}>
     *         `icon` is a `data:image/svg+xml;base64,...` URL the phtml drops
     *         straight into an `<img src>`. Inline SVGs render in Apple Mail,
     *         iOS, Gmail web/mobile, Outlook web; old Outlook desktop strips
     *         them and falls back to the alt text.
     */
    public function getUspItems(): array
    {
        $fallback = [1 => 'truck', 2 => 'return', 3 => 'shield'];
        $items = [];
        for ($i = 1; $i <= 3; $i++) {
            $title = trim((string) $this->_scopeConfig->getValue(
                self::CONFIG_USP_BASE . $i . '/usp_' . $i . '_title',
                ScopeInterface::SCOPE_STORE,
                $this->resolveStoreId(),
            ));
            if ($title === '') {
                continue;
            }
            $subtitle = trim((string) $this->_scopeConfig->getValue(
                self::CONFIG_USP_BASE . $i . '/usp_' . $i . '_subtitle',
                ScopeInterface::SCOPE_STORE,
                $this->resolveStoreId(),
            ));
            $iconCode = (string) $this->_scopeConfig->getValue(
                self::CONFIG_USP_BASE . $i . '/usp_' . $i . '_icon',
                ScopeInterface::SCOPE_STORE,
                $this->resolveStoreId(),
            );
            $svg = self::USP_SVGS[$iconCode] ?? self::USP_SVGS[$fallback[$i]];
            $items[] = [
                'title'    => $title,
                'subtitle' => $subtitle,
                'icon'     => 'data:image/svg+xml;base64,' . base64_encode($svg),
            ];
        }
        return $items;
    }

    /**
     * @return array<string, string>  network code => URL
     */
    public function getSocialUrls(): array
    {
        $networks = ['instagram', 'x', 'facebook', 'youtube'];
        $out = [];
        foreach ($networks as $n) {
            $url = trim((string) $this->_scopeConfig->getValue(
                self::CONFIG_SOCIAL_BASE . $n,
                ScopeInterface::SCOPE_STORE,
                $this->resolveStoreId(),
            ));
            if ($url !== '') {
                $out[$n] = $url;
            }
        }
        return $out;
    }

    /**
     * Get current year.
     *
     * @return string
     */
    public function getCurrentYear(): string
    {
        return date('Y');
    }

    /**
     * BCP 47 language tag for the `<html lang="…">` attribute, derived from
     * the store-scope `general/locale/code` (Magento format `en_US`, converted
     * to `en-US` for HTML). Falls back to `en` when the store has no locale
     * configured (defensive — Magento ships a non-empty default).
     */
    public function getHtmlLang(): string
    {
        $locale = trim((string) $this->_scopeConfig->getValue(
            'general/locale/code',
            ScopeInterface::SCOPE_STORE,
            $this->resolveStoreId(),
        ));
        if ($locale === '') {
            return 'en';
        }
        return str_replace('_', '-', $locale);
    }

    /**
     * Disclaimer line shown above the address/copyright in the email footer.
     * Senders pass a context-appropriate string via `setData('disclaimer', ...)`
     * (e.g. "durable-medium copy of your waiver" for the Art. 11a(4) email,
     * "transactional notification about your withdrawal request" for the
     * lifecycle emails). Falls back to a neutral default that suits every
     * email type.
     */
    public function getDisclaimer(): string
    {
        $explicit = (string) $this->getData('disclaimer');
        if ($explicit !== '') {
            return $explicit;
        }
        return (string) __('This is a transactional email about your order.');
    }

    /**
     * Render header.
     *
     * @return string
     */
    public function renderHeader(): string
    {
        return $this->renderPartial(__DIR__ . '/../../view/frontend/templates/email/header.phtml');
    }

    /**
     * Render footer.
     *
     * @return string
     */
    public function renderFooter(): string
    {
        return $this->renderPartial(__DIR__ . '/../../view/frontend/templates/email/footer.phtml');
    }

    /**
     * Renders a phtml partial directly via include, bypassing Magento's
     * theme-aware view-file resolver. The transport-builder context (CLI
     * queue consumer + frontend emulation) does not always provide a usable
     * design theme to the resolver, which manifests as
     * "Invalid template file" errors when going through `_toHtml()`. The
     * partials live inside this module under `view/frontend/templates/email/`
     * and are not theme-overridable — direct include is both correct and
     * deterministic for our use.
     */
    private function renderPartial(string $absolutePath): string
    {
        $block   = $this;
        $escaper = $this->_escaper;
        ob_start();
        try {
            // phpcs:ignore Magento2.Security.IncludeFile.FoundIncludeFile -- module-shipped phtml partial, path is a hard-coded constant
            include $absolutePath;
        } finally {
            $rendered = (string) ob_get_clean();
        }
        return $rendered;
    }

    /**
     * Resolve store id.
     *
     * @return ?int
     */
    private function resolveStoreId(): ?int
    {
        $storeId = $this->getData('store_id');
        return $storeId !== null ? (int) $storeId : null;
    }
}
