// Cross-page/tab refresh helper for appointment updates.
// Usage:
//   window.publishAppointmentsRefresh({ reason: 'confirmed' });
//   window.subscribeAppointmentsRefresh(() => location.reload());

(function () {
    const CHANNEL = 'nfa_appointments_refresh';
    const STORAGE_KEY = 'nfa_appointments_refresh';
    const LAST_SEEN_KEY = 'nfa_appointments_refresh_last_seen_ts';
    const LAST_RELOAD_KEY = 'nfa_appointments_refresh_last_reload_ts';
    const MIN_RELOAD_INTERVAL_MS = 2500;
    const tabId = (() => {
        const existing = sessionStorage.getItem('nfa_tab_id');
        if (existing) return existing;
        const id = `tab_${Date.now()}_${Math.random().toString(16).slice(2)}`;
        sessionStorage.setItem('nfa_tab_id', id);
        return id;
    })();

    let reloadQueued = false;
    const queueReload = (cb) => {
        if (reloadQueued) return;
        reloadQueued = true;
        setTimeout(() => {
            try { cb && cb(); } finally { reloadQueued = false; }
        }, 200);
    };

    const safeParse = (value) => {
        if (!value) return null;
        try { return JSON.parse(value); } catch { return null; }
    };

    let bc = null;
    try {
        if (typeof BroadcastChannel !== 'undefined') {
            bc = new BroadcastChannel(CHANNEL);
        }
    } catch {
        bc = null;
    }

    const getLastSeenTs = () => {
        const raw = sessionStorage.getItem(LAST_SEEN_KEY);
        const n = raw ? parseInt(raw, 10) : 0;
        return Number.isFinite(n) ? n : 0;
    };

    const setLastSeenTs = (ts) => {
        try {
            sessionStorage.setItem(LAST_SEEN_KEY, String(ts || 0));
        } catch {
            // ignore
        }
    };

    const getLastReloadTs = () => {
        const raw = sessionStorage.getItem(LAST_RELOAD_KEY);
        const n = raw ? parseInt(raw, 10) : 0;
        return Number.isFinite(n) ? n : 0;
    };

    const setLastReloadTs = (ts) => {
        try {
            sessionStorage.setItem(LAST_RELOAD_KEY, String(ts || 0));
        } catch {
            // ignore
        }
    };

    const notify = (message, handler) => {
        if (!message || message.tabId === tabId) return;
        if (message.type !== 'appointment_changed') return;

        const msgTs = typeof message.ts === 'number' ? message.ts : parseInt(message.ts || '0', 10) || 0;
        if (msgTs && msgTs <= getLastSeenTs()) {
            return;
        }

        const cb = typeof handler === 'function' ? handler : () => location.reload();

        // Mark as seen immediately to prevent reload loops after a refresh
        if (msgTs) setLastSeenTs(msgTs);

        // Cooldown: avoid repeated reloads that prevent user actions
        const now = Date.now();
        const lastReload = getLastReloadTs();
        const withinCooldown = lastReload && (now - lastReload) < MIN_RELOAD_INTERVAL_MS;

        // Don’t interrupt if user is typing in an input
        const active = document.activeElement;
        const activeTag = active && active.tagName ? active.tagName.toUpperCase() : '';
        const isTyping = activeTag === 'INPUT' || activeTag === 'TEXTAREA' || activeTag === 'SELECT';
        if (isTyping || withinCooldown) {
            // Best effort: reload after focus leaves, with retries.
            const startedAt = Date.now();
            const tryLater = () => {
                const stillTyping = document.activeElement === active;
                const stillWithinCooldown = getLastReloadTs() && (Date.now() - getLastReloadTs()) < MIN_RELOAD_INTERVAL_MS;
                if (!stillTyping && !stillWithinCooldown) {
                    setLastReloadTs(Date.now());
                    queueReload(cb);
                    return;
                }
                if (Date.now() - startedAt > 15000) {
                    setLastReloadTs(Date.now());
                    queueReload(cb);
                    return;
                }
                setTimeout(tryLater, 600);
            };
            setTimeout(tryLater, 600);
            return;
        }

        setLastReloadTs(Date.now());
        queueReload(cb);
    };

    const publishAppointmentsRefresh = (payload) => {
        const msg = {
            type: 'appointment_changed',
            ts: Date.now(),
            tabId,
            reason: (payload && payload.reason) ? String(payload.reason) : 'updated'
        };

        try {
            if (bc) bc.postMessage(msg);
        } catch {
            // ignore
        }

        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify(msg));
        } catch {
            // ignore
        }
    };

    const subscribeAppointmentsRefresh = (handler) => {
        const cb = typeof handler === 'function' ? handler : () => location.reload();

        const wrapped = (msg) => notify(msg, cb);

        const onStorage = (e) => {
            if (!e || e.key !== STORAGE_KEY) return;
            wrapped(safeParse(e.newValue));
        };

        const onMessage = (e) => wrapped(e && e.data);

        window.addEventListener('storage', onStorage);
        if (bc) bc.addEventListener('message', onMessage);

        return () => {
            window.removeEventListener('storage', onStorage);
            if (bc) bc.removeEventListener('message', onMessage);
        };
    };

    // Default behavior: reload on appointment updates.
    // Some pages (e.g., reports) should NOT auto-reload because it can cause heavy redraw loops.
    const autoReloadDisabled = !!(window && window.NFA_DISABLE_AUTO_APPT_REFRESH_RELOAD);
    if (!autoReloadDisabled) {
        subscribeAppointmentsRefresh(() => location.reload());
    }

    window.publishAppointmentsRefresh = publishAppointmentsRefresh;
    window.subscribeAppointmentsRefresh = subscribeAppointmentsRefresh;

    // If we received a message before handler attached (storage), best-effort parse latest
    try {
        const last = safeParse(localStorage.getItem(STORAGE_KEY));
        notify(last);
    } catch {
        // ignore
    }
})();
