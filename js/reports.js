(function () {
    const $ = (sel) => document.querySelector(sel);

    // Reports page is intentionally "simple mode" for performance.
    const SIMPLE_MODE = true;

    const notify = (message, tone = 'danger') => {
        const msg = (message || 'Something went wrong.').toString();
        if (typeof showToast === 'function') {
            showToast(msg, tone);
            return;
        }
        try { alert(msg); } catch { /* ignore */ }
    };

    const fmt = (n, { decimals = 0 } = {}) => {
        const num = Number(n);
        if (!Number.isFinite(num)) return '0';
        return num.toLocaleString(undefined, { minimumFractionDigits: decimals, maximumFractionDigits: decimals });
    };

    const clamp = (n, min, max) => Math.max(min, Math.min(max, n));

    const animateNumber = (el, to, { from, durationMs = (SIMPLE_MODE ? 0 : 650), decimals = 0 } = {}) => {
        if (!el) return;
        const start = Number.isFinite(from) ? from : (parseFloat(String(el.textContent).replace(/,/g, '')) || 0);
        const end = Number(to);
        if (!Number.isFinite(end)) return;

        if (!durationMs || durationMs <= 0) {
            el.textContent = fmt(end, { decimals });
            return;
        }

        const startTs = performance.now();
        const tick = (now) => {
            const t = clamp((now - startTs) / durationMs, 0, 1);
            const eased = 1 - Math.pow(1 - t, 3);
            const val = start + (end - start) * eased;
            el.textContent = fmt(val, { decimals });
            if (t < 1) requestAnimationFrame(tick);
        };
        requestAnimationFrame(tick);
    };

    const withLoading = async (message, disabledEls, fn) => {
        const loader = window.NFALoading;
        if (loader && typeof loader.withLoading === 'function') {
            return loader.withLoading(fn, { message, disable: disabledEls });
        }
        return fn();
    };

    const getFilters = () => {
        const start = ($('#filterStart')?.value || '').trim();
        const end = ($('#filterEnd')?.value || '').trim();
        const slot = ($('#filterSlot')?.value || '').trim();
        const farmerTypeId = parseInt($('#filterType')?.value || '0', 10) || 0;
        const statuses = Array.from(document.querySelectorAll('#statusChips input[type="checkbox"]'))
            .filter(x => x.checked)
            .map(x => x.value);

        return { start, end, slot, farmerTypeId, statuses };
    };

    const validateFilters = (f) => {
        const okDate = (d) => /^\d{4}-\d{2}-\d{2}$/.test(d);
        if (!okDate(f.start) || !okDate(f.end)) return 'Please select a valid date range.';
        if (f.start > f.end) return 'Date From must not be after Date To.';
        if (f.statuses.length === 0) return 'Select at least one status.';
        return '';
    };

    const buildQuery = (f, extra = {}) => {
        const q = new URLSearchParams();
        q.set('start_date', f.start);
        q.set('end_date', f.end);
        if (f.slot) q.set('time_slot', f.slot);
        if (f.farmerTypeId) q.set('farmer_type_id', String(f.farmerTypeId));
        q.set('statuses', f.statuses.join(','));
        Object.entries(extra).forEach(([k, v]) => {
            if (v === undefined || v === null) return;
            q.set(k, String(v));
        });
        return q;
    };

    const state = {
        // Charts removed on Reports for performance.
        table: {
            page: 1,
            pageSize: 25,
            total: 0,
            lastFilters: null
        }
    };

    const escapeHtml = (s) => String(s ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');

    const setBarWidth = (container, ratio) => {
        const fill = container?.querySelector('span');
        if (!fill) return;
        const pct = clamp(Math.round((Number(ratio) || 0) * 100), 0, 100);
        fill.style.width = `${pct}%`;
    };

    const updateUpdatedText = () => {
        const el = $('#reportsUpdatedText');
        if (!el) return;
        const now = new Date();
        el.textContent = `Updated ${now.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}`;
    };

    const renderKpis = (summary, statusRows) => {
        const total = Number(summary.total_appointments || 0);
        const totalVol = Number(summary.total_volume || 0);
        const completed = Number(summary.completed_count || 0);

        const pendingRow = (statusRows || []).find(r => String(r.status).toLowerCase() === 'pending');
        const pending = pendingRow ? Number(pendingRow.count || 0) : 0;

        animateNumber($('#kpiTotal'), total, { from: 0, decimals: 0 });
        animateNumber($('#kpiVolume'), totalVol, { from: 0, decimals: 0 });
        animateNumber($('#kpiCompleted'), completed, { from: 0, decimals: 0 });
        animateNumber($('#kpiPending'), pending, { from: 0, decimals: 0 });

        const completionRate = total > 0 ? (completed / total) * 100 : 0;
        const avgVol = Number(summary.avg_volume || 0);
        $('#miniCompletion').textContent = `${fmt(completionRate, { decimals: 1 })}%`;
        $('#miniAvgVol').textContent = fmt(avgVol, { decimals: 0 });
    };

    const palette = {
        status: {
            pending: '#f39c12',
            confirmed: '#3498db',
            rescheduled: '#9b59b6',
            completed: '#2ecc71',
            cancelled: '#e74c3c'
        },
        slot: {
            AM: '#3498db',
            PM: '#9b59b6'
        }
    };

    const renderSimple = (data) => {
        const dailyEl = $('#vizDaily');
        const statusEl = $('#vizStatus');
        const slotEl = $('#vizSlot');
        const typeEl = $('#vizType');
        const topEl = $('#vizTop');

        const setEmpty = (el, msg) => {
            if (!el) return;
            el.innerHTML = `<div class="simple-empty">${escapeHtml(msg)}</div>`;
        };

        // Daily
        const daily = Array.isArray(data.daily) ? data.daily : [];
        if (!dailyEl) {
            // ignore
        } else if (daily.length === 0) {
            setEmpty(dailyEl, 'No daily data for this range.');
        } else {
            const counts = daily.map(r => Number(r.count || 0)).filter(Number.isFinite);
            const max = Math.max(1, ...counts);
            dailyEl.innerHTML = daily.slice(0, 60).map((r) => {
                const day = escapeHtml(r.day);
                const count = Number(r.count || 0);
                const vol = Number(r.volume || 0);
                const ratio = count / max;
                return `
                    <div class="simple-row">
                        <div>
                            <div class="simple-label">${day}</div>
                            <div class="simple-sub">${fmt(vol)} bags</div>
                        </div>
                        <div class="bar"><span style="width:${clamp(Math.round(ratio * 100), 0, 100)}%"></span></div>
                        <div class="simple-value">${fmt(count)} appt</div>
                    </div>
                `;
            }).join('');
        }

        // Status
        const status = Array.isArray(data.status) ? data.status : [];
        if (!statusEl) {
            // ignore
        } else if (status.length === 0) {
            setEmpty(statusEl, 'No status data.');
        } else {
            const total = status.reduce((sum, r) => sum + (Number(r.count || 0) || 0), 0) || 1;
            const max = Math.max(1, ...status.map(r => Number(r.count || 0)).filter(Number.isFinite));
            const colorClass = (s) => {
                const k = String(s || '').toLowerCase();
                if (k === 'completed') return 'green';
                if (k === 'confirmed') return 'blue';
                if (k === 'rescheduled') return 'purple';
                if (k === 'pending') return 'orange';
                if (k === 'cancelled') return 'red';
                return '';
            };
            statusEl.innerHTML = status
                .slice()
                .sort((a, b) => (Number(b.count || 0) || 0) - (Number(a.count || 0) || 0))
                .map((r) => {
                    const s = String(r.status || 'unknown');
                    const count = Number(r.count || 0) || 0;
                    const pct = (count / total) * 100;
                    const ratio = count / max;
                    const cls = colorClass(s);
                    return `
                        <div class="simple-row">
                            <div>
                                <div class="simple-label">${escapeHtml(s.charAt(0).toUpperCase() + s.slice(1))}</div>
                                <div class="simple-sub">${fmt(pct, { decimals: 1 })}%</div>
                            </div>
                            <div class="bar ${cls}"><span style="width:${clamp(Math.round(ratio * 100), 0, 100)}%"></span></div>
                            <div class="simple-value">${fmt(count)}</div>
                        </div>
                    `;
                }).join('');
        }

        // Slot
        const slots = Array.isArray(data.slots) ? data.slots : [];
        if (!slotEl) {
            // ignore
        } else if (slots.length === 0) {
            setEmpty(slotEl, 'No time slot data.');
        } else {
            const max = Math.max(1, ...slots.map(r => Number(r.count || 0)).filter(Number.isFinite));
            slotEl.innerHTML = slots
                .slice()
                .sort((a, b) => String(a.time_slot).localeCompare(String(b.time_slot)))
                .map((r) => {
                    const slot = String(r.time_slot || '').toUpperCase();
                    const count = Number(r.count || 0) || 0;
                    const ratio = count / max;
                    const cls = slot === 'PM' ? 'purple' : 'blue';
                    return `
                        <div class="simple-row">
                            <div class="simple-label">${escapeHtml(slot || '—')}</div>
                            <div class="bar ${cls}"><span style="width:${clamp(Math.round(ratio * 100), 0, 100)}%"></span></div>
                            <div class="simple-value">${fmt(count)}</div>
                        </div>
                    `;
                }).join('');
        }

        // Farmer type
        const types = Array.isArray(data.farmer_types) ? data.farmer_types : [];
        if (!typeEl) {
            // ignore
        } else if (types.length === 0) {
            setEmpty(typeEl, 'No farmer type data.');
        } else {
            const max = Math.max(1, ...types.map(r => Number(r.count || 0)).filter(Number.isFinite));
            typeEl.innerHTML = types
                .slice()
                .sort((a, b) => (Number(b.count || 0) || 0) - (Number(a.count || 0) || 0))
                .map((r) => {
                    const name = String(r.type_name || 'Unknown');
                    const count = Number(r.count || 0) || 0;
                    const vol = Number(r.volume || 0) || 0;
                    const ratio = count / max;
                    return `
                        <div class="simple-row">
                            <div>
                                <div class="simple-label">${escapeHtml(name)}</div>
                                <div class="simple-sub">${fmt(vol)} bags</div>
                            </div>
                            <div class="bar purple"><span style="width:${clamp(Math.round(ratio * 100), 0, 100)}%"></span></div>
                            <div class="simple-value">${fmt(count)}</div>
                        </div>
                    `;
                }).join('');
        }

        // Top farmers
        const top = Array.isArray(data.top_farmers) ? data.top_farmers : [];
        if (!topEl) {
            // ignore
        } else if (top.length === 0) {
            setEmpty(topEl, 'No top farmers for this range.');
        } else {
            const max = Math.max(1, ...top.map(r => Number(r.volume || 0)).filter(Number.isFinite));
            topEl.innerHTML = top
                .slice(0, 10)
                .map((r) => {
                    const name = String(r.farmer_name || 'Unknown');
                    const vol = Number(r.volume || 0) || 0;
                    const appts = Number(r.appointments || 0) || 0;
                    const ratio = vol / max;
                    return `
                        <div class="simple-row">
                            <div>
                                <div class="simple-label">${escapeHtml(name)}</div>
                                <div class="simple-sub">${fmt(appts)} appt</div>
                            </div>
                            <div class="bar green"><span style="width:${clamp(Math.round(ratio * 100), 0, 100)}%"></span></div>
                            <div class="simple-value">${fmt(vol)} bags</div>
                        </div>
                    `;
                }).join('');
        }
    };

    const renderTable = (rows) => {
        const body = $('#reportTableBody');
        if (!body) return;

        if (!rows || rows.length === 0) {
            body.innerHTML = '<tr><td colspan="7" class="empty">No results for these filters.</td></tr>';
            return;
        }

        body.innerHTML = rows.map(r => {
            const farmer = `${r.first_name || ''} ${r.last_name || ''}`.trim();
            const vol = fmt(r.volume || 0, { decimals: 0 });
            const status = String(r.status || '').toLowerCase();
            const statusLabel = status ? (status.charAt(0).toUpperCase() + status.slice(1)) : '';
            return `
                <tr>
                    <td>${(r.reference_number || '').toString()}</td>
                    <td>${(r.date || '').toString()}</td>
                    <td>${(r.time_slot || '').toString()}</td>
                    <td>${farmer}</td>
                    <td>${(r.type_name || '—').toString()}</td>
                    <td class="num">${vol}</td>
                    <td>${statusLabel}</td>
                </tr>
            `;
        }).join('');
    };

    const setPager = () => {
        const info = $('#pagerInfo');
        const prev = $('#btnPrev');
        const next = $('#btnNext');

        const totalPages = Math.max(1, Math.ceil(state.table.total / state.table.pageSize));
        const page = clamp(state.table.page, 1, totalPages);
        state.table.page = page;

        if (info) info.textContent = `Page ${page} of ${totalPages} • ${fmt(state.table.total)} rows`;
        if (prev) prev.disabled = page <= 1;
        if (next) next.disabled = page >= totalPages;
    };

    const fetchOverview = async (filters) => {
        const q = buildQuery(filters);
        const res = await fetch(`php_helper/api.php?action=getReportsOverview&${q.toString()}`);
        const json = await res.json();
        if (!json || !json.success) throw new Error((json && json.error) ? json.error : 'Failed to load report.');
        return json.data;
    };

    const fetchTable = async (filters) => {
        const q = buildQuery(filters, { page: state.table.page, page_size: state.table.pageSize });
        const res = await fetch(`php_helper/api.php?action=getReportsAppointments&${q.toString()}`);
        const json = await res.json();
        if (!json || !json.success) throw new Error((json && json.error) ? json.error : 'Failed to load rows.');
        return json.data;
    };

    const runReport = async ({ resetPage = true } = {}) => {
        const filters = getFilters();
        const err = validateFilters(filters);
        if (err) {
            notify(err, 'warning');
            return;
        }

        state.table.lastFilters = filters;
        if (resetPage) state.table.page = 1;

        const btns = [$('#btnRunReport'), $('#btnApplyFilters'), $('#btnPrintReport')].filter(Boolean);
        try {
            await withLoading('Generating report…', btns, async () => {
                const overview = await fetchOverview(filters);
                renderKpis(overview.summary || {}, overview.status || []);

                try {
                    renderSimple(overview);
                } catch (e) {
                    notify('Visual summaries failed to render. KPIs and table still updated.', 'warning');
                }

                const table = await fetchTable(filters);
                state.table.total = Number(table.total || 0);
                renderTable(table.rows || []);
                setPager();

                try { updateUpdatedText(); } catch { /* ignore */ }

                // No cross-tab broadcast from reports; keep this page lightweight.
            });
        } catch (e) {
            const msg = (e && e.message) ? e.message : 'Failed to generate report.';
            notify(msg, 'danger');
            const updated = $('#reportsUpdatedText');
            if (updated) updated.textContent = 'Error';
        }
    };

    const printReport = async () => {
        const filters = state.table.lastFilters || getFilters();
        const err = validateFilters(filters);
        if (err) {
            notify(err, 'warning');
            return;
        }

        const ok = (typeof confirmDialog === 'function')
            ? await confirmDialog({
                title: 'Print Report',
                message: 'Open a print-ready report using the current filters?',
                confirmText: 'Open Print View',
                cancelText: 'Cancel',
                tone: 'primary'
            })
            : window.confirm('Open print-ready report using the current filters?');

        if (!ok) return;

        const q = buildQuery(filters);
        const url = `reports_print.php?${q.toString()}`;
            const w = window.open(url, '_blank');
        if (!w) notify('Pop-up blocked. Please allow pop-ups to open the print view.', 'warning');
    };

    const resetFilters = () => {
        const preset = window.reportsPreset || {};
        $('#filterStart').value = preset.start || $('#filterStart').value;
        $('#filterEnd').value = preset.end || $('#filterEnd').value;
        $('#filterSlot').value = '';
        $('#filterType').value = '0';

        const chips = document.querySelectorAll('#statusChips input[type="checkbox"]');
        chips.forEach(c => {
            const v = c.value;
            c.checked = (v === 'cancelled') ? false : true;
        });

        state.table.page = 1;
        state.table.pageSize = parseInt($('#tablePageSize')?.value || '25', 10) || 25;
    };

    const setQuickRange = (days) => {
        const end = new Date();
        const start = new Date();
        start.setDate(start.getDate() - (days - 1));
        const toYmd = (d) => d.toISOString().slice(0, 10);
        $('#filterStart').value = toYmd(start);
        $('#filterEnd').value = toYmd(end);
    };

    const init = () => {
        // Table page size
        const pageSizeSel = $('#tablePageSize');
        pageSizeSel && pageSizeSel.addEventListener('change', () => {
            state.table.pageSize = parseInt(pageSizeSel.value || '25', 10) || 25;
            state.table.page = 1;
            if (state.table.lastFilters) runReport({ resetPage: false });
        });

        $('#btnPrev')?.addEventListener('click', () => {
            state.table.page = Math.max(1, state.table.page - 1);
            if (state.table.lastFilters) runReport({ resetPage: false });
        });
        $('#btnNext')?.addEventListener('click', () => {
            state.table.page += 1;
            if (state.table.lastFilters) runReport({ resetPage: false });
        });

        const runBtns = ['btnRunReport', 'btnApplyFilters'].map(id => document.getElementById(id)).filter(Boolean);
        runBtns.forEach(btn => btn.addEventListener('click', () => runReport()));

        $('#btnPrintReport')?.addEventListener('click', printReport);
        $('#btnResetFilters')?.addEventListener('click', () => {
            resetFilters();
            if (typeof showToast === 'function') showToast('Filters reset.', 'info');
            runReport().catch(() => { /* handled in runReport */ });
        });

        $('#btnQuickMonth')?.addEventListener('click', () => {
            const now = new Date();
            const start = new Date(now.getFullYear(), now.getMonth(), 1);
            const end = new Date(now.getFullYear(), now.getMonth() + 1, 0);
            const toYmd = (d) => d.toISOString().slice(0, 10);
            $('#filterStart').value = toYmd(start);
            $('#filterEnd').value = toYmd(end);

            state.table.page = 1;
            runReport().catch(() => { /* handled in runReport */ });
        });

        $('#btnQuickWeek')?.addEventListener('click', () => {
            setQuickRange(7);
            state.table.page = 1;
            runReport().catch(() => { /* handled in runReport */ });
        });

        // First paint: run once automatically
        runReport().catch((e) => {
            const msg = (e && e.message) ? e.message : 'Failed to generate report.';
            notify(msg, 'danger');
        });
    };

    document.addEventListener('DOMContentLoaded', init);
})();
