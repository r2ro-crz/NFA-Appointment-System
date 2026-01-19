(function () {
    'use strict';

    const DEFAULT_MESSAGE = 'Processing your request…';
    let activeCount = 0;

    const ensureOverlay = () => {
        let overlay = document.getElementById('loadingOverlay') || document.getElementById('nfaLoadingOverlay');
        if (!overlay) {
            overlay = document.createElement('div');
            overlay.id = 'loadingOverlay';
            overlay.className = 'loading-overlay';
            overlay.innerHTML = `
                <div class="loading-content" role="status" aria-live="polite" aria-label="Loading">
                    <div class="spinner" aria-hidden="true"></div>
                    <p>${DEFAULT_MESSAGE}</p>
                </div>
            `;
            document.body.appendChild(overlay);
        }

        // Ensure content structure exists (some pages might have a simpler overlay).
        if (!overlay.querySelector('.loading-content')) {
            const existingText = overlay.querySelector('p')?.textContent || DEFAULT_MESSAGE;
            overlay.innerHTML = `
                <div class="loading-content" role="status" aria-live="polite" aria-label="Loading">
                    <div class="spinner" aria-hidden="true"></div>
                    <p>${existingText}</p>
                </div>
            `;
        }

        if (!overlay.getAttribute('role')) {
            overlay.setAttribute('role', 'presentation');
        }

        return overlay;
    };

    const setCursorWaiting = (waiting) => {
        try {
            document.documentElement.style.cursor = waiting ? 'wait' : '';
            document.body.style.cursor = waiting ? 'wait' : '';
        } catch (e) {
            // ignore
        }
    };

    const show = (message) => {
        const overlay = ensureOverlay();
        const msgEl = overlay.querySelector('.loading-content p');
        if (msgEl) msgEl.textContent = (message || DEFAULT_MESSAGE);

        activeCount += 1;
        overlay.classList.add('active');
        setCursorWaiting(true);
    };

    const hide = () => {
        activeCount = Math.max(0, activeCount - 1);
        const overlay = document.getElementById('loadingOverlay') || document.getElementById('nfaLoadingOverlay');
        if (!overlay) return;

        if (activeCount === 0) {
            overlay.classList.remove('active');
            setCursorWaiting(false);
        }
    };

    const withLoading = async (fnOrPromise, options) => {
        const message = options && options.message;
        const disable = options && options.disable;

        const disableEls = Array.isArray(disable) ? disable : (disable ? [disable] : []);
        const prevDisabled = disableEls.map((el) => ({ el, disabled: !!el?.disabled }));

        try {
            disableEls.forEach((el) => { if (el) el.disabled = true; });
            show(message);

            if (typeof fnOrPromise === 'function') {
                return await fnOrPromise();
            }
            return await fnOrPromise;
        } finally {
            hide();
            prevDisabled.forEach(({ el, disabled }) => { if (el) el.disabled = disabled; });
        }
    };

    // Auto-show overlay for normal form submits (non-AJAX) when opted-in.
    // Usage: <form data-loading="Processing…"> or data-loading="true"
    const wireForms = () => {
        document.querySelectorAll('form[data-loading]').forEach((form) => {
            form.addEventListener('submit', () => {
                const value = form.getAttribute('data-loading');
                const msg = (!value || value === 'true') ? DEFAULT_MESSAGE : value;
                show(msg);
            });
        });
    };

    document.addEventListener('DOMContentLoaded', wireForms);

    window.NFALoading = {
        show,
        hide,
        withLoading
    };
})();
