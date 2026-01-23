(function () {
    const STORAGE_KEY = 'nfa_settings_v1';

    const qs = (sel) => document.querySelector(sel);

    function notify(message, type = 'info') {
        if (typeof window.showToast === 'function') {
            window.showToast(message, type);
            return;
        }
        alert(message);
    }

    function loadSettings() {
        try {
            const raw = localStorage.getItem(STORAGE_KEY);
            const obj = raw ? JSON.parse(raw) : null;
            return {
                autoRefresh: obj?.autoRefresh ?? true,
                compact: obj?.compact ?? false,
                reduceMotion: obj?.reduceMotion ?? false,
                toasts: obj?.toasts ?? true
            };
        } catch (_) {
            return { autoRefresh: true, compact: false, reduceMotion: false, toasts: true };
        }
    }

    function saveSettings(s) {
        localStorage.setItem(STORAGE_KEY, JSON.stringify(s));
    }

    function applySettings(s) {
        document.body.classList.toggle('pref-compact', !!s.compact);
        document.body.classList.toggle('pref-reduce-motion', !!s.reduceMotion);
        window.__nfaSettings = s;
    }

    const setAutoRefresh = qs('#setAutoRefresh');
    const setCompact = qs('#setCompact');
    const setReduceMotion = qs('#setReduceMotion');
    const setToasts = qs('#setToasts');

    const btnSave = qs('#btnSaveSettings');
    const btnReset = qs('#btnResetSettings');

    function syncUI(s) {
        if (setAutoRefresh) setAutoRefresh.checked = !!s.autoRefresh;
        if (setCompact) setCompact.checked = !!s.compact;
        if (setReduceMotion) setReduceMotion.checked = !!s.reduceMotion;
        if (setToasts) setToasts.checked = !!s.toasts;
    }

    function readUI() {
        return {
            autoRefresh: !!setAutoRefresh?.checked,
            compact: !!setCompact?.checked,
            reduceMotion: !!setReduceMotion?.checked,
            toasts: !!setToasts?.checked
        };
    }

    const initial = loadSettings();
    syncUI(initial);
    applySettings(initial);

    btnSave?.addEventListener('click', () => {
        const s = readUI();
        saveSettings(s);
        applySettings(s);
        notify('Settings saved.', 'success');
    });

    btnReset?.addEventListener('click', () => {
        const s = { autoRefresh: true, compact: false, reduceMotion: false, toasts: true };
        saveSettings(s);
        syncUI(s);
        applySettings(s);
        notify('Settings reset to default.', 'success');
    });
})();
