(function () {
    const $ = (sel) => document.querySelector(sel);

    const fmt = (n) => {
        const num = Number(n);
        if (!Number.isFinite(num)) return '0';
        return num.toLocaleString(undefined, { maximumFractionDigits: 0 });
    };

    const clamp = (n, min, max) => Math.max(min, Math.min(max, n));

    const animateNumber = (el, to, { from, durationMs = 650, decimals = 0 } = {}) => {
        if (!el) return;
        const start = Number.isFinite(from) ? from : (parseFloat(String(el.textContent).replace(/,/g, '')) || 0);
        const end = Number(to);
        if (!Number.isFinite(end)) return;

        const startTs = performance.now();
        const tick = (now) => {
            const t = clamp((now - startTs) / durationMs, 0, 1);
            const eased = 1 - Math.pow(1 - t, 3);
            const val = start + (end - start) * eased;
            el.textContent = val.toLocaleString(undefined, {
                minimumFractionDigits: decimals,
                maximumFractionDigits: decimals
            });
            if (t < 1) requestAnimationFrame(tick);
        };
        requestAnimationFrame(tick);
    };

    const getTone = (percent) => {
        const p = Number(percent);
        if (!Number.isFinite(p)) return 'good';
        if (p >= 90) return 'critical';
        if (p >= 75) return 'warning';
        return 'good';
    };

    const updateToneUI = (tone) => {
        const badge = $('#capacityHealthBadge');
        const ring = $('#ringProgress');
        const fill = $('#meterFill');

        if (badge) {
            badge.classList.toggle('warning', tone === 'warning');
            badge.classList.toggle('critical', tone === 'critical');
            const label = badge.querySelector('.label');
            if (label) {
                label.textContent = tone === 'critical' ? 'Critical' : (tone === 'warning' ? 'Warning' : 'Healthy');
            }
        }

        const css = getComputedStyle(document.documentElement);
        const good = css.getPropertyValue('--cap-good').trim() || '#1abc9c';
        const warn = css.getPropertyValue('--cap-warn').trim() || '#f39c12';
        const bad = css.getPropertyValue('--cap-bad').trim() || '#e74c3c';
        const color = tone === 'critical' ? bad : (tone === 'warning' ? warn : good);

        if (ring) ring.style.stroke = color;
        if (fill) {
            fill.style.background = `linear-gradient(90deg, ${color}, rgba(15,23,42,0.08))`;
        }
    };

    const setUpdatedText = (ts) => {
        const el = $('#capacityUpdatedText');
        if (!el) return;
        const when = ts ? new Date(ts) : new Date();
        el.textContent = `Updated ${when.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}`;
    };

    const render = (data, { animate = true } = {}) => {
        const capacity = Number(data.warehouse_capacity || 0);
        const inventory = Number(data.inventory || 0);
        const available = Math.max(0, capacity - inventory);
        const percent = capacity > 0 ? (inventory / capacity) * 100 : 0;

        if (animate) {
            animateNumber($('#kpiCapacity'), capacity, { from: 0, durationMs: 700, decimals: 0 });
            animateNumber($('#kpiInventory'), inventory, { from: 0, durationMs: 700, decimals: 0 });
            animateNumber($('#kpiAvailable'), available, { from: 0, durationMs: 700, decimals: 0 });
            animateNumber($('#kpiPercent'), percent, { from: 0, durationMs: 700, decimals: 1 });
            animateNumber($('#ringPercent'), percent, { from: 0, durationMs: 700, decimals: 0 });
            animateNumber($('#meterInventory'), inventory, { from: 0, durationMs: 700, decimals: 0 });
            animateNumber($('#meterCapacity'), capacity, { from: 0, durationMs: 700, decimals: 0 });
        } else {
            const set = (id, val) => { const el = $(id); if (el) el.textContent = val; };
            set('#kpiCapacity', fmt(capacity));
            set('#kpiInventory', fmt(inventory));
            set('#kpiAvailable', fmt(available));
            set('#kpiPercent', percent.toFixed(1));
            set('#ringPercent', String(Math.round(percent)));
            set('#meterInventory', fmt(inventory));
            set('#meterCapacity', fmt(capacity));
        }

        const leftEl = $('#meterLeft');
        if (leftEl) leftEl.textContent = `${fmt(available)} bags available`;

        const meterFill = $('#meterFill');
        const width = clamp(percent, 0, 100);
        if (meterFill) meterFill.style.width = `${width}%`;

        const ringProgress = $('#ringProgress');
        if (ringProgress) {
            const r = 52;
            const circumference = 2 * Math.PI * r;
            const dashOffset = circumference * (1 - clamp(percent, 0, 100) / 100);
            ringProgress.style.strokeDasharray = String(circumference);
            ringProgress.style.strokeDashoffset = String(dashOffset);
        }

        updateToneUI(getTone(percent));
        setUpdatedText(Date.now());
    };

    const state = {
        lastData: null,
        pollTimer: null,
        modalOpen: false
    };

    const loadFromStore = () => {
        const store = $('#capacity-data-store');
        if (!store) return null;
        return {
            branch_name: store.dataset.branchName || '',
            region_name: store.dataset.regionName || '',
            warehouse_capacity: parseFloat(store.dataset.capacity || '0') || 0,
            inventory: parseFloat(store.dataset.inventory || '0') || 0,
            available: parseFloat(store.dataset.available || '0') || 0,
            percent: parseFloat(store.dataset.percent || '0') || 0
        };
    };

    const fetchCapacity = async () => {
        const res = await fetch('php_helper/api.php?action=getCapacityManagementData', { method: 'GET' });
        const data = await res.json();
        if (!data || !data.success) {
            throw new Error((data && data.error) ? data.error : 'Failed to load capacity data.');
        }
        return data.data;
    };

    const withLoading = async (message, disabledEls, fn) => {
        const loader = window.NFALoading;
        if (loader && typeof loader.withLoading === 'function') {
            return loader.withLoading(fn, { message, disable: disabledEls });
        }
        return fn();
    };

    const openModal = () => {
        const modal = $('#capacityModal');
        if (!modal) return;

        state.modalOpen = true;

        const capEl = $('#capInputCapacity');
        const invEl = $('#capInputInventory');
        const data = state.lastData || loadFromStore() || { warehouse_capacity: 0, inventory: 0 };

        if (capEl) capEl.value = String(Math.round(Number(data.warehouse_capacity || 0)));
        if (invEl) invEl.value = String(Math.round(Number(data.inventory || 0)));

        updateModalComputed();
        modal.style.display = 'flex';
        document.body.classList.add('modal-open');

        setTimeout(() => {
            if (capEl && typeof capEl.focus === 'function') capEl.focus();
        }, 0);
    };

    const closeModal = () => {
        const modal = $('#capacityModal');
        if (!modal) return;
        modal.style.display = 'none';
        document.body.classList.remove('modal-open');
        state.modalOpen = false;
    };

    const updateModalComputed = () => {
        const capEl = $('#capInputCapacity');
        const invEl = $('#capInputInventory');
        const availEl = $('#capComputedAvailable');
        const pctEl = $('#capComputedPercent');
        const validation = $('#capValidation');

        const capacity = capEl ? parseFloat(capEl.value || '0') : 0;
        const inventory = invEl ? parseFloat(invEl.value || '0') : 0;
        const available = Math.max(0, capacity - inventory);
        const percent = capacity > 0 ? (inventory / capacity) * 100 : 0;

        if (availEl) availEl.textContent = `${fmt(available)} bags`;
        if (pctEl) pctEl.textContent = `${clamp(percent, 0, 999).toFixed(1)}%`;

        let msg = 'Ready to save.';
        let isError = false;

        if (!Number.isFinite(capacity) || !Number.isFinite(inventory)) {
            msg = 'Please enter numeric values.';
            isError = true;
        } else if (capacity < 0 || inventory < 0) {
            msg = 'Values cannot be negative.';
            isError = true;
        } else if (capacity > 0 && inventory > capacity) {
            msg = 'Inventory cannot exceed warehouse capacity.';
            isError = true;
        } else if (capacity === 0) {
            msg = 'Capacity is 0 — utilization will be 0%.';
        } else {
            const tone = getTone(percent);
            if (tone === 'critical') msg = 'Critical utilization. Consider scheduling adjustments.';
            if (tone === 'warning') msg = 'Warning utilization. Monitor incoming deliveries.';
        }

        if (validation) {
            validation.textContent = msg;
            validation.classList.toggle('error', isError);
        }

        const submit = $('#capSubmit');
        if (submit) submit.disabled = !!isError;

        return { capacity, inventory, isError };
    };

    const saveModal = async () => {
        const { capacity, inventory, isError } = updateModalComputed();
        if (isError) return;

        const ok = (typeof confirmDialog === 'function')
            ? await confirmDialog({
                title: 'Save Capacity Changes',
                message: `Set warehouse capacity to ${fmt(capacity)} and inventory to ${fmt(inventory)} bags?`,
                confirmText: 'Yes, Save',
                cancelText: 'Cancel',
                tone: 'primary'
            })
            : window.confirm('Save these capacity changes?');

        if (!ok) return;

        const btn = $('#capSubmit');
        await withLoading('Saving capacity…', [btn], async () => {
            const res = await fetch('php_helper/api.php?action=updateCapacityManagement', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    warehouse_capacity: capacity,
                    inventory: inventory
                })
            });

            const json = await res.json();
            if (!json || !json.success) {
                throw new Error((json && json.error) ? json.error : 'Failed to update capacity.');
            }

            state.lastData = { ...state.lastData, ...json.data };
            render(state.lastData, { animate: true });
            closeModal();

            try {
                if (typeof window.publishAppointmentsRefresh === 'function') {
                    window.publishAppointmentsRefresh({ reason: 'capacity' });
                }
            } catch {
                // ignore
            }

            if (typeof showToast === 'function') {
                showToast('Capacity updated successfully.', 'success');
            }
        });
    };

    const schedulePoll = () => {
        if (state.pollTimer) clearTimeout(state.pollTimer);
        state.pollTimer = setTimeout(async () => {
            try {
                const active = document.activeElement;
                const isTyping = active && ['INPUT', 'TEXTAREA', 'SELECT'].includes((active.tagName || '').toUpperCase());
                if (document.visibilityState !== 'visible' || state.modalOpen || isTyping) {
                    schedulePoll();
                    return;
                }

                const latest = await fetchCapacity();
                state.lastData = latest;
                render(latest, { animate: false });
            } catch {
                // ignore polling errors
            } finally {
                schedulePoll();
            }
        }, 60 * 1000);
    };

    const init = async () => {
        const storeData = loadFromStore();
        if (storeData) {
            state.lastData = storeData;
            render(storeData, { animate: true });
        }

        const refreshBtn = $('#btnRefreshCapacity');
        const openBtn = $('#btnOpenModal');
        const heroBtn = $('#btnEditCapacity');
        const modal = $('#capacityModal');

        refreshBtn && refreshBtn.addEventListener('click', async () => {
            await withLoading('Refreshing capacity…', [refreshBtn], async () => {
                const latest = await fetchCapacity();
                state.lastData = latest;
                render(latest, { animate: true });
            });
        });

        openBtn && openBtn.addEventListener('click', openModal);
        heroBtn && heroBtn.addEventListener('click', openModal);

        $('#capModalClose') && $('#capModalClose').addEventListener('click', closeModal);
        $('#capCancel') && $('#capCancel').addEventListener('click', closeModal);
        $('#capSubmit') && $('#capSubmit').addEventListener('click', saveModal);

        $('#capInputCapacity') && $('#capInputCapacity').addEventListener('input', updateModalComputed);
        $('#capInputInventory') && $('#capInputInventory').addEventListener('input', updateModalComputed);

        modal && modal.addEventListener('click', (e) => {
            if (e.target === modal) closeModal();
        });

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && state.modalOpen) {
                e.preventDefault();
                closeModal();
            }
        });

        try {
            const latest = await fetchCapacity();
            state.lastData = latest;
            render(latest, { animate: false });
        } catch {
            // keep initial store values
        }

        schedulePoll();
    };

    document.addEventListener('DOMContentLoaded', init);
})();
