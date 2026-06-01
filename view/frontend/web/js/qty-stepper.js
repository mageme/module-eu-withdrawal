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
            const min = Number(stepper.dataset.min || 0);
            const max = Number(stepper.dataset.max || 0);

            stepper.querySelectorAll('.mm-eu-w-qty-btn').forEach((btn) => {
                btn.addEventListener('click', () => {
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
