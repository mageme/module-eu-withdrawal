(() => {
    'use strict';

    const onReady = (cb) => {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', cb);
        } else {
            cb();
        }
    };

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

    /**
     * Banker's rounding (half-to-even). Mirrors PHP's
     * round($value, $dp, PHP_ROUND_HALF_EVEN) used by RefundCalculator so the
     * UI total exactly matches the value RefundCalculator writes to
     * mm_eu_withdrawal_request. The float-arithmetic path is fine for money
     * amounts up to ~1e9 at 4 decimal places (well under 2^53).
     */
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

    onReady(() => {
        const sidebar = document.querySelector('.mm-eu-w-sidebar');
        if (!sidebar) return;
        const currency = sidebar.dataset.currency || 'EUR';
        const state = new Map();

        const itemsContainer = sidebar.querySelector('[data-role="items-to-return"]');
        const itemsCount = sidebar.querySelector('[data-role="items-count"]');
        const itemsEmpty = sidebar.querySelector('[data-role="items-empty"]');
        const itemsTotalEl = sidebar.querySelector('[data-role="items-total"]');
        const shippingPaidEl = sidebar.querySelector('[data-role="shipping-paid"]');
        const taxEl = sidebar.querySelector('[data-role="tax"]');
        const totalRefundEl = sidebar.querySelector('[data-role="total-refund"]');
        const continueBtn = document.querySelector('[data-role="continue"]');
        const selectionSummary = sidebar.querySelector('[data-role="selection-summary"]');
        const bootstrapScript = document.querySelector('.mm-eu-w-spa script[data-role="bootstrap"]');
        let boot = {};
        try {
            boot = bootstrapScript ? JSON.parse(bootstrapScript.textContent || '{}') : {};
        } catch {
            boot = {};
        }
        const shippingPaid = Number(boot.shippingPaid || 0);
        const shippingTax = Number(boot.shippingTax || 0);
        // eligibleItems[id] = { qty: maxRemaining, taxAmount, qtyOrdered }
        // (legacy `unitTax` schema kept as fallback for cached pages)
        const eligibleItems = boot.eligibleItems || {};

        const render = () => {
            let itemsTotal = 0;
            let visibleCount = 0;
            if (itemsContainer) itemsContainer.innerHTML = '';
            for (const [, data] of state.entries()) {
                if (data.qty <= 0) continue;
                itemsTotal += data.qty * data.price;
                visibleCount += 1;
                if (itemsContainer) {
                    const li = document.createElement('li');
                    li.className = 'mm-eu-w-items-to-return-item';

                    if (data.thumb) {
                        const img = document.createElement('img');
                        img.src = data.thumb;
                        img.alt = data.name || '';
                        li.appendChild(img);
                    } else {
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
                        p3.setAttribute('x1', '12');
                        p3.setAttribute('y1', '13');
                        p3.setAttribute('x2', '12');
                        p3.setAttribute('y2', '22');
                        svg.appendChild(p1);
                        svg.appendChild(p2);
                        svg.appendChild(p3);
                        li.appendChild(svg);
                    }

                    const body = document.createElement('div');
                    body.className = 'mm-eu-w-items-to-return-body';
                    const nameEl = document.createElement('div');
                    nameEl.className = 'mm-eu-w-items-to-return-name';
                    nameEl.textContent = data.name || '';
                    const qtyEl = document.createElement('div');
                    qtyEl.className = 'mm-eu-w-items-to-return-qty';
                    qtyEl.textContent = `Qty: ${data.qty}`;
                    body.appendChild(nameEl);
                    body.appendChild(qtyEl);
                    li.appendChild(body);

                    const priceEl = document.createElement('div');
                    priceEl.className = 'mm-eu-w-items-to-return-price';
                    priceEl.textContent = formatPrice(data.qty * data.price, currency);
                    li.appendChild(priceEl);

                    itemsContainer.appendChild(li);
                }
            }
            if (itemsCount) itemsCount.textContent = visibleCount + (visibleCount === 1 ? ' item' : ' items');
            if (itemsEmpty) itemsEmpty.style.display = visibleCount === 0 ? '' : 'none';
            if (selectionSummary) {
                if (visibleCount > 0) {
                    selectionSummary.removeAttribute('hidden');
                } else {
                    selectionSummary.setAttribute('hidden', '');
                }
            }

            // Full-return detection: every eligible item id has its full
            // remaining qty selected. When true, shipping refund = shipping
            // paid (EU Art. 13(2): full withdrawal refunds all sums paid).
            const eligibleIds = Object.keys(eligibleItems);
            let fullReturn = eligibleIds.length > 0 && visibleCount === eligibleIds.length;
            if (fullReturn) {
                for (const id of eligibleIds) {
                    const entry = state.get(Number(id));
                    const need = Number(eligibleItems[id].qty ?? eligibleItems[id]);
                    if (!entry || entry.qty < need) {
                        fullReturn = false;
                        break;
                    }
                }
            }

            // Tax: replicate RefundCalculator::calculate() exactly.
            // For each selected item: lineTax = round(qty * tax / ordered, 4).
            // Sum, re-round, then add shippingTax when full-return triggers
            // shipping refund. Falls back to the legacy unitTax schema if the
            // bootstrap was emitted by a pre-0.12.2 release.
            let tax = 0;
            for (const [id, data] of state.entries()) {
                if (data.qty <= 0) continue;
                const meta = eligibleItems[String(id)];
                if (!meta) continue;
                if (typeof meta.taxAmount !== 'undefined' && typeof meta.qtyOrdered !== 'undefined') {
                    const ordered = Number(meta.qtyOrdered) || 1;
                    tax += roundHalfEven(data.qty * Number(meta.taxAmount || 0) / ordered, 4);
                } else {
                    tax += data.qty * Number(meta.unitTax || 0);
                }
            }
            tax = roundHalfEven(tax, 4);
            // EU Art. 14(2): full withdrawal refunds shipping AND its VAT.
            // Bake shippingTax into the displayed shipping refund (matches
            // RefundCalculator since 0.12.2: shipping_refund column stores
            // shipping_amount + shipping_tax_amount). Tax row stays as the
            // pure sum of per-item line tax.
            const shippingRefundEx = fullReturn ? roundHalfEven(shippingPaid + shippingTax, 4) : 0;
            const totalRefund = roundHalfEven(itemsTotal + tax + shippingRefundEx, 4);

            if (itemsTotalEl) itemsTotalEl.textContent = formatPrice(itemsTotal, currency);
            if (shippingPaidEl) shippingPaidEl.textContent = formatPrice(shippingRefundEx, currency);
            if (taxEl) taxEl.textContent = formatPrice(tax, currency);
            if (totalRefundEl) totalRefundEl.textContent = formatPrice(totalRefund, currency);
            if (continueBtn) continueBtn.disabled = visibleCount === 0;

            document.querySelectorAll('.mm-eu-w-item-row').forEach((row) => {
                const id = Number(row.dataset.itemId);
                const data = state.get(id);
                const lineCell = row.querySelector('[data-role="line-total"]');
                if (!lineCell) return;
                const line = data ? data.qty * data.price : 0;
                lineCell.textContent = formatPrice(line, currency);
            });
        };

        document.addEventListener('mm-eu-w:qty-changed', (evt) => {
            const { itemId, qty } = evt.detail;
            const row = document.querySelector(`.mm-eu-w-item-row[data-item-id="${itemId}"]`);
            if (!row) return;
            const price = Number(row.dataset.price || 0);
            const name = row.dataset.name || '';
            const thumb = row.dataset.thumb || '';
            state.set(itemId, { qty, price, name, thumb });
            const reasonRow = document.querySelector(
                `[data-role="reason-row"][data-item-id="${itemId}"]`,
            );
            if (reasonRow) {
                if (qty > 0) {
                    reasonRow.removeAttribute('hidden');
                } else {
                    reasonRow.setAttribute('hidden', '');
                    const sel = reasonRow.querySelector('[data-role="reason-select"]');
                    const txt = reasonRow.querySelector('[data-role="reason-other"]');
                    if (sel) sel.value = '';
                    if (txt) {
                        txt.value = '';
                        txt.setAttribute('hidden', '');
                    }
                }
            }
            // Show the seal-broken question for sealed-hygiene/AV items
            // when qty>0. When qty drops to 0 we keep the row visible if the
            // customer just declared the seal broken (warning + 0 qty is the
            // legitimate end state for that flow). Only fully reset when the
            // customer actively returns to "intact" — the seal-input change
            // handler below covers that path.
            const sealRow = document.querySelector(
                `[data-role="seal-row"][data-item-id="${itemId}"]`,
            );
            if (sealRow) {
                const brokenChecked = sealRow.querySelector('input[value="1"]')?.checked === true;
                if (qty > 0 || brokenChecked) {
                    sealRow.removeAttribute('hidden');
                } else {
                    sealRow.setAttribute('hidden', '');
                    const warning = sealRow.querySelector('[data-role="seal-warning"]');
                    if (warning) warning.setAttribute('hidden', '');
                }
            }
            render();
        });

        // Seal radio: when "Yes broken" is selected, force the qty back to 0
        // (the item is excluded under Art. 16(e)/(i) once unsealed) and show
        // an inline warning so the customer knows why their qty was reset.
        // Also toggles the `is-selected` class on each choice card so the
        // CSS can highlight the chosen option (no native CSS :has() needed).
        const refreshSealHighlight = (sealRow) => {
            sealRow.querySelectorAll('.mm-eu-w-item-seal-choice').forEach((label) => {
                const input = label.querySelector('input[data-role="seal-input"]');
                if (input && input.checked) label.classList.add('is-selected');
                else label.classList.remove('is-selected');
            });
        };
        // Initialise highlight on every seal-row at load time
        document.querySelectorAll('[data-role="seal-row"]').forEach(refreshSealHighlight);

        document.addEventListener('change', (evt) => {
            const radio = evt.target;
            if (!radio || !radio.matches || !radio.matches('input[data-role="seal-input"]')) return;
            const sealRow = radio.closest('[data-role="seal-row"]');
            if (!sealRow) return;
            refreshSealHighlight(sealRow);
            const itemId = sealRow.dataset.itemId;
            const warning = sealRow.querySelector('[data-role="seal-warning"]');
            const qtyInput = document.querySelector(
                `.mm-eu-w-qty-input[name="items[${itemId}]"]`,
            );
            if (radio.value === '1' && radio.checked) {
                // Stash the customer's current qty before zeroing — clicking
                // "intact" again restores it so a misclick isn't punishing.
                if (qtyInput && Number(qtyInput.value) > 0) {
                    qtyInput.dataset.preSealQty = qtyInput.value;
                }
                if (warning) warning.removeAttribute('hidden');
                if (qtyInput) {
                    qtyInput.value = 0;
                    qtyInput.dispatchEvent(new Event('change', { bubbles: true }));
                }
            } else if (radio.value === '0' && radio.checked) {
                if (warning) warning.setAttribute('hidden', '');
                if (qtyInput && Number(qtyInput.value) === 0 && qtyInput.dataset.preSealQty) {
                    qtyInput.value = qtyInput.dataset.preSealQty;
                    delete qtyInput.dataset.preSealQty;
                    qtyInput.dispatchEvent(new Event('change', { bubbles: true }));
                }
            }
        });

        // Per-item reason: toggle the free-text textarea when "Other" is picked.
        document.addEventListener('change', (evt) => {
            const sel = evt.target;
            if (!sel || !sel.matches || !sel.matches('[data-role="reason-select"]')) return;
            const textarea = sel.parentElement
                ? sel.parentElement.querySelector('[data-role="reason-other"]')
                : null;
            if (!textarea) return;
            if (sel.value === 'other') {
                textarea.removeAttribute('hidden');
            } else {
                textarea.setAttribute('hidden', '');
                textarea.value = '';
            }
        });

        render();
    });
})();
