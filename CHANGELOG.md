## 1.0.3

- Fix: The refund total now matches the order total when a payment-method discount, gift card or other order-level adjustment applies — such orders previously could not be withdrawn or showed an inflated refund.
- Fix: The refund total shown right after submitting a withdrawal now includes the shipping refund.
- Fix: Corrected the German translation of the "Withdraw from contract" label.

## 1.0.2

+ New: Digital-content detection can now recognise digital items inside bundle products.
- Fix: Submission confirmation email and the merchant copy now also send for requests made through the storefront app.
- Fix: The store logo now appears in withdrawal emails.
- Fix: Email USP and social icons now display in Gmail, Outlook and Yahoo.
- Fix: Withdrawal emails are sent in the store's language instead of always English.
* Other: Storefront-app submissions now go through the same rate-limiting and audit logging as the form.

## 1.0.1

+ New: "Show more" in the order picker — load more eligible orders on demand.
- Fix: Order picker lists newest orders first.
- Fix: Order picker honours a withdrawal period over 14 days.
- Fix: Delivered date is consistent between the order list and detail.
- Fix: Admin request view no longer crashes in the free edition.

## 1.0.0

+ New: First public release. EU right-of-withdrawal management for Magento 2 — a guided customer withdrawal flow (find order, pick items, review, submit), eligibility rules, automatic refund calculation, and durable-medium receipt emails.
+ New: Admin request management — withdrawal request grid, request detail screen, approve/deny workflow, and configurable withdrawal period and return address.
+ New: Pre-contractual Annex I information notice on checkout, plus a downloadable model withdrawal form (Art. 6(1)(h)).
+ New: Digital-content waiver step with express consent and loss-of-right acknowledgement (Art. 16(m)).
+ New: Merchant alert email on each new request, and a "Withdraw from contract" call-to-action in order and shipment confirmation emails.
+ New: Translations for 22 EU locales.
