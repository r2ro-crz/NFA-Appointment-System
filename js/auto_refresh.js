// Server-backed auto-refresh for real-time-ish updates across all users.
// Polls php_helper/api.php?action=getChangeToken and reloads when token changes.
// Safe guards: only reload when page is visible + user is idle + not typing + no modal open.

(function () {
    const DEFAULTS = {
        scope: '', // 'admin' | 'processor'
        intervalMs: 15000,
        idleMs: 8000,
        minReloadGapMs: 5000,
    };

    const state = {
        timer: null,
        lastToken: null,
        pendingToken: null,
        lastInteractionTs: Date.now(),
        lastReloadTs: 0,
        started: false,
        options: { ...DEFAULTS },
    };

    const now = () => Date.now();

    const markInteraction = () => {
        state.lastInteractionTs = now();
    };

    const isTyping = () => {
        const active = document.activeElement;
        if (!active || !active.tagName) return false;
        const tag = String(active.tagName).toUpperCase();
        if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return true;
        if (active.isContentEditable) return true;
        return false;
    };

    const isModalOpen = () => {
        if (document.body && document.body.classList.contains('modal-open')) return true;

        // Common patterns used in this project
        const selectors = [
            '.modal.show',
            '.modal-backdrop.show',
            '#detailsModal.show',
            '#receiveModal.show',
            '#reschedModal.show',
            '#accountModalBackdrop[style*="display"]',
            '#confirmModalBackdrop[style*="display"]',
            '#reassignModalBackdrop[style*="display"]',
            '[role="dialog"][aria-hidden="false"]'
        ];

        for (const sel of selectors) {
            const el = document.querySelector(sel);
            if (!el) continue;

            // If it's a backdrop div that toggles display, ensure it's actually shown
            const style = window.getComputedStyle(el);
            if (style && style.display !== 'none' && style.visibility !== 'hidden' && style.opacity !== '0') {
                return true;
            }
        }

        return false;
    };

    const shouldReloadNow = () => {
        if (document.visibilityState && document.visibilityState !== 'visible') return false;
        if (now() - state.lastInteractionTs < state.options.idleMs) return false;
        if (isTyping()) return false;
        if (isModalOpen()) return false;
        if (state.lastReloadTs && (now() - state.lastReloadTs) < state.options.minReloadGapMs) return false;
        return true;
    };

    const isDisabled = () => {
        try {
            const url = new URL(window.location.href);
            if (url.searchParams.get('norefresh') === '1') return true;
        } catch {
            // ignore
        }

        try {
            const pref = localStorage.getItem('nfa_auto_refresh');
            if (pref && String(pref).toLowerCase() === 'off') return true;
        } catch {
            // ignore
        }

        return false;
    };

    const buildUrl = () => {
        const url = new URL('php_helper/api.php', window.location.href);
        url.searchParams.set('action', 'getChangeToken');
        url.searchParams.set('scope', state.options.scope);
        return url.toString();
    };

    const poll = async () => {
        if (!state.started) return;
        if (isDisabled()) return;

        const url = buildUrl();

        try {
            const res = await fetch(url, {
                method: 'GET',
                cache: 'no-store',
                headers: { 'Accept': 'application/json' }
            });

            if (!res.ok) return;
            const json = await res.json();
            if (!json || json.success !== true) return;

            const token = String(json.token || '');
            if (!token) return;

            if (state.lastToken === null) {
                state.lastToken = token;
                state.pendingToken = null;
                return;
            }

            if (token !== state.lastToken) {
                state.pendingToken = token;

                if (shouldReloadNow()) {
                    state.lastReloadTs = now();
                    state.lastToken = token;
                    state.pendingToken = null;
                    window.location.reload();
                }
            } else {
                state.pendingToken = null;
            }

            // If a token change is pending but user was busy, reload as soon as safe.
            if (state.pendingToken && shouldReloadNow()) {
                state.lastReloadTs = now();
                state.lastToken = state.pendingToken;
                state.pendingToken = null;
                window.location.reload();
            }
        } catch {
            // ignore transient errors
        }
    };

    const start = (opts) => {
        if (state.started) return;
        state.started = true;
        state.options = { ...DEFAULTS, ...(opts || {}) };

        if (!state.options.scope) {
            // Don’t run without a scope (helps avoid accidental public-page polling)
            state.started = false;
            return;
        }

        // Activity listeners
        ['pointerdown', 'keydown', 'touchstart', 'scroll'].forEach((ev) => {
            window.addEventListener(ev, markInteraction, { passive: true });
        });
        window.addEventListener('focusin', markInteraction);
        window.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'visible') {
                // Fast catch-up when returning to the tab
                poll();
            }
        });

        // Kick off
        poll();
        state.timer = window.setInterval(poll, Math.max(4000, state.options.intervalMs | 0));
    };

    const stop = () => {
        state.started = false;
        state.lastToken = null;
        state.pendingToken = null;
        if (state.timer) {
            window.clearInterval(state.timer);
            state.timer = null;
        }
    };

    window.NFAAutoRefresh = { start, stop };
})();
