(() => {
    'use strict';

    const onReady = (cb) => {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', cb);
        } else {
            cb();
        }
    };

    const clamp = (value, min, max) => Math.max(min, Math.min(max, value));

    // A sealed-hygiene / sealed-AV item whose seal the customer declared broken
    // is legally excluded (Art. 16(e)/(i)); its qty is pinned to 0 and must not
    // be raised again via the stepper. Mirrors the Hyvä canEditQty() guard.
    const sealBroken = (itemId) => {
        const sealRow = document.querySelector(
            '[data-role="seal-row"][data-item-id="' + String(itemId) + '"]',
        );
        if (!sealRow) return false;
        const broken = sealRow.querySelector('input[data-role="seal-input"][value="1"]');
        return !!(broken && broken.checked);
    };

    const dispatch = (input, qty) => {
        const stepper = input.closest('.mm-eu-w-qty-stepper');
        const row = input.closest('.mm-eu-w-item-row');
        const itemId = Number(stepper?.dataset.itemId || 0);
        const price = Number(row?.dataset.price || 0);
        document.dispatchEvent(new CustomEvent('mm-eu-w:qty-changed', {
            detail: { itemId, qty, price, input },
        }));
    };

    onReady(() => {
        document.querySelectorAll('.mm-eu-w-qty-stepper').forEach((stepper) => {
            const input = stepper.querySelector('.mm-eu-w-qty-input');
            if (!input) return;
            const itemId = Number(stepper.dataset.itemId || 0);
            const min = Number(stepper.dataset.min || 0);
            const max = Number(stepper.dataset.max || 0);

            stepper.querySelectorAll('.mm-eu-w-qty-btn').forEach((btn) => {
                btn.addEventListener('click', () => {
                    if (sealBroken(itemId)) {
                        if (input.value !== '0') {
                            input.value = '0';
                            dispatch(input, 0);
                        }
                        return;
                    }
                    const current = Number(input.value) || 0;
                    const delta = btn.dataset.action === 'inc' ? 1 : -1;
                    const next = clamp(current + delta, min, max);
                    if (next !== current) {
                        input.value = String(next);
                        dispatch(input, next);
                    }
                });
            });
            input.addEventListener('change', () => {
                if (sealBroken(itemId)) {
                    input.value = '0';
                    dispatch(input, 0);
                    return;
                }
                const raw = Number(input.value) || 0;
                const next = clamp(raw, min, max);
                if (next !== raw) {
                    input.value = String(next);
                }
                dispatch(input, next);
            });
        });
    });
})();
