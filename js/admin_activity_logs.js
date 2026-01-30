document.addEventListener('DOMContentLoaded', function () {
    const tbody = document.getElementById('logsTbody');
    if (!tbody) return;

    const attemptsTbody = document.getElementById('loginAttemptsTbody');
    const attemptsPaginationHost = document.getElementById('loginAttemptsPagination');

    const tabSystem = document.getElementById('tabSystem');
    const tabLoginErrors = document.getElementById('tabLoginErrors');
    const panelSystem = document.getElementById('panelSystem');
    const panelLoginErrors = document.getElementById('panelLoginErrors');

    const qInput = document.getElementById('logQ');
    const fromInput = document.getElementById('logFrom');
    const toInput = document.getElementById('logTo');

    const regionSel = document.getElementById('logRegion');
    const branchSel = document.getElementById('logBranch');

    const refreshBtn = document.getElementById('btnLogRefresh');
    const resetBtn = document.getElementById('btnLogReset');
    const printBtn = document.getElementById('btnPrintLogs');

    const paginationHost = document.getElementById('logsPagination');
    const notice = document.getElementById('logsNotice');

    const modalBackdrop = document.getElementById('logModalBackdrop');
    const modalClose = document.getElementById('logModalClose');
    const modalOk = document.getElementById('logModalOk');
    const modalTitle = document.getElementById('logModalTitle');
    const modalSub = document.getElementById('logModalSub');
    const modalBody = document.getElementById('logModalBody');

    const attemptModalBackdrop = document.getElementById('attemptModalBackdrop');
    const attemptModalClose = document.getElementById('attemptModalClose');
    const attemptModalOk = document.getElementById('attemptModalOk');
    const attemptModalTitle = document.getElementById('attemptModalTitle');
    const attemptModalSub = document.getElementById('attemptModalSub');
    const attemptModalBody = document.getElementById('attemptModalBody');

    let items = [];
    let currentPage = 1;
    let pageSize = 50;
    let sort = { key: 'timestamp', dir: 'desc' };

    let attemptItems = [];
    let attemptCurrentPage = 1;
    let attemptPageSize = 50;
    let attemptSort = { key: 'attempt_ts', dir: 'desc' };

    let activeTab = 'system';

    let regionsCache = null;
    let branchesByRegion = new Map();

    function escapeHtml(s) {
        const div = document.createElement('div');
        div.textContent = s ?? '';
        return div.innerHTML;
    }

    function setNotice(message, kind) {
        if (!notice) return;
        notice.classList.remove('show', 'success', 'error');
        notice.textContent = '';
        if (!message) return;

        notice.textContent = message;
        notice.classList.add('show');
        if (kind) notice.classList.add(kind);

        window.clearTimeout(setNotice._t);
        setNotice._t = window.setTimeout(() => {
            notice.classList.remove('show', 'success', 'error');
        }, 3500);
    }

    function formatDate(ts) {
        if (!ts) return '—';
        const d = new Date(ts);
        if (Number.isNaN(d.getTime())) return String(ts);
        return d.toLocaleString(undefined, {
            year: 'numeric',
            month: 'short',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit'
        });
    }

    function sentenceCase(s) {
        const t = String(s || '').trim();
        if (!t) return '';
        return t.charAt(0).toUpperCase() + t.slice(1);
    }

    function humanizeReason(code) {
        const c = String(code || '').trim();
        if (!c) return '—';
        const pretty = c.replace(/_/g, ' ').toLowerCase();
        return pretty.charAt(0).toUpperCase() + pretty.slice(1);
    }

    function getActionType(action) {
        const act = String(action || '').toLowerCase();
        if (act.includes('login') || act.includes('logout')) return 'login';
        if (act.includes('create') || act.includes('add') || act.includes('new')) return 'create';
        if (act.includes('update') || act.includes('edit') || act.includes('modify')) return 'update';
        if (act.includes('delete') || act.includes('remove') || act.includes('archive')) return 'delete';
        return 'system';
    }

    async function getRegions() {
        if (regionsCache) return regionsCache;
        const res = await fetch('php_helper/api.php?action=getRegions');
        const json = await res.json();
        regionsCache = json?.regions || json?.data || json || [];
        return regionsCache;
    }

    async function getBranches(regionId) {
        if (branchesByRegion.has(regionId)) return branchesByRegion.get(regionId);
        const res = await fetch(`php_helper/api.php?action=getBranches&region_id=${encodeURIComponent(regionId)}`);
        const json = await res.json();
        const branches = json?.branches || json?.data || json || [];
        branchesByRegion.set(regionId, branches);
        return branches;
    }

    function fillSelect(selectEl, items, idKey, labelKey, placeholder, selectedId) {
        if (!selectEl) return;
        selectEl.innerHTML = '';
        const first = document.createElement('option');
        first.value = '';
        first.textContent = placeholder;
        selectEl.appendChild(first);

        (items || []).forEach(item => {
            const id = parseInt(item[idKey] ?? item.id ?? '0', 10);
            const label = String(item[labelKey] ?? item.name ?? '').trim();
            if (!id) return;
            const opt = document.createElement('option');
            opt.value = String(id);
            opt.textContent = label;
            if (selectedId && id === selectedId) opt.selected = true;
            selectEl.appendChild(opt);
        });
    }

    function showLoading() {
        tbody.innerHTML = '';
        for (let i = 0; i < 10; i++) {
            const tr = document.createElement('tr');
            tr.className = 'loading-row';
            tr.innerHTML = `
                <td><div class="skeleton-cell" style="width: 75%"></div></td>
                <td><div class="skeleton-cell" style="width: 55%"></div></td>
                <td><div class="skeleton-cell" style="width: 65%"></div></td>
                <td><div class="skeleton-cell" style="width: 90%"></div></td>
                <td><div class="skeleton-cell" style="width: 45%"></div></td>
                <td><div class="skeleton-cell" style="width: 35%"></div></td>
            `;
            tbody.appendChild(tr);
        }
    }

    function showAttemptsLoading() {
        if (!attemptsTbody) return;
        attemptsTbody.innerHTML = '';
        for (let i = 0; i < 10; i++) {
            const tr = document.createElement('tr');
            tr.className = 'loading-row';
            tr.innerHTML = `
                <td><div class="skeleton-cell" style="width: 75%"></div></td>
                <td><div class="skeleton-cell" style="width: 55%"></div></td>
                <td><div class="skeleton-cell" style="width: 65%"></div></td>
                <td><div class="skeleton-cell" style="width: 90%"></div></td>
                <td><div class="skeleton-cell" style="width: 45%"></div></td>
                <td><div class="skeleton-cell" style="width: 35%"></div></td>
            `;
            attemptsTbody.appendChild(tr);
        }
    }

    function openModal(log) {
        if (!modalBackdrop || !modalBody) return;

        const who = `${log.first_name || ''} ${log.last_name || ''}`.trim() || log.username || 'User';
        const where = [log.region_name, log.branch_name].filter(Boolean).join(' • ') || '—';
        const type = getActionType(log.action);
        const typeLabel = sentenceCase(type);

        if (modalTitle) modalTitle.textContent = 'Activity Details';
        if (modalSub) modalSub.textContent = `${formatDate(log.timestamp)} • ${who}`;

        const rows = [
            ['Processor', who],
            ['Employee ID', log.employee_id || '—'],
            ['Region / Branch', where],
            ['Action', log.action || '—'],
            ['Type', typeLabel || '—'],
            ['Details', log.details || '—'],
            ['IP Address', log.ip_address || '—'],
        ];

        modalBody.innerHTML = `
            <div class="activity-modal-head" style="grid-column: 1 / -1;">
                <div class="activity-modal-who">
                    <div class="activity-modal-title">${escapeHtml(who)}</div>
                    <div class="activity-modal-sub">${escapeHtml(where)}</div>
                </div>
                <div class="activity-modal-badges">
                    <span class="action-type action-type-${escapeHtml(type)}">${escapeHtml(typeLabel || type)}</span>
                </div>
            </div>
            <div class="modal-section" style="grid-column: 1 / -1;">
                <h3>Log Details</h3>
                <div class="kv-grid">
                    ${rows.map(([k, v]) => `
                        <div class="kv">
                            <div class="k">${escapeHtml(k)}</div>
                            <div class="v">${escapeHtml(v ?? '—')}</div>
                        </div>
                    `).join('')}
                </div>
            </div>
        `;

        modalBackdrop.style.display = 'flex';
        document.body.classList.add('modal-open');
        (modalClose || modalOk || modalBackdrop).focus?.();
    }

    function closeModal() {
        if (!modalBackdrop) return;
        modalBackdrop.style.display = 'none';
        document.body.classList.remove('modal-open');
    }

    function openAttemptModal(attempt) {
        if (!attemptModalBackdrop || !attemptModalBody) return;

        const attempted = attempt.attempted_username || '—';
        const matchedName = `${attempt.first_name || ''} ${attempt.last_name || ''}`.trim();
        const matchedUser = matchedName || attempt.matched_username || '';
        const where = [attempt.region_name, attempt.branch_name].filter(Boolean).join(' • ') || '—';

        if (attemptModalTitle) attemptModalTitle.textContent = 'Login Attempt Details';
        if (attemptModalSub) attemptModalSub.textContent = `${formatDate(attempt.occurred_at)} • ${attempted}`;

        const rows = [
            ['Timestamp', formatDate(attempt.occurred_at)],
            ['Attempted Username', attempted],
            ['Matched Account', matchedUser || '—'],
            ['Employee ID', attempt.employee_id || '—'],
            ['Region / Branch', where],
            ['Reason', humanizeReason(attempt.reason_code)],
            ['Account Status', attempt.account_status || '—'],
            ['Active', String(attempt.is_active ?? '').trim() === '' ? '—' : (String(attempt.is_active) === '1' ? 'Yes' : 'No')],
            ['IP Address', attempt.ip_address || '—'],
            ['User Agent', attempt.user_agent || '—'],
        ];

        attemptModalBody.innerHTML = `
            <div class="modal-section" style="grid-column: 1 / -1;">
                <h3>Attempt Details</h3>
                <div class="kv-grid">
                    ${rows.map(([k, v]) => `
                        <div class="kv">
                            <div class="k">${escapeHtml(k)}</div>
                            <div class="v">${escapeHtml(v ?? '—')}</div>
                        </div>
                    `).join('')}
                </div>
            </div>
        `;

        attemptModalBackdrop.style.display = 'flex';
        document.body.classList.add('modal-open');
        (attemptModalClose || attemptModalOk || attemptModalBackdrop).focus?.();
    }

    function closeAttemptModal() {
        if (!attemptModalBackdrop) return;
        attemptModalBackdrop.style.display = 'none';
        document.body.classList.remove('modal-open');
    }

    function sortItems(list) {
        const dir = sort.dir === 'asc' ? 1 : -1;
        const key = sort.key;

        return [...list].sort((a, b) => {
            let av = '';
            let bv = '';

            if (key === 'timestamp') {
                av = new Date(a.timestamp || 0).getTime();
                bv = new Date(b.timestamp || 0).getTime();
            } else if (key === 'processor') {
                av = `${a.first_name || ''} ${a.last_name || ''}`.toLowerCase();
                bv = `${b.first_name || ''} ${b.last_name || ''}`.toLowerCase();
            } else if (key === 'location') {
                av = `${a.region_name || ''} ${a.branch_name || ''}`.toLowerCase();
                bv = `${b.region_name || ''} ${b.branch_name || ''}`.toLowerCase();
            } else if (key === 'action') {
                av = String(a.action || '').toLowerCase();
                bv = String(b.action || '').toLowerCase();
            } else if (key === 'type') {
                av = getActionType(a.action);
                bv = getActionType(b.action);
            }

            if (av > bv) return 1 * dir;
            if (av < bv) return -1 * dir;
            return 0;
        });
    }

    function sortAttempts(list) {
        const dir = attemptSort.dir === 'asc' ? 1 : -1;
        const key = attemptSort.key;

        return [...list].sort((a, b) => {
            let av = '';
            let bv = '';

            if (key === 'attempt_ts') {
                av = new Date(a.occurred_at || 0).getTime();
                bv = new Date(b.occurred_at || 0).getTime();
            } else if (key === 'attempt_user') {
                av = String(a.attempted_username || '').toLowerCase();
                bv = String(b.attempted_username || '').toLowerCase();
            } else if (key === 'attempt_match') {
                av = `${a.first_name || ''} ${a.last_name || ''} ${a.matched_username || ''}`.toLowerCase();
                bv = `${b.first_name || ''} ${b.last_name || ''} ${b.matched_username || ''}`.toLowerCase();
            } else if (key === 'attempt_reason') {
                av = String(a.reason_code || '').toLowerCase();
                bv = String(b.reason_code || '').toLowerCase();
            } else if (key === 'attempt_ip') {
                av = String(a.ip_address || '').toLowerCase();
                bv = String(b.ip_address || '').toLowerCase();
            }

            if (av > bv) return 1 * dir;
            if (av < bv) return -1 * dir;
            return 0;
        });
    }

    function render() {
        const sorted = sortItems(items);
        const total = sorted.length;
        const totalPages = Math.max(1, Math.ceil(total / pageSize));
        if (currentPage > totalPages) currentPage = totalPages;

        const start = (currentPage - 1) * pageSize;
        const page = sorted.slice(start, start + pageSize);

        if (total === 0) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="6" class="empty-state">
                        <div class="empty-state-icon"><i class="fas fa-clipboard"></i></div>
                        <h3>No activity logs found</h3>
                        <p>No activity logs match your current filters.</p>
                    </td>
                </tr>
            `;
            renderPagination(0, 1);
            return;
        }

        tbody.innerHTML = '';
        page.forEach((log) => {
            const who = `${log.first_name || ''} ${log.last_name || ''}`.trim() || log.username || 'User';
            const where = [log.region_name, log.branch_name].filter(Boolean).join(' • ') || '—';
            const type = getActionType(log.action);
            const typeLabel = sentenceCase(type);

            const subline = (log.employee_id && String(log.employee_id).trim() !== '')
                ? `Employee ID: ${String(log.employee_id).trim()}`
                : (log.username ? `@${String(log.username)}` : '');

            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td>${escapeHtml(formatDate(log.timestamp))}</td>
                <td>
                    <strong>${escapeHtml(who)}</strong>
                    ${subline ? `<div class="muted">${escapeHtml(subline)}</div>` : ''}
                </td>
                <td>${escapeHtml(where)}</td>
                <td>${escapeHtml(log.action || '—')}</td>
                <td><span class="action-type action-type-${escapeHtml(type)}">${escapeHtml(typeLabel || type)}</span></td>
                <td>
                    <button class="btn-view-details btn-inline-secondary" type="button" data-view="1">
                        <i class="fas fa-eye"></i> View
                    </button>
                </td>
            `;

            tr.querySelector('button[data-view]')?.addEventListener('click', () => openModal(log));
            tbody.appendChild(tr);
        });

        renderPagination(total, totalPages);
    }

    function renderAttempts() {
        if (!attemptsTbody) return;

        const sorted = sortAttempts(attemptItems);
        const total = sorted.length;
        const totalPages = Math.max(1, Math.ceil(total / attemptPageSize));
        if (attemptCurrentPage > totalPages) attemptCurrentPage = totalPages;

        const start = (attemptCurrentPage - 1) * attemptPageSize;
        const page = sorted.slice(start, start + attemptPageSize);

        if (total === 0) {
            attemptsTbody.innerHTML = `
                <tr>
                    <td colspan="6" class="empty-state">
                        <div class="empty-state-icon"><i class="fas fa-user-shield"></i></div>
                        <h3>No login errors found</h3>
                        <p>No failed login attempts match your current filters.</p>
                    </td>
                </tr>
            `;
            renderAttemptsPagination(0, 1);
            return;
        }

        attemptsTbody.innerHTML = '';
        page.forEach((a) => {
            const attempted = a.attempted_username || '—';
            const matchedName = `${a.first_name || ''} ${a.last_name || ''}`.trim();
            const matchedUser = matchedName || a.matched_username || '—';
            const reason = humanizeReason(a.reason_code);
            const ip = a.ip_address || '—';

            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td>${escapeHtml(formatDate(a.occurred_at))}</td>
                <td><strong>${escapeHtml(attempted)}</strong></td>
                <td>
                    <strong>${escapeHtml(matchedUser)}</strong>
                    ${a.employee_id ? `<div class="muted">Employee ID: ${escapeHtml(String(a.employee_id))}</div>` : ''}
                </td>
                <td>${escapeHtml(reason)}</td>
                <td>${escapeHtml(ip)}</td>
                <td>
                    <button class="btn-view-details btn-inline-secondary" type="button" data-view="1">
                        <i class="fas fa-eye"></i> View
                    </button>
                </td>
            `;

            tr.querySelector('button[data-view]')?.addEventListener('click', () => openAttemptModal(a));
            attemptsTbody.appendChild(tr);
        });

        renderAttemptsPagination(total, totalPages);
    }

    function renderPagination(total, totalPages) {
        if (!paginationHost) return;

        const canPrev = currentPage > 1;
        const canNext = currentPage < totalPages;

        paginationHost.innerHTML = `
            <div class="pagination">
                <button class="pagination-btn" type="button" ${canPrev ? '' : 'disabled'} data-page="prev">
                    <i class="fas fa-chevron-left"></i> Previous
                </button>
                <span class="pagination-info">Page ${currentPage} of ${totalPages} • ${total} logs</span>
                <button class="pagination-btn" type="button" ${canNext ? '' : 'disabled'} data-page="next">
                    Next <i class="fas fa-chevron-right"></i>
                </button>
                <select class="form-control page-size-select" id="pageSizeSel" aria-label="Rows per page">
                    ${[10, 20, 50, 100].map(n => `<option value="${n}" ${n === pageSize ? 'selected' : ''}>${n} / page</option>`).join('')}
                </select>
            </div>
        `;

        paginationHost.querySelector('button[data-page="prev"]')?.addEventListener('click', () => {
            if (currentPage > 1) {
                currentPage--;
                render();
            }
        });

        paginationHost.querySelector('button[data-page="next"]')?.addEventListener('click', () => {
            if (currentPage < totalPages) {
                currentPage++;
                render();
            }
        });

        paginationHost.querySelector('#pageSizeSel')?.addEventListener('change', (e) => {
            pageSize = parseInt(e.target.value || '50', 10);
            currentPage = 1;
            refresh();
        });
    }

    function renderAttemptsPagination(total, totalPages) {
        if (!attemptsPaginationHost) return;

        const canPrev = attemptCurrentPage > 1;
        const canNext = attemptCurrentPage < totalPages;

        attemptsPaginationHost.innerHTML = `
            <div class="pagination">
                <button class="pagination-btn" type="button" ${canPrev ? '' : 'disabled'} data-page="prev">
                    <i class="fas fa-chevron-left"></i> Previous
                </button>
                <span class="pagination-info">Page ${attemptCurrentPage} of ${totalPages} • ${total} attempts</span>
                <button class="pagination-btn" type="button" ${canNext ? '' : 'disabled'} data-page="next">
                    Next <i class="fas fa-chevron-right"></i>
                </button>
                <select class="form-control page-size-select" id="attemptPageSizeSel" aria-label="Rows per page">
                    ${[10, 20, 50, 100].map(n => `<option value="${n}" ${n === attemptPageSize ? 'selected' : ''}>${n} / page</option>`).join('')}
                </select>
            </div>
        `;

        attemptsPaginationHost.querySelector('button[data-page="prev"]')?.addEventListener('click', () => {
            if (attemptCurrentPage > 1) {
                attemptCurrentPage--;
                renderAttempts();
            }
        });

        attemptsPaginationHost.querySelector('button[data-page="next"]')?.addEventListener('click', () => {
            if (attemptCurrentPage < totalPages) {
                attemptCurrentPage++;
                renderAttempts();
            }
        });

        attemptsPaginationHost.querySelector('#attemptPageSizeSel')?.addEventListener('change', (e) => {
            attemptPageSize = parseInt(e.target.value || '50', 10);
            attemptCurrentPage = 1;
            refreshAttempts();
        });
    }

    async function refreshSystem() {
        showLoading();

        const q = qInput ? qInput.value.trim() : '';
        const from = fromInput ? fromInput.value : '';
        const to = toInput ? toInput.value : '';

        const regionId = regionSel ? parseInt(regionSel.value || '0', 10) : 0;
        const branchId = branchSel ? parseInt(branchSel.value || '0', 10) : 0;

        try {
            const url = `php_helper/api.php?action=adminGetActivityLogs&q=${encodeURIComponent(q)}&from=${encodeURIComponent(from)}&to=${encodeURIComponent(to)}&region_id=${encodeURIComponent(regionId || 0)}&branch_id=${encodeURIComponent(branchId || 0)}&limit=1000`;
            const res = await fetch(url);
            const json = await res.json();

            if (!json || !json.success) {
                throw new Error(json?.error || json?.message || 'Failed to load activity logs');
            }

            items = json.items || [];
            currentPage = 1;
            render();
        } catch (err) {
            setNotice(err.message || 'Failed to load activity logs.', 'error');
            tbody.innerHTML = `
                <tr>
                    <td colspan="6" class="empty-state">
                        <div class="empty-state-icon"><i class="fas fa-triangle-exclamation"></i></div>
                        <h3>Loading Error</h3>
                        <p>${escapeHtml(err.message || 'Please try again.')}</p>
                    </td>
                </tr>
            `;
            if (paginationHost) paginationHost.innerHTML = '';
        }
    }

    async function refreshAttempts() {
        showAttemptsLoading();

        const q = qInput ? qInput.value.trim() : '';
        const from = fromInput ? fromInput.value : '';
        const to = toInput ? toInput.value : '';

        const regionId = regionSel ? parseInt(regionSel.value || '0', 10) : 0;
        const branchId = branchSel ? parseInt(branchSel.value || '0', 10) : 0;

        try {
            const url = `php_helper/api.php?action=adminGetLoginAttempts&q=${encodeURIComponent(q)}&from=${encodeURIComponent(from)}&to=${encodeURIComponent(to)}&region_id=${encodeURIComponent(regionId || 0)}&branch_id=${encodeURIComponent(branchId || 0)}&limit=1000`;
            const res = await fetch(url);
            const json = await res.json();

            if (!json || !json.success) {
                throw new Error(json?.error || json?.message || 'Failed to load login attempts');
            }

            attemptItems = json.items || [];
            attemptCurrentPage = 1;
            renderAttempts();
        } catch (err) {
            setNotice(err.message || 'Failed to load login attempts.', 'error');
            if (attemptsTbody) {
                attemptsTbody.innerHTML = `
                    <tr>
                        <td colspan="6" class="empty-state">
                            <div class="empty-state-icon"><i class="fas fa-triangle-exclamation"></i></div>
                            <h3>Loading Error</h3>
                            <p>${escapeHtml(err.message || 'Please try again.')}</p>
                        </td>
                    </tr>
                `;
            }
            if (attemptsPaginationHost) attemptsPaginationHost.innerHTML = '';
        }
    }

    function refreshActive() {
        if (activeTab === 'loginErrors') {
            refreshAttempts();
        } else {
            refreshSystem();
        }
    }

    function openPrint() {
        const q = qInput ? qInput.value.trim() : '';
        const from = fromInput ? fromInput.value : '';
        const to = toInput ? toInput.value : '';
        const regionId = regionSel ? parseInt(regionSel.value || '0', 10) : 0;
        const branchId = branchSel ? parseInt(branchSel.value || '0', 10) : 0;

        const url = `admin_activity_logs_print.php?q=${encodeURIComponent(q)}&from=${encodeURIComponent(from)}&to=${encodeURIComponent(to)}&region_id=${encodeURIComponent(regionId || 0)}&branch_id=${encodeURIComponent(branchId || 0)}`;
        window.open(url, '_blank', 'noopener');
    }

    function setActiveTab(nextTab) {
        activeTab = nextTab;

        const isSystem = nextTab === 'system';
        if (tabSystem) {
            tabSystem.classList.toggle('active', isSystem);
            tabSystem.setAttribute('aria-selected', isSystem ? 'true' : 'false');
        }
        if (tabLoginErrors) {
            tabLoginErrors.classList.toggle('active', !isSystem);
            tabLoginErrors.setAttribute('aria-selected', isSystem ? 'false' : 'true');
        }

        if (panelSystem) panelSystem.style.display = isSystem ? '' : 'none';
        if (panelLoginErrors) panelLoginErrors.style.display = isSystem ? 'none' : '';

        if (printBtn) printBtn.style.display = isSystem ? '' : 'none';

        refreshActive();
    }

    function debounce(fn, ms) {
        let t;
        return function () {
            window.clearTimeout(t);
            t = window.setTimeout(fn, ms);
        };
    }

    // Sorting (System)
    document.querySelectorAll('#panelSystem th.sortable[data-sort]').forEach(th => {
        th.addEventListener('click', () => {
            const key = th.getAttribute('data-sort');
            if (!key) return;

            if (sort.key === key) {
                sort.dir = sort.dir === 'asc' ? 'desc' : 'asc';
            } else {
                sort = { key, dir: 'desc' };
            }

            document.querySelectorAll('#panelSystem th.sortable').forEach(h => h.classList.remove('sort-asc', 'sort-desc'));
            th.classList.add(sort.dir === 'asc' ? 'sort-asc' : 'sort-desc');

            render();
        });
    });

    // Sorting (Login Errors)
    document.querySelectorAll('#panelLoginErrors th.sortable[data-sort]').forEach(th => {
        th.addEventListener('click', () => {
            const key = th.getAttribute('data-sort');
            if (!key) return;

            if (attemptSort.key === key) {
                attemptSort.dir = attemptSort.dir === 'asc' ? 'desc' : 'asc';
            } else {
                attemptSort = { key, dir: 'desc' };
            }

            document.querySelectorAll('#panelLoginErrors th.sortable').forEach(h => h.classList.remove('sort-asc', 'sort-desc'));
            th.classList.add(attemptSort.dir === 'asc' ? 'sort-asc' : 'sort-desc');

            renderAttempts();
        });
    });

    // Filters
    if (qInput) qInput.addEventListener('input', debounce(refreshActive, 300));
    if (fromInput) fromInput.addEventListener('change', refreshActive);
    if (toInput) toInput.addEventListener('change', refreshActive);
    if (regionSel) {
        regionSel.addEventListener('change', async function () {
            const regionId = parseInt(this.value || '0', 10);
            if (!branchSel) {
                refreshActive();
                return;
            }

            if (!regionId) {
                fillSelect(branchSel, [], 'branch_id', 'branch_name', 'Select Region First', null);
                branchSel.disabled = true;
                branchSel.value = '';
                refreshActive();
                return;
            }

            branchSel.disabled = true;
            const branches = await getBranches(regionId);
            fillSelect(branchSel, branches, 'branch_id', 'branch_name', 'All Branches', null);
            branchSel.disabled = false;
            branchSel.value = '';
            refreshActive();
        });
    }
    if (branchSel) branchSel.addEventListener('change', refreshActive);

    if (refreshBtn) refreshBtn.addEventListener('click', refreshActive);
    if (printBtn) printBtn.addEventListener('click', openPrint);

    if (resetBtn) {
        resetBtn.addEventListener('click', () => {
            if (qInput) qInput.value = '';
            if (fromInput) {
                const d = new Date();
                d.setDate(d.getDate() - 7);
                fromInput.valueAsDate = d;
            }
            if (toInput) toInput.valueAsDate = new Date();
            if (regionSel) regionSel.value = '';
            if (branchSel) {
                branchSel.value = '';
                branchSel.disabled = true;
                fillSelect(branchSel, [], 'branch_id', 'branch_name', 'Select Region First', null);
            }
            refreshActive();
        });
    }

    // Modal wiring
    if (modalClose) modalClose.addEventListener('click', closeModal);
    if (modalOk) modalOk.addEventListener('click', closeModal);
    if (modalBackdrop) {
        modalBackdrop.addEventListener('click', (e) => {
            if (e.target === modalBackdrop) closeModal();
        });
    }

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            closeModal();
            closeAttemptModal();
        }
        if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'r') {
            e.preventDefault();
            refreshActive();
        }
        if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'f') {
            e.preventDefault();
            qInput?.focus();
        }
    });

    // init default range: last 7 days
    if (fromInput && !fromInput.value) {
        const d = new Date();
        d.setDate(d.getDate() - 7);
        fromInput.valueAsDate = d;
    }
    if (toInput && !toInput.value) {
        toInput.valueAsDate = new Date();
    }

    // init region select
    (async function initRegions() {
        if (!regionSel) return;
        try {
            const regions = await getRegions();
            fillSelect(regionSel, regions, 'region_id', 'region_name', 'All Regions', null);
            if (branchSel) {
                fillSelect(branchSel, [], 'branch_id', 'branch_name', 'Select Region First', null);
                branchSel.disabled = true;
            }
        } catch (_) {
            setNotice('Failed to retrieve regions. Please check the regions table / API.', 'error');
        }
    })();

    if (tabSystem) tabSystem.addEventListener('click', () => setActiveTab('system'));
    if (tabLoginErrors) tabLoginErrors.addEventListener('click', () => setActiveTab('loginErrors'));

    if (attemptModalClose) attemptModalClose.addEventListener('click', closeAttemptModal);
    if (attemptModalOk) attemptModalOk.addEventListener('click', closeAttemptModal);
    if (attemptModalBackdrop) {
        attemptModalBackdrop.addEventListener('click', (e) => {
            if (e.target === attemptModalBackdrop) closeAttemptModal();
        });
    }

    refreshSystem();
});