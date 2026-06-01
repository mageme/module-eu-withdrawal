(() => {
    'use strict';

    const onReady = (cb) => {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', cb);
        } else {
            cb();
        }
    };

    onReady(() => {
        const btn = document.querySelector('.mm-eu-w-copy-btn');
        if (!btn) return;
        if (!navigator.clipboard) {
            btn.style.display = 'none';
            return;
        }
        const pill = btn.closest('.mm-eu-w-rr-pill');
        const codeEl = pill?.querySelector('[data-role="rr-number"]');
        const feedback = pill?.querySelector('[data-role="copy-feedback"]');
        if (!codeEl || !feedback) return;

        btn.addEventListener('click', async () => {
            const value = codeEl.dataset.copy || codeEl.textContent.trim();
            try {
                await navigator.clipboard.writeText(value);
                feedback.classList.add('is-visible');
                setTimeout(() => feedback.classList.remove('is-visible'), 2000);
            } catch {
                // fail silently — clipboard permissions or tab not focused
            }
        });
    });
})();
