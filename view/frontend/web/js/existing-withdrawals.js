(function () {
    'use strict';

    document.addEventListener('click', function (event) {
        const revealBtn = event.target.closest('[data-role="reveal-cancel"]');
        if (revealBtn) {
            const container = revealBtn.closest('[data-role="cancel-container"]');
            const confirmForm = container && container.querySelector('[data-role="cancel-confirm"]');
            if (confirmForm) {
                confirmForm.hidden = false;
                revealBtn.hidden = true;
            }
            return;
        }

        const abortBtn = event.target.closest('[data-role="abort-cancel"]');
        if (abortBtn) {
            const container = abortBtn.closest('[data-role="cancel-container"]');
            const confirmForm = container && container.querySelector('[data-role="cancel-confirm"]');
            const revealBtn2 = container && container.querySelector('[data-role="reveal-cancel"]');
            if (confirmForm) {
                confirmForm.hidden = true;
            }
            if (revealBtn2) {
                revealBtn2.hidden = false;
            }
        }
    });
})();
