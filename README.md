# MageMe EU Withdrawal for Magento 2

> EU withdrawal button for Magento 2 — the consumer right-of-withdrawal flow
> required under Article 11a of the
> [Consumer Rights Directive (2011/83/EU)](https://eur-lex.europa.eu/eli/dir/2011/83/oj) as amended
> by [Directive (EU) 2023/2673](https://eur-lex.europa.eu/eli/dir/2023/2673/oj).

[![Latest Version](https://img.shields.io/packagist/v/mageme/module-eu-withdrawal.svg?style=flat-square)](https://packagist.org/packages/mageme/module-eu-withdrawal)
[![Downloads](https://img.shields.io/packagist/dt/mageme/module-eu-withdrawal.svg?style=flat-square)](https://packagist.org/packages/mageme/module-eu-withdrawal)
[![Magento](https://img.shields.io/badge/Magento-2.4.4%20–%202.4.9-EE672F.svg?style=flat-square)](https://magento.com)
[![PHP](https://img.shields.io/badge/PHP-8.1%20%7C%208.2%20%7C%208.3%20%7C%208.4%20%7C%208.5-777BB4.svg?style=flat-square)](https://php.net)
[![License](https://img.shields.io/badge/license-MageMe%20EULA-blue.svg?style=flat-square)](https://mageme.com/license/)

The Magento 2 withdrawal button your EU storefront needs — a storefront-ready, guided flow (find order → select items → review & confirm) with durable-medium receipt emails and Annex I content in 22 EU locales. It runs on Luma and Breeze out of the box, with companion modules for Hyvä and Hyvä Checkout.

It adds only the legal withdrawal step required under Art. 11a, and works alongside your existing RMA / refund process rather than replacing it.

**[Documentation](https://docs.mageme.com)** · **[Get Pro features](https://mageme.com/magento-2-withdrawal-button-extension.html)**

![Withdrawal button on the order page — shown automatically once the order is delivered](https://mageme.com/media/extensions/eu-withdrawal/order-view.png)

---

## What it does

- **Guided storefront flow** — find order → select items → review & confirm at `/withdraw-contract/`, for registered customers and guests (lookup by order number and the email on the order), with per-item or full-order selection
- **Article 16 exclusions** — presets for custom-made, perishable, sealed-hygiene, sealed audio/video, and digital-content goods, configurable per category with merchant override
- **Digital-content waiver at checkout (Art. 16(m))** — express-consent and right-loss acknowledgment step, with matching enforcement on REST/GraphQL and headless orders
- **Eligibility controls** — configurable withdrawal window, handling for orders with no recorded delivery date, merchant-excluded order statuses, and automatic exclusion of canceled orders
- **Scope controls** — limit the self-service flow by country and by customer group (for example, hide it from B2B / wholesale groups while guests and consumers keep it)
- **Durable-medium receipt** — confirmation email with a frozen snapshot of the legal text shown to the consumer
- **Annex I in 22 EU locales** — pre-contractual notice and model withdrawal form, verbatim EUR-Lex translations where available, theme-overridable per locale
- **Pro-rata refund calculation** — Art. 13(2)/14(2) totals (with order-level adjustments) shown in the admin and added to the order timeline; calculation only — it does not replace Magento's credit-memo / refund processing
- **Reimbursement tracking** — each approved request tracks its 14-day refund deadline (Art. 13(3)) with a due / withheld / overdue / refunded state and a credit-memo link, a daily overdue digest emailed to admins, the option to withhold a refund until the goods come back and resume it later, and a "mark refund as paid" action for refunds issued outside Magento (bank transfer or elsewhere) — after which no second refund can be raised
- **Admin grid and workflow** — filterable request list, status changes (approve / deny / cancel), order-timeline notes, column controls, and CSV export

## Screenshots

**Storefront — guided withdrawal flow** (find order → select items → review & confirm):

![Storefront withdrawal flow — select items to withdraw with per-item quantity](https://mageme.com/media/extensions/eu-withdrawal/storefront-flow.png)

**Admin — manage withdrawal requests** (status workflow, jurisdictions, refund totals, CSV export):

![Admin withdrawal requests grid](https://mageme.com/media/extensions/eu-withdrawal/admin-grid.png)

**Durable-medium receipt email** — the confirmation sent to the consumer with a frozen snapshot of the legal text they accepted. *(The SHA-256 integrity-hash card and one-click verification shown here are a [Pro](https://mageme.com/magento-2-withdrawal-button-extension.html) add-on.)*

![Durable-medium withdrawal receipt email; the SHA-256 integrity-hash card shown is a Pro add-on](https://mageme.com/media/extensions/eu-withdrawal/receipt-email.png)

## Free vs Pro

| Feature                                                    | Free | [Pro](https://mageme.com/magento-2-withdrawal-button-extension.html) |
|------------------------------------------------------------|:----:|:-------------------------------------------:|
| Storefront withdrawal flow                                 | Yes  |                     Yes                     |
| Annex I — 22 EU locales                                    | Yes  |                     Yes                     |
| Durable-medium receipt email                               | Yes  |                     Yes                     |
| Admin grid + status workflow                               | Yes  |                     Yes                     |
| Article 16 exclusion presets                               | Yes  |                     Yes                     |
| Per-item or full-order selection                           | Yes  |                     Yes                     |
| Country & customer-group scope                             | Yes  |                     Yes                     |
| Digital-content waiver (Art. 16(m))                        | Yes  |                     Yes                     |
| Eligibility controls (window, statuses, delivery)          | Yes  |                     Yes                     |
| API / headless waiver enforcement                          | Yes  |                     Yes                     |
| Pro-rata refund calculation                                | Yes  |                     Yes                     |
| Reimbursement deadline tracking & withhold                 | Yes  |                     Yes                     |
| **Receipt verification** — SHA-256 cryptographic audit     |  —   |                     Yes                     |
| **Annex I forensic snapshot** — immutable per-request copy |  —   |                     Yes                     |
| **Hash-chain audit log** — DB-backed, tamper-evident       |  —   |                     Yes                     |
| **Magic-link guest access** — one-click tokenised URL      |  —   |                     Yes                     |
| **Seal-photo evidence** — optional intact-seal photos      |  —   |                     Yes                     |
| **Auto-approval engine** — rule-based auto-approve + memo  |  —   |                     Yes                     |

→ **[Compare tiers and pricing](https://mageme.com/magento-2-withdrawal-button-extension.html)**

## Install

```bash
composer require mageme/module-eu-withdrawal
bin/magento module:enable MageMe_EUWithdrawal
bin/magento setup:upgrade
bin/magento cache:flush
```

After installation, enable the module at **Stores → Configuration → MageMe Extensions → EU Withdrawal**.

→ **[Full installation guide and configuration reference](https://docs.mageme.com/eu-withdrawal/install)**

On **Luma** and **Breeze** (Swissup) storefronts the base module works out of the box — no extra package required. Only **Hyvä** themes need the companion module below.

### Hyvä storefront

If your storefront runs on a Hyvä theme, install the theme companion alongside this module — it ports the
customer-facing withdrawal flow and order-view integrations to Tailwind + Alpine.js:

```bash
composer require mageme/module-eu-withdrawal-hyva
bin/magento module:enable Hyva_MageMeEUWithdrawal
bin/magento setup:upgrade
```

If you also run **Hyvä Checkout**, add the checkout companion as well — it re-implements the pre-contract Annex I block
and the digital-content waiver step (Art. 16(m)) for Hyvä Checkout's Magewire runtime:

```bash
composer require mageme/module-eu-withdrawal-hyva-checkout
bin/magento module:enable Hyva_MageMeEUWithdrawalCheckout
bin/magento setup:upgrade
```

→ **[Hyvä theme companion](https://github.com/mageme/module-eu-withdrawal-hyva)** · **[Hyvä Checkout companion](https://github.com/mageme/module-eu-withdrawal-hyva-checkout)**

## Requirements

- **Magento 2.4.4 – 2.4.9** (Open Source / Commerce)
- **PHP 8.1, 8.2, 8.3, 8.4, or 8.5** — match your Magento version's PHP support matrix
- MySQL 8.0+ / MariaDB 10.6+

## Legal disclaimer

This module is provided **AS-IS, without warranty**. It is a **technical implementation** of the workflow described in
[Article 11a of Directive 2011/83/EU](https://eur-lex.europa.eu/eli/dir/2011/83/oj); it is **not legal advice** and has
not been reviewed by EU consumer-law counsel.

The merchant is solely responsible for verifying that this implementation satisfies their jurisdiction's specific
consumer-protection requirements, reviewing bundled translations for accuracy in their target markets, and adapting
Article 16 exclusion presets to their actual catalogue.

### Streaming and SaaS subscriptions — out of scope

This module's Art. 16(m) waiver flow is for **one-time digital content** — extensions, ebooks, courses, software
licences, downloadable templates. It is **not** for ongoing **digital services** (streaming, SaaS subscriptions),
where the waiver may not remove the withdrawal right — a digital-content vs digital-service distinction now before the
CJEU in [Case C-234/25](https://curia.europa.eu/juris/liste.jsf?num=C-234/25). For subscription cancellations and
pro-rata refunds, use your billing platform's own cancellation flow.

→ **[Full disclaimer and merchant compliance checklist](https://docs.mageme.com)**

### Digital-content waiver on API / headless orders

The Art. 16(m) waiver record is created only from a genuine storefront
confirmation (the customer ticking both consent boxes). By default, digital-content orders placed through the
REST/GraphQL API are **blocked** unless a genuine consent record exists for each digital item (the same protection
the storefront enforces). If your headless integration collects the consent itself, record it through the
waiver-event mechanism and the order will pass; or set **Stores → Configuration → MageMe Extensions →
EU Withdrawal → Digital Waiver → Enforce Waiver on API / Headless Orders** to **No** to opt out of API enforcement
(the storefront waiver step is unaffected either way).

## Custom Magento development

Need a feature an extension doesn't cover, or a bespoke Magento build? MageMe takes on custom extension development and integration work.

→ **[Custom Magento development](https://mageme.com/magento-services/custom-development)**

## Support

- Documentation: [docs.mageme.com](https://docs.mageme.com)
- Bug reports and feature requests: [GitHub Issues](https://github.com/mageme/module-eu-withdrawal/issues)

> **Note:** This GitHub Issues tracker is for **free tier** bug reports and feature requests only.

## Changelog

See [`CHANGELOG.md`](CHANGELOG.md). This module follows [Semantic Versioning](https://semver.org).

## License

All tiers are governed by the **MageMe End User License Agreement** ([mageme.com/license](https://mageme.com/license/)).
The base module is distributed free of charge; Pro requires a paid commercial licence.

---

**MageMe** builds Magento 2 and Adobe Commerce extensions for B2B merchants — form building, quoting, catalog control, and EU compliance.