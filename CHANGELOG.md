## 1.0.9

+ New: Bundle Item Selection - return a bundle as a single unit (default) or by its individual components, so a device sold together with an accessory as a bundle can have each part withdrawn and refunded on its own, each with its own VAT.
+ New: The refund summary now presents VAT in line with your store's tax-display setting - gross prices with an "Of which VAT" note on tax-inclusive stores, or net prices with an added VAT line on tax-exclusive stores - across the item list, review step, success page and receipt.
- Fix: The review button no longer stays disabled when the store is set to withdraw the whole order at once, which could previously block the request.
- Fix: Storefront, email and notification texts now show your configured withdrawal period instead of a fixed 14 days.
- Fix: On stores set to move JavaScript to the bottom of the page, the refund summary no longer shows zero shipping and tax and the request submits correctly.
- Fix: A withdrawal can no longer refund units that were already cancelled or refunded through a Magento credit memo, so a partially-refunded order line is never refunded a second time.
- Fix: The self-cancellation return link now rejects off-site (protocol-relative or backslash) URLs, closing a redirect gap.
- Fix: The durable-medium receipt and waiver-confirmation email can no longer be sent twice when a queue message is redelivered or two workers overlap.
- Fix: Saving a withdrawal status change no longer risks overwriting a concurrent update to the request's receipt or acknowledgement data.
* Other: The new settings and refund-summary VAT wording are localised across all 22 locales.
* Other: The Hyvä companion waiver and form strings are localised across all 22 locales, and a stale translation entry was removed.

## 1.0.8

+ New: Admin withdrawal-request grid rows link directly to the order and shipment.
- Fix: Four admin settings that were defined but never applied now take effect.
- Fix: Guest order lookup is now rate-limited to resist order-number enumeration.
- Fix: Withdrawal is no longer offered for canceled orders.
- Fix: Long free-text reasons are truncated safely on non-Latin-script storefronts.
* Other: Localised the item-selection / full-order strings across all 22 locales and corrected the German confirm-label warning.

## 1.0.7

+ New: Restrict by Customer Group - optionally hide the self-service withdrawal flow from selected customer groups (e.g. your B2B / wholesale groups), while guests and consumers keep it
+ New: a new "If delivery is never confirmed" setting decides whether orders that reached a delivered status without a recorded delivery date stay open or are treated as not eligible (default)
+ New: Statuses Excluded From Withdrawal - choose order statuses (such as a legacy import status) that should never be offered for withdrawal
+ New: submitting a withdrawal request now adds a note with the requested refund to the related order's timeline
- Fix: the refund amount shown in the order-timeline notes and the durable-medium receipt now reflects the full amount instead of the shipping portion only
* Other: the checkout pre-contractual withdrawal information now follows the country and customer-group scope, so it shows only where the self-service withdrawal applies

## 1.0.6

+ New: the withdrawal-CTA order and shipment emails can now be selected directly from the Sales Emails template dropdown - no need to clone a template first
+ New: approving, denying or cancelling a request now adds a short note to the related order's timeline (refund amount, denial reason, or who cancelled), so the outcome is visible on the order itself
+ New: a drop-in "Withdraw from contract" link block for custom themes, so the link can be placed anywhere in the storefront
- Fix: status emails now send guests to the withdrawal page instead of a login page they cannot use
- Fix: the "Show Footer Link" setting now hides the storefront footer link when it is turned off
- Fix: the withdrawal form no longer errors on themes that do not include the optional photo-evidence step
* Other: the submission notification email now lists all of its available variables in the admin template editor

## 1.0.5

+ New: Item Selection setting - the withdrawal form can run in "Full order" mode where the request always covers all returnable items
+ New: Extension points for the Pro photo evidence step on the withdrawal form
+ New: Country Scope setting - optionally limit the self-service withdrawal flow to customers in selected countries
- Fix: final confirmation button now renders the legally required "Confirm withdrawal" label in the customer's language instead of "Submit return request"
- Fix: request creation no longer fails when a third-party extension dispatches the request-created event without eligibility data
- Fix: the "Withdraw from contract" link in order and shipment emails now renders when the email is resent from the admin or sent by a background job
- Fix: an order now stays available for withdrawal through the whole of its final eligible day
- Fix: a logged-in customer opening a withdrawal link for an order that is not their own is now turned away
* Other: Removed the redundant Eligibility column from the items table on the admin withdrawal request page.

## 1.0.4

- Fix: The digital-content waiver step no longer fails to save when the billing address is set during checkout.
* Other: Removed a non-functional Privacy & Retention settings group.
* Other: Internal cleanup, dead-code removal and a more robust order-lookup rate limiter.
* Other: Module permissions now appear under the Sales section in admin role permissions (thanks to Ole Schäfer).

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
