/**
 * SPA state machine for the withdrawal flow.
 *
 * Responsibilities:
 *   - Track selected items (qty + metadata) across step 2/3 transitions
 *   - Swap active panel on "Continue to review", "Back to items", "Submit"
 *   - Mirror panel state to the top-of-page progress stepper
 *   - POST the finalize request as JSON to the API endpoint, render panel 4
 *     from the response
 *
 * CSP notes: no inline handlers, no eval, no innerHTML of untrusted content.
 * DOM mutations use textContent or cloned static templates only.
 */
(() => {
    'use strict';

    const ready = (cb) => {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', cb);
        } else {
            cb();
        }
    };

    ready(() => {
        const spa = document.querySelector('.mm-eu-w-spa');
        if (!spa) return;

        const bootstrapScript = spa.querySelector('script[data-role="bootstrap"]');
        let boot = {};
        if (bootstrapScript) {
            try {
                boot = JSON.parse(bootstrapScript.textContent || '{}');
            } catch {
                boot = {};
            }
        }
        const itemSelectorBootScript = spa.querySelector('script[data-role="item-selector-boot"]');
        if (itemSelectorBootScript) {
            try {
                const selectorBoot = JSON.parse(itemSelectorBootScript.textContent || '{}');
                if (selectorBoot.selectionMode) boot.selectionMode = selectorBoot.selectionMode;
            } catch {
                // leave boot unchanged
            }
        }
        // Magento renders the session form_key inside the step2 form as
        // <input name="form_key">. Bootstrap's `formKey` is null on pages where
        // Template::getFormKey() isn't available, so read from DOM as a fallback.
        if (!boot.formKey) {
            const hidden = spa.querySelector('input[name="form_key"]');
            if (hidden) {
                boot.formKey = hidden.value;
            }
        }

        const state = {
            items: new Map(),   // itemId -> {qty, price, name, thumb}
            // itemReasons[itemId] = {code: string, codeLabel: string, text: string}
            //   - code: machine value of the <select> ('' | 'changed_mind' | ... | 'other')
            //   - codeLabel: human label of the chosen <option> (rendered in review)
            //   - text: free-text typed into the textarea (used when code === 'other')
            itemReasons: new Map(),
            step: '2',
        };

        // Per-item reason wiring: read live from the DOM controls inserted by
        // item_selector.phtml. Each row carries data-role="reason-row" with
        // data-item-id; the inner <select> + <textarea> hold the values.
        const readReasonForItem = (itemId) => {
            const row = document.querySelector(
                '[data-role="reason-row"][data-item-id="' + String(itemId) + '"]',
            );
            if (!row) return null;
            const sel = row.querySelector('[data-role="reason-select"]');
            const txt = row.querySelector('[data-role="reason-other"]');
            const code = sel ? sel.value : '';
            const opt = sel && sel.value !== '' ? sel.options[sel.selectedIndex] : null;
            const codeLabel = opt ? (opt.textContent || '').trim() : '';
            const text = txt ? (txt.value || '').trim() : '';
            return { code, codeLabel, text };
        };

        document.addEventListener('change', (evt) => {
            const t = evt.target;
            if (!t || !t.matches) return;
            if (!t.matches('[data-role="reason-select"], [data-role="reason-other"]')) return;
            const row = t.closest('[data-role="reason-row"]');
            if (!row) return;
            const itemId = Number(row.dataset.itemId);
            const data = readReasonForItem(itemId);
            if (data) state.itemReasons.set(itemId, data);
        });
        document.addEventListener('input', (evt) => {
            const t = evt.target;
            if (!t || !t.matches || !t.matches('[data-role="reason-other"]')) return;
            const row = t.closest('[data-role="reason-row"]');
            if (!row) return;
            const itemId = Number(row.dataset.itemId);
            const data = readReasonForItem(itemId);
            if (data) state.itemReasons.set(itemId, data);
        });

        document.addEventListener('mm-eu-w:qty-changed', (evt) => {
            const { itemId, qty } = evt.detail;
            const row = document.querySelector(
                '.mm-eu-w-item-row[data-item-id="' + String(itemId) + '"]',
            );
            if (!row) return;
            if (qty <= 0) {
                state.items.delete(itemId);
                state.itemReasons.delete(itemId);
            } else {
                state.items.set(itemId, {
                    qty,
                    price: Number(row.dataset.price || 0),
                    name: row.dataset.name || '',
                    thumb: row.dataset.thumb || '',
                });
            }
        });

        // Full-order mode: seal radio changes dispatch qty-changed so both
        // withdrawal-app and withdrawal-summary stay in sync. "broken" dispatches
        // qty 0 (item excluded); "intact" dispatches the remaining qty from the
        // row's data-remaining attribute.
        if (boot.selectionMode === 'full_order') {
            document.addEventListener('change', (evt) => {
                const radio = evt.target;
                if (!radio || !radio.matches || !radio.matches('input[data-role="seal-input"]')) return;
                const sealRow = radio.closest('[data-role="seal-row"]');
                if (!sealRow) return;
                const itemId = Number(sealRow.dataset.itemId);
                const itemRow = document.querySelector('.mm-eu-w-item-row[data-item-id="' + String(itemId) + '"]');
                if (radio.value === '1' && radio.checked) {
                    document.dispatchEvent(new CustomEvent('mm-eu-w:qty-changed', { detail: { itemId, qty: 0 } }));
                } else if (radio.value === '0' && radio.checked) {
                    const qty = itemRow ? Number(itemRow.dataset.remaining || 0) : 0;
                    document.dispatchEvent(new CustomEvent('mm-eu-w:qty-changed', { detail: { itemId, qty } }));
                }
            });
        }

        // ---- Panel routing ----
        const panels = {
            '2': spa.querySelector('.mm-eu-w-panel[data-step="2"]'),
            '3': spa.querySelector('.mm-eu-w-panel[data-step="3"]'),
            '4': spa.querySelector('.mm-eu-w-panel[data-step="4"]'),
        };

        const stepperSteps = document.querySelectorAll('.mm-eu-w-step');

        const setStepper = (activeN) => {
            stepperSteps.forEach((el, idx) => {
                const n = idx + 1;
                el.classList.remove('mm-eu-w-step--active', 'mm-eu-w-step--done', 'mm-eu-w-step--upcoming');
                if (n < activeN) el.classList.add('mm-eu-w-step--done');
                else if (n === activeN) el.classList.add('mm-eu-w-step--active');
                else el.classList.add('mm-eu-w-step--upcoming');
            });
        };

        // Dismiss any Magento messageManager banner ("Withdrawal cancelled.",
        // etc.) that was rendered on full-page load — it belongs to the
        // previous step and shouldn't linger as the user navigates forward.
        const clearFlashMessages = () => {
            document.querySelectorAll('.messages, .page.messages, .messages > div, .message').forEach((el) => {
                if (el.closest('.mm-eu-w-spa')) return;
                el.remove();
            });
        };

        const showPanel = (step) => {
            for (const [key, el] of Object.entries(panels)) {
                if (!el) continue;
                if (key === step) {
                    el.classList.add('is-active');
                    el.removeAttribute('aria-hidden');
                } else {
                    el.classList.remove('is-active');
                    el.setAttribute('aria-hidden', 'true');
                }
            }
            setStepper(Number(step));
            state.step = step;
            clearFlashMessages();
            // Instant scroll, not smooth: iOS Safari ignores `behavior:'smooth'`
            // unreliably on programmatic scroll, and on small viewports a 750 ms
            // animation makes the panel switch feel laggy. Jump immediately so
            // the new step's header is the first thing the user sees.
            window.scrollTo(0, 0);
        };

        // ---- Step 3 render ----
        const formatPrice = (amount, currency) => {
            try {
                return new Intl.NumberFormat(document.documentElement.lang || 'en-US', {
                    style: 'currency',
                    currency: currency || 'EUR',
                }).format(amount);
            } catch {
                return (currency || 'EUR') + ' ' + amount.toFixed(2);
            }
        };

        // Banker's rounding mirroring PHP's PHP_ROUND_HALF_EVEN. Required so
        // the JS-displayed refund total exactly matches what RefundCalculator
        // writes to mm_eu_withdrawal_request.
        const roundHalfEven = (value, dp) => {
            if (!isFinite(value)) return 0;
            const factor = Math.pow(10, dp);
            const scaled = value * factor;
            const floor = Math.floor(scaled);
            const diff = scaled - floor;
            if (Math.abs(diff - 0.5) < 1e-9) {
                return ((floor % 2 === 0) ? floor : floor + 1) / factor;
            }
            return Math.round(scaled) / factor;
        };

        const lineTaxFor = (id, qty) => {
            const meta = eligibleItems[String(id)];
            if (!meta) return 0;
            if (typeof meta.taxAmount !== 'undefined' && typeof meta.qtyOrdered !== 'undefined') {
                const ordered = Number(meta.qtyOrdered) || 1;
                return roundHalfEven(qty * Number(meta.taxAmount || 0) / ordered, 4);
            }
            // Legacy unitTax fallback (pre-0.12.2 bootstrap)
            return qty * Number(meta.unitTax || 0);
        };

        const sidebarEl = document.querySelector('.mm-eu-w-sidebar');
        const currency = sidebarEl?.dataset.currency || 'EUR';

        // Resolve the display string for one item's reason. Free text wins
        // over the preset label when "Other" was picked.
        const reasonDisplayFor = (itemId) => {
            const r = state.itemReasons.get(itemId);
            if (!r) return '';
            if (r.code === 'other') {
                const t = (r.text || '').trim();
                return t !== '' ? t : r.codeLabel;
            }
            return r.codeLabel || '';
        };

        const shippingPaid = Number(boot.shippingPaid || 0);
        const shippingTax  = Number(boot.shippingTax || 0);
        const eligibleItems = boot.eligibleItems || {};

        // Full-order mode: seed both stores by dispatching qty-changed for each
        // eligible row. The data-remaining attribute on the <tr> carries the qty;
        // both this file's listener and withdrawal-summary.js's listener receive
        // the event (both registered earlier in this same ready() callback or in
        // withdrawal-summary.js's onReady() which also runs synchronously since
        // all three scripts are defer-loaded in document order).
        if (boot.selectionMode === 'full_order') {
            document.querySelectorAll('.mm-eu-w-item-row[data-remaining]').forEach((el) => {
                const itemId = Number(el.dataset.itemId);
                const qty = Number(el.dataset.remaining);
                if (qty > 0) {
                    document.dispatchEvent(new CustomEvent('mm-eu-w:qty-changed', { detail: { itemId, qty } }));
                }
            });
        }

        const renderReview = () => {
            const tbody = panels['3'].querySelector('[data-role="review-items"]');
            const itemsTotalEl = panels['3'].querySelector('[data-role="review-items-total"]');
            const shippingEl = panels['3'].querySelector('[data-role="review-shipping-paid"]');
            const taxEl = panels['3'].querySelector('[data-role="review-tax"]');
            const totalEl = panels['3'].querySelector('[data-role="review-total-refund"]');

            while (tbody.firstChild) tbody.removeChild(tbody.firstChild);
            let total = 0;
            for (const [itemId, data] of state.items.entries()) {
                const line = data.qty * data.price;
                total += line;
                const tr = document.createElement('tr');
                tr.dataset.itemId = String(itemId);

                const thTd = document.createElement('td');
                thTd.className = 'mm-eu-w-col-thumb';
                if (data.thumb) {
                    const img = document.createElement('img');
                    img.src = data.thumb;
                    img.alt = data.name;
                    thTd.appendChild(img);
                } else {
                    // Inline SVG package icon (matches server-rendered fallback).
                    const ns = 'http://www.w3.org/2000/svg';
                    const svg = document.createElementNS(ns, 'svg');
                    svg.setAttribute('class', 'mm-eu-w-thumb-fallback-svg');
                    svg.setAttribute('viewBox', '0 0 24 24');
                    svg.setAttribute('width', '24');
                    svg.setAttribute('height', '24');
                    svg.setAttribute('fill', 'none');
                    svg.setAttribute('stroke', 'currentColor');
                    svg.setAttribute('stroke-width', '2');
                    svg.setAttribute('stroke-linecap', 'round');
                    svg.setAttribute('stroke-linejoin', 'round');
                    svg.setAttribute('aria-hidden', 'true');
                    const p1 = document.createElementNS(ns, 'path');
                    p1.setAttribute('d', 'M3 9l9-4 9 4v10l-9 4-9-4z');
                    const p2 = document.createElementNS(ns, 'polyline');
                    p2.setAttribute('points', '3 9 12 13 21 9');
                    const p3 = document.createElementNS(ns, 'line');
                    p3.setAttribute('x1', '12'); p3.setAttribute('y1', '13');
                    p3.setAttribute('x2', '12'); p3.setAttribute('y2', '22');
                    svg.appendChild(p1); svg.appendChild(p2); svg.appendChild(p3);
                    thTd.appendChild(svg);
                }
                tr.appendChild(thTd);

                const nameTd = document.createElement('td');
                nameTd.className = 'mm-eu-w-col-item';
                const nameDiv = document.createElement('div');
                nameDiv.className = 'mm-eu-w-item-name';
                nameDiv.textContent = data.name;
                nameTd.appendChild(nameDiv);
                tr.appendChild(nameTd);

                const qtyTd = document.createElement('td');
                qtyTd.className = 'mm-eu-w-col-qty';
                qtyTd.textContent = String(data.qty);
                tr.appendChild(qtyTd);

                const reasonTd = document.createElement('td');
                reasonTd.className = 'mm-eu-w-col-reason';
                reasonTd.textContent = reasonDisplayFor(itemId) || '—';
                tr.appendChild(reasonTd);

                const totalTd = document.createElement('td');
                totalTd.className = 'mm-eu-w-col-total';
                totalTd.textContent = formatPrice(line, currency);
                tr.appendChild(totalTd);

                tbody.appendChild(tr);
            }

            // Full-return detection — same logic as step 2 sidebar: every
            // eligible line's full remaining qty is selected.
            const eligibleIds = Object.keys(eligibleItems);
            let fullReturn = eligibleIds.length > 0 && state.items.size === eligibleIds.length;
            if (fullReturn) {
                for (const id of eligibleIds) {
                    const entry = state.items.get(Number(id));
                    const need = Number(eligibleItems[id].qty ?? eligibleItems[id]);
                    if (!entry || entry.qty < need) {
                        fullReturn = false;
                        break;
                    }
                }
            }
            let tax = 0;
            for (const [id, data] of state.items.entries()) {
                if (data.qty <= 0) continue;
                tax += lineTaxFor(id, data.qty);
            }
            tax = roundHalfEven(tax, 4);
            // Shipping refund includes shipping VAT under EU Art. 14(2);
            // matches RefundCalculator since 0.12.2.
            const shippingRefund = fullReturn ? roundHalfEven(shippingPaid + shippingTax, 4) : 0;
            const grandRefund = roundHalfEven(total + tax + shippingRefund, 4);

            itemsTotalEl.textContent = formatPrice(total, currency);
            if (shippingEl) shippingEl.textContent = formatPrice(shippingRefund, currency);
            if (taxEl) taxEl.textContent = formatPrice(tax, currency);
            totalEl.textContent = formatPrice(grandRefund, currency);

            // Let add-ons (Pro seal photos) decorate the freshly-built review
            // rows, which carry data-item-id for lookup.
            document.dispatchEvent(new CustomEvent('mm-eu-w:review-rendered'));
        };

        // ---- Step 4 render ----
        const renderSubmitted = (data) => {
            const rrEl = panels['4'].querySelector('[data-role="rr-number"]');
            const emailEl = panels['4'].querySelector('[data-role="customer-email"]');

            rrEl.textContent = data.incrementId || ('#' + String(data.requestId || 0));
            rrEl.dataset.copy = rrEl.textContent;
            emailEl.textContent = data.customerEmail || '';

            // Bottom 2-col card: Return summary — order header, per-item list, total
            const headerEl = panels['4'].querySelector('[data-role="submitted-order-header"]');
            const itemsEl = panels['4'].querySelector('[data-role="submitted-items"]');
            const totalEl = panels['4'].querySelector('[data-role="submitted-total"]');
            if (headerEl) {
                headerEl.textContent = boot.orderIncrementId
                    ? ((boot.i18n && boot.i18n.orderPrefix) || 'Order #') + boot.orderIncrementId
                    : '';
            }
            if (itemsEl) {
                while (itemsEl.firstChild) itemsEl.removeChild(itemsEl.firstChild);
                let subtotal = 0;
                let taxTotal = 0;
                let selectedCount = 0;
                for (const [id, d] of state.items.entries()) {
                    if (d.qty <= 0) continue;
                    selectedCount += 1;
                    const line = d.qty * d.price;
                    subtotal += line;
                    taxTotal += lineTaxFor(id, d.qty);

                    const li = document.createElement('li');
                    li.className = 'mm-eu-w-summary-item';

                    const nameDiv = document.createElement('div');
                    nameDiv.className = 'mm-eu-w-summary-name';
                    nameDiv.textContent = d.name;
                    li.appendChild(nameDiv);

                    const qtyDiv = document.createElement('div');
                    qtyDiv.className = 'mm-eu-w-summary-qty';
                    qtyDiv.textContent = ((boot.i18n && boot.i18n.qtyPrefix) || 'Qty: ') + d.qty;
                    li.appendChild(qtyDiv);

                    const priceDiv = document.createElement('div');
                    priceDiv.className = 'mm-eu-w-summary-price';
                    priceDiv.textContent = formatPrice(line, currency);
                    li.appendChild(priceDiv);

                    itemsEl.appendChild(li);
                }

                // Full-return check + shipping refund + shipping tax.
                const eligibleIds = Object.keys(eligibleItems);
                let fullReturn = eligibleIds.length > 0 && selectedCount === eligibleIds.length;
                if (fullReturn) {
                    for (const id of eligibleIds) {
                        const entry = state.items.get(Number(id));
                        const need = Number(eligibleItems[id].qty ?? eligibleItems[id]);
                        if (!entry || entry.qty < need) {
                            fullReturn = false;
                            break;
                        }
                    }
                }
                taxTotal = roundHalfEven(taxTotal, 4);
                const shippingRefund = fullReturn ? roundHalfEven(shippingPaid + shippingTax, 4) : 0;
                const grand = roundHalfEven(subtotal + taxTotal + shippingRefund, 4);
                if (totalEl) totalEl.textContent = formatPrice(grand, currency);
            }
        };

        // Continue gate: any selected (qty>0) item carrying a seal question
        // must have its seal radio answered before review. Mirrors the Hyvä
        // canContinue() model and the disabled state in withdrawal-summary.js.
        const sealGateBlocks = () => {
            for (const [itemId, data] of state.items.entries()) {
                if (!data || data.qty <= 0) continue;
                const sealRow = document.querySelector(
                    '[data-role="seal-row"][data-item-id="' + String(itemId) + '"]',
                );
                if (!sealRow) continue;
                const answered = sealRow.querySelector('input[data-role="seal-input"]:checked');
                if (!answered) return true;
            }
            return false;
        };

        // Seal-photo gate (Pro): mirrors withdrawal-summary.js. Block review when
        // a selected item's visible seal-photo control has neither a photo nor an
        // explicit skip. Absent data-photo-satisfied = no requirement.
        const photoGateBlocks = () => {
            for (const [itemId, data] of state.items.entries()) {
                if (!data || data.qty <= 0) continue;
                const hook = document.querySelector(
                    '[data-role="seal-extra"][data-item-id="' + String(itemId) + '"]',
                );
                if (!hook || hook.hidden) continue;
                if (hook.dataset.photoSatisfied === '0') return true;
            }
            return false;
        };

        // ---- Event wiring ----
        const step2Form = panels['2'].querySelector('[data-role="step2-form"]');
        if (step2Form) {
            step2Form.addEventListener('submit', (evt) => {
                evt.preventDefault();
                if (state.items.size === 0 || sealGateBlocks()) {
                    return;
                }
                // Seal-photo gate: Continue stays enabled. If an intact item still
                // has neither a photo nor an explicit skip, ask the Pro control to
                // highlight itself and stay on step 2 instead of advancing.
                if (photoGateBlocks()) {
                    document.dispatchEvent(new CustomEvent('mm-eu-w:photo-enforce'));
                    return;
                }
                renderReview();
                showPanel('3');
            });
        }

        const backLink = panels['3'].querySelector('[data-role="back-to-items"]');
        if (backLink) {
            backLink.addEventListener('click', (evt) => {
                evt.preventDefault();
                showPanel('2');
            });
        }

        const submitBtn = panels['3'].querySelector('[data-role="submit-request"]');
        const submitErr = panels['3'].querySelector('[data-role="submit-error"]');
        if (submitBtn) {
            submitBtn.addEventListener('click', async () => {
                submitBtn.disabled = true;
                submitErr.textContent = '';
                // Collect per-item reasons. Only include items with a non-empty
                // selection — backend further validates oid is in the items map.
                const itemReasons = {};
                for (const [id] of state.items.entries()) {
                    const r = state.itemReasons.get(id);
                    if (!r) continue;
                    const code = r.code || '';
                    const text = (r.text || '').trim();
                    if (code === '' && text === '') continue;
                    itemReasons[String(id)] = {
                        code: code,
                        text: code === 'other' ? text : '',
                    };
                }
                // Per-item seal-broken declaration — sent so the server enforces
                // the Art. 16(e)/(i) exclusion itself, not just via client qty-zeroing.
                const itemSeal = {};
                document.querySelectorAll('input[data-role="seal-input"][value="1"]').forEach((r) => {
                    if (!r.checked) return;
                    const row = r.closest('[data-role="seal-row"]');
                    if (row && row.dataset.itemId) itemSeal[String(row.dataset.itemId)] = 1;
                });
                const body = {
                    orderId: boot.orderIncrementId || '',
                    items: Object.fromEntries(
                        Array.from(state.items.entries()).map(([id, d]) => [String(id), d.qty]),
                    ),
                    itemReasons,
                    itemSeal,
                    formKey: boot.formKey || '',
                };
                // Propagate the magic-link token AND the verified-order id so
                // the server-side CustomerIdentityFactory can resolve the
                // guest's bound order and pull the authoritative email/name
                // from it. `?t=` is the Pro path; `?order_id=` is the Free
                // session-verified fallback (set by Lookup on a successful
                // email+order_id match) — without it, the JSON-POST URL has no
                // query string and the factory cannot identify the guest.
                const pageParams = new URLSearchParams(window.location.search);
                const pageToken = pageParams.get('t');
                const pageOrderId = pageParams.get('order_id');
                const extra = [];
                if (pageToken) extra.push('t=' + encodeURIComponent(pageToken));
                if (pageOrderId) extra.push('order_id=' + encodeURIComponent(pageOrderId));
                let finalizeUrl = boot.finalizeUrl;
                if (extra.length) {
                    finalizeUrl += (finalizeUrl.indexOf('?') === -1 ? '?' : '&') + extra.join('&');
                }
                const beforeSubmit = new CustomEvent('mm-eu-w:before-submit', { cancelable: true, detail: { body } });
                if (!document.dispatchEvent(beforeSubmit)) {
                    if (beforeSubmit.detail.cancelMessage) {
                        submitErr.textContent = beforeSubmit.detail.cancelMessage;
                    }
                    submitBtn.disabled = false;
                    return;
                }
                try {
                    const resp = await fetch(finalizeUrl, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                            'X-Magento-Form-Key': boot.formKey || '',
                        },
                        body: JSON.stringify(body),
                    });
                    const data = await resp.json().catch(() => ({ ok: false, error: (boot.i18n && boot.i18n.invalidServerReply) || 'Invalid server response.' }));
                    if (resp.ok && data.ok) {
                        renderSubmitted(data);
                        showPanel('4');
                    } else {
                        submitErr.textContent = data.error || (boot.i18n && boot.i18n.submissionFailed) || 'Submission failed. Please try again.';
                        submitBtn.disabled = false;
                    }
                } catch (e) {
                    submitErr.textContent = (boot.i18n && boot.i18n.networkError) || 'Network error. Please try again.';
                    submitBtn.disabled = false;
                }
            });
        }

        // ---- Async Cancel-request on step 2 strips ----
        // Uses a button + data-attrs instead of a nested <form> (HTML5 bans
        // form-in-form nesting, and step 2 already wraps the table in a form).
        // POSTs the cancel request with form_key + request_id via fetch, then
        // reloads so the page reflects the new pending/returned counts.
        document.addEventListener('click', async (evt) => {
            const btn = evt.target.closest('[data-role="cancel-request"]');
            if (!btn) return;
            evt.preventDefault();
            let url = btn.dataset.cancelUrl;
            const requestId = btn.dataset.requestId;
            if (!url || !requestId) return;
            // Propagate the magic-link token AND the verified-order id to the
            // cancel endpoint so the server-side CustomerIdentity factory can
            // resolve boundOrderEntityId and pass the ownership check
            // (Cancel::canSeeRequest). `?t=` is the Pro path; `?order_id=` is
            // the base module session-verified fallback (Lookup-set) — without it the
            // factory cannot identify the guest on form-encoded POSTs either.
            const pageParams = new URLSearchParams(window.location.search);
            const pageToken = pageParams.get('t');
            const pageOrderId = pageParams.get('order_id');
            const extra = [];
            if (pageToken) extra.push('t=' + encodeURIComponent(pageToken));
            if (pageOrderId) extra.push('order_id=' + encodeURIComponent(pageOrderId));
            if (extra.length) {
                url += (url.indexOf('?') === -1 ? '?' : '&') + extra.join('&');
            }
            btn.disabled = true;
            try {
                const body = new FormData();
                body.append('form_key', boot.formKey || '');
                body.append('request_id', requestId);
                await fetch(url, {
                    method: 'POST',
                    body,
                    credentials: 'same-origin',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    redirect: 'follow',
                });
            } catch {
                /* swallow — reload still refreshes state or surfaces server error */
            }
            window.location.reload();
        });

        // ---- Copy button (panel 4) ----
        // navigator.clipboard is only exposed on secure contexts (HTTPS or
        // localhost). On HTTP dev hosts fall back to execCommand('copy') via
        // a hidden, temporarily-focused textarea. Both paths surface the
        // "Copied!" inline feedback for 2s.
        const copyBtn = panels['4'].querySelector('[data-role="copy-rr"]');
        if (copyBtn) {
            const codeEl = panels['4'].querySelector('[data-role="rr-number"]');
            const fb = panels['4'].querySelector('[data-role="copy-feedback"]');
            const writeClipboard = async (text) => {
                if (navigator.clipboard) {
                    try {
                        await navigator.clipboard.writeText(text);
                        return true;
                    } catch {
                        // fall through to legacy path
                    }
                }
                const ta = document.createElement('textarea');
                ta.value = text;
                ta.setAttribute('readonly', '');
                ta.style.position = 'fixed';
                ta.style.opacity = '0';
                document.body.appendChild(ta);
                ta.select();
                let ok = false;
                try {
                    ok = document.execCommand('copy');
                } catch {
                    ok = false;
                }
                document.body.removeChild(ta);
                return ok;
            };
            copyBtn.addEventListener('click', async () => {
                const v = codeEl.dataset.copy || codeEl.textContent.trim();
                const ok = await writeClipboard(v);
                if (ok && fb) {
                    fb.classList.add('is-visible');
                    setTimeout(() => fb.classList.remove('is-visible'), 2000);
                }
            });
        }
    });
})();
