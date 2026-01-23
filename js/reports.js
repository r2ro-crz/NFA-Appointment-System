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

    const NFAValueLabelsPlugin = {
        id: 'nfaValueLabels',
        afterDatasetsDraw: (chart, _args, opts) => {
            const type = String(opts?.type || '').toLowerCase();
            if (!type) return;

            const ctx = chart?.ctx;
            if (!ctx) return;

            const drawText = (text, x, y, {
                font = '700 10px Arial',
                fill = '#0f172a',
                bg = 'rgba(255,255,255,0.92)',
                padX = 4,
                padY = 2,
                bounds = null
            } = {}) => {
                if (!text) return;
                ctx.save();
                ctx.font = font;
                ctx.textAlign = 'center';
                ctx.textBaseline = 'middle';

                const metrics = ctx.measureText(text);
                const w = Math.ceil(metrics.width) + padX * 2;
                const h = 14 + padY * 2;
                let rx = x - w / 2;
                let ry = y - h / 2;

                // Keep the label inside the visible plot area to avoid being clipped.
                if (bounds && Number.isFinite(bounds.left) && Number.isFinite(bounds.top) && Number.isFinite(bounds.right) && Number.isFinite(bounds.bottom)) {
                    const minX = bounds.left + 2;
                    const maxX = bounds.right - w - 2;
                    const minY = bounds.top + 2;
                    const maxY = bounds.bottom - h - 2;
                    rx = clamp(rx, minX, maxX);
                    ry = clamp(ry, minY, maxY);
                    x = rx + w / 2;
                    y = ry + h / 2;
                }

                // background pill
                ctx.fillStyle = bg;
                const r = 4;
                ctx.beginPath();
                ctx.moveTo(rx + r, ry);
                ctx.lineTo(rx + w - r, ry);
                ctx.quadraticCurveTo(rx + w, ry, rx + w, ry + r);
                ctx.lineTo(rx + w, ry + h - r);
                ctx.quadraticCurveTo(rx + w, ry + h, rx + w - r, ry + h);
                ctx.lineTo(rx + r, ry + h);
                ctx.quadraticCurveTo(rx, ry + h, rx, ry + h - r);
                ctx.lineTo(rx, ry + r);
                ctx.quadraticCurveTo(rx, ry, rx + r, ry);
                ctx.closePath();
                ctx.fill();

                ctx.fillStyle = fill;
                ctx.fillText(text, x, y);
                ctx.restore();
            };

            if (type === 'line') {
                const datasetIndex = Number.isFinite(opts?.datasetIndex) ? opts.datasetIndex : 0;
                const dataset = chart.data?.datasets?.[datasetIndex];
                const data = Array.isArray(dataset?.data) ? dataset.data : [];
                const meta = chart.getDatasetMeta(datasetIndex);
                const elems = meta?.data || [];
                const n = Math.min(data.length, elems.length);

                const gran = String(opts?.granularity || '').toLowerCase();
                const maxLabels = Number.isFinite(opts?.maxLabels) ? opts.maxLabels : 24;

                const nonZeroIdx = [];
                for (let i = 0; i < n; i++) {
                    const v = Number(data[i] || 0);
                    if (Number.isFinite(v) && v > 0) nonZeroIdx.push(i);
                }

                if (nonZeroIdx.length === 0) return;

                const shouldLabelIndex = (i) => {
                    // Always label values > 0. If too many non-zero points, thin them out.
                    if (nonZeroIdx.length <= maxLabels) return true;

                    // If very active series, downsample labels.
                    // Keep first, last, and then every other non-zero point.
                    const pos = nonZeroIdx.indexOf(i);
                    if (pos < 0) return false;
                    const lastPos = nonZeroIdx.length - 1;
                    if (pos === 0 || pos === lastPos) return true;
                    return (pos % 2) === 0;
                };

                for (let i = 0; i < n; i++) {
                    const v = Number(data[i] || 0);
                    if (!Number.isFinite(v) || v <= 0) continue; // user request: label only > 0
                    if (!shouldLabelIndex(i)) continue;

                    const el = elems[i];
                    const x = el?.x;
                    const y = el?.y;
                    if (!Number.isFinite(x) || !Number.isFinite(y)) continue;

                    drawText((opts?.formatter ? opts.formatter(v) : String(v)), x, y - 14, {
                        font: opts?.font || '700 10px Arial',
                        bounds: chart.chartArea
                    });
                }
                return;
            }

            if (type === 'doughnut' || type === 'pie') {
                const datasetIndex = Number.isFinite(opts?.datasetIndex) ? opts.datasetIndex : 0;
                const dataset = chart.data?.datasets?.[datasetIndex];
                const data = Array.isArray(dataset?.data) ? dataset.data : [];
                const meta = chart.getDatasetMeta(datasetIndex);
                const arcs = meta?.data || [];
                const n = Math.min(data.length, arcs.length);
                const total = Number(opts?.total || data.reduce((a, b) => a + Number(b || 0), 0));

                for (let i = 0; i < n; i++) {
                    const v = Number(data[i] || 0);
                    if (!Number.isFinite(v) || v <= 0) continue;

                    const arc = arcs[i];
                    const sa = arc?.startAngle;
                    const ea = arc?.endAngle;
                    const ox = arc?.x;
                    const oy = arc?.y;
                    const or = arc?.outerRadius;
                    const ir = arc?.innerRadius;
                    if (![sa, ea, ox, oy, or].every(Number.isFinite)) continue;
                    const inner = Number.isFinite(ir) ? ir : (or * 0.55);
                    const r = inner + (or - inner) * 0.62; // inside the slice

                    const ang = (sa + ea) / 2;
                    const x = ox + Math.cos(ang) * r;
                    const y = oy + Math.sin(ang) * r;
                    const pct = total > 0 ? (v / total) * 100 : 0;
                    const text = (opts?.formatter)
                        ? opts.formatter(v, pct)
                        : `${fmt(v)} (${fmt(pct, { decimals: 1 })}%)`;

                    drawText(text, x, y, {
                        font: opts?.font || '700 10px Arial',
                        bounds: chart.chartArea
                    });
                }
            }
        }
    };

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
        mode: 'appointments',
        // Charts removed on Reports for performance.
        table: {
            page: 1,
            pageSize: 10,
            total: 0,
            lastFilters: null
        },
        warehouse: {
            data: null
        }
    };

    const debounce = (fn, waitMs = 250) => {
        let t;
        return (...args) => {
            if (t) clearTimeout(t);
            t = setTimeout(() => fn(...args), waitMs);
        };
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
        const totalCount = Number(summary.total_appointments || 0);
        const totalVol = Number(summary.total_volume || 0);

        const map = new Map(
            (statusRows || []).map(r => {
                const k = String(r.status || '').toLowerCase();
                return [k, { count: Number(r.count || 0) || 0, volume: Number(r.volume || 0) || 0 }];
            })
        );
        const get = (k) => map.get(k) || { count: 0, volume: 0 };

        const completed = get('completed');
        const confirmed = get('confirmed');
        const rescheduled = get('rescheduled');
        const pending = get('pending');
        const cancelled = get('cancelled');

        animateNumber($('#kpiTotalCount'), totalCount, { from: 0, decimals: 0 });
        animateNumber($('#kpiTotalBags'), totalVol, { from: 0, decimals: 0 });

        animateNumber($('#kpiCompletedCount'), completed.count, { from: 0, decimals: 0 });
        animateNumber($('#kpiCompletedBags'), completed.volume, { from: 0, decimals: 0 });

        animateNumber($('#kpiConfirmedCount'), confirmed.count, { from: 0, decimals: 0 });
        animateNumber($('#kpiConfirmedBags'), confirmed.volume, { from: 0, decimals: 0 });

        animateNumber($('#kpiRescheduledCount'), rescheduled.count, { from: 0, decimals: 0 });
        animateNumber($('#kpiRescheduledBags'), rescheduled.volume, { from: 0, decimals: 0 });

        animateNumber($('#kpiPendingCount'), pending.count, { from: 0, decimals: 0 });
        animateNumber($('#kpiPendingBags'), pending.volume, { from: 0, decimals: 0 });

        animateNumber($('#kpiCancelledCount'), cancelled.count, { from: 0, decimals: 0 });
        animateNumber($('#kpiCancelledBags'), cancelled.volume, { from: 0, decimals: 0 });

        const completionRate = totalCount > 0 ? (completed.count / totalCount) * 100 : 0;
        const avgVol = Number(summary.avg_volume || 0);
        $('#miniCompletion').textContent = `${fmt(completionRate, { decimals: 1 })}%`;
        $('#miniAvgVol').textContent = fmt(avgVol, { decimals: 0 });
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

    const fetchWarehouseReport = async (filters) => {
        const q = buildQuery(filters);
        const res = await fetch(`php_helper/api.php?action=getWarehouseReport&${q.toString()}`);
        const json = await res.json();
        if (!json || !json.success) throw new Error((json && json.error) ? json.error : 'Failed to load warehouse report.');
        return json.data;
    };

    let warehouseStatusChart = null;
    let warehouseTrendChart = null;

    const setModeButtonState = (mode) => {
        const a = $('#btnModeAppointments');
        const w = $('#btnModeWarehouse');
        if (a) a.classList.toggle('is-active', mode === 'appointments');
        if (w) w.classList.toggle('is-active', mode === 'warehouse');

        if (a && w) {
            a.classList.toggle('btn-inline-primary', mode === 'appointments');
            a.classList.toggle('btn-inline-secondary', mode !== 'appointments');
            w.classList.toggle('btn-inline-primary', mode === 'warehouse');
            w.classList.toggle('btn-inline-secondary', mode !== 'warehouse');
        }

        const aWrap = $('#appointmentReport');
        const wWrap = $('#warehouseReport');
        if (aWrap) aWrap.hidden = mode !== 'appointments';
        if (wWrap) wWrap.hidden = mode !== 'warehouse';

        const printBtn = $('#btnPrintReport');
        if (printBtn) {
            printBtn.disabled = false;
            printBtn.title = (mode === 'warehouse') ? 'Print warehouse charts report.' : 'Print appointment report.';
        }

        const mini = $('#appointmentMiniMetrics');
        if (mini) mini.hidden = mode !== 'appointments';

        const note = $('#warehouseOnlyNote');
        if (note) note.hidden = mode !== 'warehouse';

        const chips = document.querySelectorAll('#statusChips input[type="checkbox"]');
        chips.forEach(cb => {
            cb.disabled = mode === 'warehouse';
        });
    };

    const renderWarehouseCapacity = (cap) => {
        const total = Number(cap.warehouse_capacity || 0);
        const inventory = Number(cap.inventory || 0);
        const available = Number(cap.available || 0);
        const percent = Number(cap.percent || 0);

        const metrics = $('#warehouseCapacityMetrics');
        if (metrics) {
            metrics.innerHTML = `
                <span><strong>${fmt(inventory)}</strong> inventory</span>
                <span><strong>${fmt(available)}</strong> available</span>
                <span><strong>${fmt(total)}</strong> capacity</span>
                <span class="muted">${fmt(percent, { decimals: 1 })}% full</span>
            `;
        }

        const canvas = $('#warehouseStatusChart');
        if (!canvas || typeof Chart === 'undefined') return;
        const ctx = canvas.getContext('2d');
        if (!ctx) return;

        if (warehouseStatusChart) {
            try { warehouseStatusChart.destroy(); } catch { /* ignore */ }
            warehouseStatusChart = null;
        }

        warehouseStatusChart = new Chart(ctx, {
            plugins: [NFAValueLabelsPlugin],
            type: 'doughnut',
            data: {
                labels: ['Current Inventory', 'Available Capacity'],
                datasets: [{
                    data: [inventory, Math.max(0, total - inventory)],
                    backgroundColor: ['#3b82f6', '#10b981'],
                    borderColor: '#fff',
                    borderWidth: 2,
                    cutout: '62%'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    nfaValueLabels: {
                        type: 'doughnut',
                        datasetIndex: 0,
                        total,
                        font: '700 10px Arial',
                        formatter: (value, pct) => `${fmt(value)} (${fmt(pct, { decimals: 1 })}%)`
                    },
                    tooltip: {
                        callbacks: {
                            label: (context) => {
                                const v = Number(context.raw || 0);
                                const pct = total > 0 ? (v / total) * 100 : 0;
                                return `${fmt(v)} bags (${fmt(pct, { decimals: 1 })}%)`;
                            }
                        }
                    }
                }
            }
        });
    };

    const parseYmd = (ymd) => {
        const m = /^\s*(\d{4})-(\d{2})-(\d{2})\s*$/.exec(String(ymd || ''));
        if (!m) return null;
        const y = parseInt(m[1], 10);
        const mo = parseInt(m[2], 10);
        const d = parseInt(m[3], 10);
        return new Date(y, mo - 1, d);
    };

    const fmtDateShort = (ymd) => {
        const d = parseYmd(ymd);
        if (!d) return String(ymd || '');
        return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
    };

    const fmtMonth = (ymd) => {
        const d = parseYmd(ymd);
        if (!d) return String(ymd || '');
        return d.toLocaleDateString(undefined, { month: 'short', year: 'numeric' });
    };

    const renderWarehouseTrend = (trend, filters) => {
        const gran = String(trend?.granularity || '').toLowerCase();
        const points = Array.isArray(trend?.points) ? trend.points : [];

        const labelEl = $('#warehouseGranularityLabel');
        const granLabel = (gran === 'session') ? 'Session (AM/PM)' : (gran === 'day') ? 'Day' : (gran === 'week') ? 'Week' : (gran === 'month') ? 'Month' : '—';
        if (labelEl) labelEl.textContent = `Viewing data by: ${granLabel}`;

        const labels = points.map(p => {
            const s = p.start_date;
            const e = p.end_date;
            if (gran === 'month') return fmtMonth(s);
            if (gran === 'day') return fmtDateShort(s);
            if (gran === 'session') {
                const slot = String(p.time_slot || '').toUpperCase();
                const slotLabel = (slot === 'AM' || slot === 'PM') ? ` ${slot}` : '';
                return `${fmtDateShort(s)}${slotLabel}`;
            }
            return (s && e) ? `${fmtDateShort(s)}–${fmtDateShort(e)}` : (p.label || '');
        });
        const vols = points.map(p => Number(p.volume || 0));
        const counts = points.map(p => Number(p.count || 0));

        const totalVol = vols.reduce((a, b) => a + b, 0);
        const totalCnt = counts.reduce((a, b) => a + b, 0);

        const note = $('#warehouseTrendNote');
        if (note) {
            note.textContent = `${filters.start} to ${filters.end} • Completed deliveries: ${fmt(totalCnt)} • Total volume: ${fmt(totalVol)} bags`;
        }

        const canvas = $('#warehouseMonthlyChart');
        if (!canvas || typeof Chart === 'undefined') return;
        const ctx = canvas.getContext('2d');
        if (!ctx) return;

        if (warehouseTrendChart) {
            try { warehouseTrendChart.destroy(); } catch { /* ignore */ }
            warehouseTrendChart = null;
        }

        warehouseTrendChart = new Chart(ctx, {
            plugins: [NFAValueLabelsPlugin],
            type: 'line',
            data: {
                labels,
                datasets: [{
                    label: 'Completed Volume (bags)',
                    data: vols,
                    counts,
                    borderColor: 'rgba(52, 152, 219, 1)',
                    backgroundColor: 'rgba(52, 152, 219, 0.18)',
                    tension: 0.28,
                    fill: true,
                    pointRadius: 3,
                    pointHoverRadius: 5,
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: { beginAtZero: true, ticks: { callback: (v) => fmt(v) } }
                },
                plugins: {
                    legend: { display: false },
                    nfaValueLabels: {
                        type: 'line',
                        datasetIndex: 0,
                        granularity: gran,
                        maxDailyAll: 14,
                        maxOtherAll: 18,
                        font: '700 10px Arial',
                        formatter: (value) => fmt(value)
                    },
                    tooltip: {
                        callbacks: {
                            label: (context) => {
                                const v = Number(context.raw || 0);
                                return `Volume: ${fmt(v)} bags`;
                            },
                            afterBody: (items) => {
                                const it = items && items[0];
                                const idx = it ? it.dataIndex : -1;
                                const c = (idx >= 0 && idx < counts.length) ? counts[idx] : 0;
                                return `Completed appointments: ${fmt(c)}`;
                            }
                        }
                    }
                }
            }
        });
    };

    const runWarehouseReport = async () => {
        const filters = getFilters();
        // Warehouse report trend is always based on completed deliveries.
        // Status chips are disabled in warehouse mode, but validation still expects at least one status.
        filters.statuses = ['completed'];
        const err = validateFilters(filters);
        if (err) {
            notify(err, 'warning');
            return;
        }

        // Warehouse report always uses completed appointments for monthly aggregation.
        const btns = [$('#btnModeAppointments'), $('#btnModeWarehouse'), $('#btnPrintReport'), $('#btnResetFilters')].filter(Boolean);
        try {
            await withLoading('Loading warehouse report…', btns, async () => {
                const data = await fetchWarehouseReport(filters);
                state.warehouse.data = data;
                renderWarehouseCapacity(data.capacity || {});

                renderWarehouseTrend(data.trend || {}, filters);
                try { updateUpdatedText(); } catch { /* ignore */ }
            });
        } catch (e) {
            const msg = (e && e.message) ? e.message : 'Failed to load warehouse report.';
            notify(msg, 'danger');
            const updated = $('#reportsUpdatedText');
            if (updated) updated.textContent = 'Error';
        }
    };

    const runActiveReport = async ({ resetPage = true } = {}) => {
        if (state.mode === 'warehouse') return runWarehouseReport();
        return runReport({ resetPage });
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

        const getCanvasDataUrl = (sel) => {
                const canvas = $(sel);
                if (!canvas || typeof canvas.toDataURL !== 'function') return '';
                try {
                        return canvas.toDataURL('image/png');
                } catch {
                        return '';
                }
        };

        const printWarehouseReport = async () => {
                const filters = getFilters();
                // Warehouse intake trend is always based on completed deliveries.
                filters.statuses = ['completed'];

                const err = validateFilters(filters);
                if (err) {
                        notify(err, 'warning');
                        return;
                }

                const ok = (typeof confirmDialog === 'function')
                        ? await confirmDialog({
                                title: 'Print Warehouse Report',
                                message: 'Open a print-ready warehouse report with the charts using the current filters?',
                                confirmText: 'Open Print View',
                                cancelText: 'Cancel',
                                tone: 'primary'
                        })
                        : window.confirm('Open a print-ready warehouse report with the charts using the current filters?');

                if (!ok) return;

                try {
                        const btns = [$('#btnModeAppointments'), $('#btnModeWarehouse'), $('#btnPrintReport'), $('#btnResetFilters')].filter(Boolean);
                        await withLoading('Preparing print view…', btns, async () => {
                                // Always fetch fresh so the printout matches the active filters.
                                const data = await fetchWarehouseReport(filters);
                                state.warehouse.data = data;

                                // Ensure canvases are up-to-date before capturing.
                                try { renderWarehouseCapacity(data.capacity || {}); } catch { /* ignore */ }
                                try { renderWarehouseTrend(data.trend || {}, filters); } catch { /* ignore */ }

                                const donutUrl = getCanvasDataUrl('#warehouseStatusChart');
                                const trendUrl = getCanvasDataUrl('#warehouseMonthlyChart');
                                const noteText = ($('#warehouseTrendNote')?.textContent || '').trim();

                                const token = `${Date.now()}_${Math.random().toString(16).slice(2)}`;
                                try {
                                        localStorage.setItem(
                                                `nfa_warehouse_print_${token}`,
                                                JSON.stringify({ donut: donutUrl, trend: trendUrl, note: noteText })
                                        );
                                } catch {
                                        // If storage fails, the print page will show placeholders.
                                }

                                const q = buildQuery(filters, { token });
                                const url = `reports_warehouse_print.php?${q.toString()}`;
                                const w = window.open(url, '_blank');
                                if (!w) notify('Pop-up blocked. Please allow pop-ups to open the print view.', 'warning');
                        });
                } catch (e) {
                        const msg = (e && e.message) ? e.message : 'Failed to open warehouse print view.';
                        notify(msg, 'danger');
                }
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
        state.table.pageSize = 10;
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
        // Table page size is fixed (10 rows).

        setModeButtonState(state.mode);

        $('#btnModeAppointments')?.addEventListener('click', () => {
            state.mode = 'appointments';
            setModeButtonState(state.mode);
            runActiveReport({ resetPage: true }).catch(() => { /* handled */ });
        });
        $('#btnModeWarehouse')?.addEventListener('click', () => {
            state.mode = 'warehouse';
            setModeButtonState(state.mode);
            runActiveReport({ resetPage: true }).catch(() => { /* handled */ });
        });

        $('#btnPrev')?.addEventListener('click', () => {
            if (state.mode !== 'appointments') return;
            state.table.page = Math.max(1, state.table.page - 1);
            if (state.table.lastFilters) runReport({ resetPage: false });
        });
        $('#btnNext')?.addEventListener('click', () => {
            if (state.mode !== 'appointments') return;
            state.table.page += 1;
            if (state.table.lastFilters) runReport({ resetPage: false });
        });

        const scheduleAutoRun = debounce(() => {
            state.table.page = 1;
            runActiveReport({ resetPage: true }).catch(() => { /* handled */ });
        }, 250);

        // Auto-apply: any filter change triggers a refresh.
        ['#filterStart', '#filterEnd', '#filterSlot', '#filterType'].forEach(sel => {
            $(sel)?.addEventListener('change', scheduleAutoRun);
        });
        document.querySelectorAll('#statusChips input[type="checkbox"]').forEach(cb => {
            cb.addEventListener('change', scheduleAutoRun);
        });

        $('#btnPrintReport')?.addEventListener('click', (e) => {
            e.preventDefault();
            if (state.mode === 'warehouse') {
                printWarehouseReport();
                return;
            }
            printReport();
        });

        $('#btnResetFilters')?.addEventListener('click', () => {
            resetFilters();
            if (typeof showToast === 'function') showToast('Filters reset.', 'info');
            runActiveReport({ resetPage: true }).catch(() => { /* handled */ });
        });

        $('#btnQuickMonth')?.addEventListener('click', () => {
            const now = new Date();
            const start = new Date(now.getFullYear(), now.getMonth(), 1);
            const end = new Date(now.getFullYear(), now.getMonth() + 1, 0);
            const toYmd = (d) => d.toISOString().slice(0, 10);
            $('#filterStart').value = toYmd(start);
            $('#filterEnd').value = toYmd(end);

            state.table.page = 1;
            runActiveReport({ resetPage: true }).catch(() => { /* handled */ });
        });

        $('#btnQuickWeek')?.addEventListener('click', () => {
            setQuickRange(7);
            state.table.page = 1;
            runActiveReport({ resetPage: true }).catch(() => { /* handled */ });
        });

        // First paint: run once automatically
        runActiveReport({ resetPage: true }).catch((e) => {
            const msg = (e && e.message) ? e.message : 'Failed to generate report.';
            notify(msg, 'danger');
        });
    };

    document.addEventListener('DOMContentLoaded', init);
})();
