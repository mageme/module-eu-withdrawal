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
        const adjustmentEl = sidebar.querySelector('[data-role="adjustment"]');
        const adjustmentRow = sidebar.querySelector('[data-role="adjustment-row"]');
        const totalRefundEl = sidebar.querySelector('[data-role="total-refund"]');
        const continueBtn = document.querySelector('[data-role="continue"]');
        const selectionSummary = sidebar.querySelector('[data-role="selection-summary"]');
        // Query document-wide: the native "Move JS code to the bottom of the
        // page" setting (dev/js/move_script_to_bottom) relocates this JSON
        // <script> out of .mm-eu-w-spa to just before </body>, so a scoped
        // lookup would miss it and every boot value would fall back to 0.
        const bootstrapScript = document.querySelector('script[data-role="bootstrap"]');
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
        // Order-level adjustment (payment discount, gift card, custom total):
        // gap is the order-wide amount not captured in item/shipping fields,
        // distributed by the returned share to mirror RefundCalculator. Zero for
        // standard orders.
        const orderItemsBase = Number(boot.orderItemsBase || 0);
        const orderLevelGap = Number(boot.orderLevelGap || 0);
        const lineTaxFor = (id, qty) => {
            const meta = eligibleItems[String(id)];
            if (!meta) return 0;
            if (typeof meta.taxAmount !== 'undefined' && typeof meta.qtyOrdered !== 'undefined') {
                const ordered = Number(meta.qtyOrdered) || 1;
                return roundHalfEven(qty * Number(meta.taxAmount || 0) / ordered, 4);
            }
            return qty * Number(meta.unitTax || 0);
        };
        // Prorate the row, exactly as RefundCalculator does. Multiplying the
        // already-rounded per-unit `data.price` drifts by up to qty * 0.00005.
        // The fallback serves pages cached before `netAmount` was emitted.
        const lineNetFor = (id, qty, data) => {
            const meta = eligibleItems[String(id)];
            if (meta && typeof meta.netAmount !== 'undefined' && typeof meta.qtyOrdered !== 'undefined') {
                const ordered = Number(meta.qtyOrdered) || 1;
                return roundHalfEven(qty * Number(meta.netAmount || 0) / ordered, 4);
            }
            return qty * Number(data ? data.price : 0);
        };
        // The gross line refund, rounding net and VAT separately the way
        // RefundCalculator does, so the figure matches the stored refund_amount
        // to the cent on non-divisible quantities.
        const lineDisplay = (id, data) => roundHalfEven(
            lineNetFor(id, data.qty, data) + lineTaxFor(id, data.qty),
            4,
        );

        // Continue gate: block while any selected (qty>0) item that carries a
        // seal question still has no seal radio answered. Mirrors the Hyvä
        // canContinue() model — the customer must explicitly state whether the
        // seal is intact before reviewing the request.
        const sealGateBlocks = () => {
            for (const [itemId, data] of state.entries()) {
                if (!data || data.qty <= 0) continue;
                // The line's own seal question (if the line product is itself sealed).
                const sealRow = document.querySelector(
                    `[data-role="seal-row"][data-item-id="${itemId}"]:not([data-bundle-parent-id])`,
                );
                if (sealRow) {
                    if (!sealRow.querySelector('input[data-role="seal-input"]:checked')) return true;
                    // Seal-broken must never reach review: it is legally excluded, so its
                    // qty should be 0 — this is the defence-in-depth backstop.
                    const broken = sealRow.querySelector('input[data-role="seal-input"][value="1"]');
                    if (broken && broken.checked) return true;
                }
                // Every sealed component of a selected bundle must be answered and intact.
                const childSeals = document.querySelectorAll(`[data-role="seal-row"][data-bundle-parent-id="${itemId}"]`);
                for (const cs of childSeals) {
                    if (!cs.querySelector('input[data-role="seal-input"]:checked')) return true;
                    const b = cs.querySelector('input[data-role="seal-input"][value="1"]');
                    if (b && b.checked) return true;
                }
            }
            return false;
        };

        // Lock/unlock a per-item qty stepper (Art. 16(e)/(i) broken-seal path):
        // once the seal is broken the qty is pinned to 0 and the stepper is
        // disabled so it cannot be re-raised. Mirrors the Hyvä canEditQty() gate.
        const setStepperLock = (itemId, locked) => {
            const stepper = document.querySelector(
                `.mm-eu-w-qty-stepper[data-item-id="${itemId}"]`,
            );
            if (!stepper) return;
            stepper.classList.toggle('mm-eu-w-qty-stepper--disabled', locked);
            stepper.querySelectorAll('.mm-eu-w-qty-btn').forEach((b) => { b.disabled = locked; });
            const inp = stepper.querySelector('.mm-eu-w-qty-input');
            if (inp) inp.disabled = locked;
        };

        const render = () => {
            let itemsTotal = 0;
            let visibleCount = 0;
            if (itemsContainer) itemsContainer.innerHTML = '';
            for (const [id, data] of state.entries()) {
                if (data.qty <= 0) continue;
                itemsTotal += lineNetFor(id, data.qty, data);
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
                    priceEl.textContent = formatPrice(lineDisplay(id, data), currency);
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

            // Per-item VAT (mirrors RefundCalculator::calculate()) plus shipping
            // VAT on a full return. Subtotal and Shipping are gross, so the VAT
            // line breaks the tax out of them and is not added again. The total
            // stays S + Ti + Sh + Tsh + Adj.
            let itemTax = 0;
            for (const [id, data] of state.entries()) {
                if (data.qty <= 0) continue;
                itemTax += lineTaxFor(id, data.qty);
            }
            itemTax = roundHalfEven(itemTax, 4);

            const shipNet = fullReturn ? roundHalfEven(shippingPaid, 4) : 0;
            const shipTax = fullReturn ? roundHalfEven(shippingTax, 4) : 0;
            const vatLine = roundHalfEven(itemTax + shipTax, 4);

            // `itemsTotal` stays net here: the order-level gap was computed
            // against a net item base, so the ratio must use the same basis.
            let orderLevelAdj = 0;
            if (Math.abs(orderLevelGap) > 0.005 && orderItemsBase > 0) {
                orderLevelAdj = roundHalfEven(orderLevelGap * (itemsTotal / orderItemsBase), 4);
            }

            const subtotalDisplay = roundHalfEven(itemsTotal + itemTax, 4);
            const shippingDisplay = roundHalfEven(shipNet + shipTax, 4);
            const totalRefund = roundHalfEven(subtotalDisplay + shippingDisplay + orderLevelAdj, 4);

            if (itemsTotalEl) itemsTotalEl.textContent = formatPrice(subtotalDisplay, currency);
            if (shippingPaidEl) shippingPaidEl.textContent = formatPrice(shippingDisplay, currency);
            if (taxEl) taxEl.textContent = formatPrice(vatLine, currency);
            const hideAdj = Math.abs(orderLevelAdj) < 0.005;
            if (adjustmentEl) {
                adjustmentEl.textContent = formatPrice(orderLevelAdj, currency);
                adjustmentEl.hidden = hideAdj;
            }
            if (adjustmentRow) adjustmentRow.hidden = hideAdj;
            if (totalRefundEl) totalRefundEl.textContent = formatPrice(totalRefund, currency);
            if (continueBtn) continueBtn.disabled = visibleCount === 0 || sealGateBlocks();

            document.querySelectorAll('.mm-eu-w-item-row').forEach((row) => {
                const id = Number(row.dataset.itemId);
                const data = state.get(id);
                const lineCell = row.querySelector('[data-role="line-total"]');
                if (!lineCell) return;
                lineCell.textContent = formatPrice(data ? lineDisplay(id, data) : 0, currency);
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
            // Reveal/hide the seal question for each sealed component of this bundle
            // line (whole-bundle contents). Keep a broken component's row visible.
            document.querySelectorAll(`[data-role="seal-row"][data-bundle-parent-id="${itemId}"]`).forEach((childSeal) => {
                const brokenChecked = childSeal.querySelector('input[value="1"]')?.checked === true;
                if (qty > 0 || brokenChecked) {
                    childSeal.removeAttribute('hidden');
                } else {
                    childSeal.setAttribute('hidden', '');
                    const w = childSeal.querySelector('[data-role="seal-warning"]');
                    if (w) w.setAttribute('hidden', '');
                }
            });
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
        // Any gate broken for a line = its own seal broken, or any component seal
        // (data-bundle-parent-id) of that line broken. Used so restoring qty on one
        // "intact" answer never re-adds a line another broken seal still excludes.
        const anyGateBrokenFor = (lineId) => {
            const own = document.querySelector(`[data-role="seal-row"][data-item-id="${lineId}"]:not([data-bundle-parent-id])`);
            if (own && own.querySelector('input[data-role="seal-input"][value="1"]')?.checked === true) return true;
            return [...document.querySelectorAll(`[data-role="seal-row"][data-bundle-parent-id="${lineId}"]`)]
                .some((r) => r.querySelector('input[data-role="seal-input"][value="1"]')?.checked === true);
        };
        // Initialise highlight on every seal-row at load time
        document.querySelectorAll('[data-role="seal-row"]').forEach(refreshSealHighlight);

        document.addEventListener('change', (evt) => {
            const radio = evt.target;
            if (!radio || !radio.matches || !radio.matches('input[data-role="seal-input"]')) return;
            const sealRow = radio.closest('[data-role="seal-row"]');
            if (!sealRow) return;
            refreshSealHighlight(sealRow);
            // The gated line is the parent for a component seal, else the item itself.
            const itemId = sealRow.dataset.bundleParentId || sealRow.dataset.itemId;
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
                    setStepperLock(itemId, true);
                } else {
                    // Full-order mode has no qty input (static qty span). Exclude
                    // the item from our own selection directly, so a broken seal
                    // still removes it even if withdrawal-app.js is not present.
                    document.dispatchEvent(new CustomEvent('mm-eu-w:qty-changed', { detail: { itemId: Number(itemId), qty: 0 } }));
                }
            } else if (radio.value === '0' && radio.checked) {
                if (warning) warning.setAttribute('hidden', '');
                // Another gate (the line's own seal or a sibling component seal) may
                // still be broken — keep the line excluded until every gate is intact.
                if (anyGateBrokenFor(itemId)) return;
                setStepperLock(itemId, false);
                if (qtyInput && Number(qtyInput.value) === 0 && qtyInput.dataset.preSealQty) {
                    qtyInput.value = qtyInput.dataset.preSealQty;
                    delete qtyInput.dataset.preSealQty;
                    qtyInput.dispatchEvent(new Event('change', { bubbles: true }));
                } else if (!qtyInput) {
                    const itemRow = document.querySelector(`.mm-eu-w-item-row[data-item-id="${itemId}"]`);
                    const remaining = itemRow ? Number(itemRow.dataset.remaining || 0) : 0;
                    if (remaining > 0) {
                        document.dispatchEvent(new CustomEvent('mm-eu-w:qty-changed', { detail: { itemId: Number(itemId), qty: remaining } }));
                    }
                }
            }
            // Answering the seal question may unblock (or re-block) the
            // Continue gate without changing any qty — re-render so the
            // button's disabled state reflects the new answer.
            render();
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

        // Full-order rows are pre-selected: data-remaining is set only on
        // eligible full-order lines. Seed the selection state from them on load
        // so the sidebar total and the Continue gate reflect the pre-filled
        // quantities. The listener above is already registered, so this stays
        // self-contained regardless of the other scripts' load order.
        document.querySelectorAll('.mm-eu-w-item-row[data-remaining]').forEach((row) => {
            const itemId = Number(row.dataset.itemId);
            const qty = Number(row.dataset.remaining);
            if (itemId && qty > 0 && !state.has(itemId)) {
                document.dispatchEvent(new CustomEvent('mm-eu-w:qty-changed', { detail: { itemId, qty } }));
            }
        });

        render();
    });
})();
