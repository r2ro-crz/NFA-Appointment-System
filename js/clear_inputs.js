// Clears form inputs on refresh / page navigation (including back-forward cache restores).
// Opt out per element by adding: data-keep-input="true"

(function () {
    const shouldRun = () => {
        const body = document.body;
        if (!body) return false;
        return body.getAttribute('data-clear-inputs') === 'true';
    };

    const clearElement = (el) => {
        if (!el || el.getAttribute('data-keep-input') === 'true') return;

        const tag = (el.tagName || '').toUpperCase();
        if (tag === 'SELECT') {
            el.selectedIndex = 0;
            return;
        }

        if (tag === 'TEXTAREA') {
            el.value = '';
            return;
        }

        if (tag !== 'INPUT') return;

        const type = (el.type || '').toLowerCase();
        if (type === 'hidden' || type === 'submit' || type === 'button' || type === 'reset' || type === 'file') {
            return;
        }
        if (type === 'checkbox' || type === 'radio') {
            el.checked = false;
            return;
        }

        el.value = '';
    };

    const clearAllInputs = () => {
        if (!shouldRun()) return;
        document.querySelectorAll('input, select, textarea').forEach(clearElement);
    };

    // Clear on normal load
    window.addEventListener('DOMContentLoaded', () => {
        clearAllInputs();
        // Some browsers apply autofill slightly later
        window.requestAnimationFrame(clearAllInputs);
        setTimeout(clearAllInputs, 0);
    });

    // Clear when page is restored from bfcache (back/forward navigation)
    window.addEventListener('pageshow', (e) => {
        if (!shouldRun()) return;
        if (e.persisted) {
            clearAllInputs();
            return;
        }

        // Navigation Timing API (best-effort)
        try {
            const navEntries = performance.getEntriesByType && performance.getEntriesByType('navigation');
            const nav = navEntries && navEntries[0];
            if (nav && (nav.type === 'back_forward' || nav.type === 'reload')) {
                clearAllInputs();
            }
        } catch {
            // ignore
        }
    });
})();
