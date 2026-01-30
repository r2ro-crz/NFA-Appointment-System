document.addEventListener('DOMContentLoaded', function () {
    const tiles = document.getElementById('accountsTiles');
    if (!tiles) return;

    const statusSel = document.getElementById('filterStatus');
    const qInput = document.getElementById('filterQ');
    const includeInactive = document.getElementById('filterInactive');
    const refreshBtn = document.getElementById('btnRefresh');
    const resetBtn = document.getElementById('btnResetFilters');

    const notice = document.getElementById('accountsNotice');

    const detailsBackdrop = document.getElementById('accountModalBackdrop');
    const detailsClose = document.getElementById('accountModalClose');
    const detailsKv = document.getElementById('accountDetailsKv');

    const reassignBackdrop = document.getElementById('reassignModalBackdrop');
    const reassignClose = document.getElementById('reassignModalClose');
    const reassignCancel = document.getElementById('reassignCancel');
    const reassignRegion = document.getElementById('reassignRegion');
    const reassignBranch = document.getElementById('reassignBranch');
    const reassignSubmit = document.getElementById('reassignSubmit');

    const confirmBackdrop = document.getElementById('confirmModalBackdrop');
    const confirmClose = document.getElementById('confirmModalClose');

    const url = new URL(window.location.href);
    const viewId = parseInt(url.searchParams.get('view') || '0', 10) || 0;

    let regionsCache = null;
    let branchesByRegion = new Map();
    let selectedForReassign = null;

    if (window.NFA_ADMIN_DEFAULT_STATUS && statusSel) {
        statusSel.value = window.NFA_ADMIN_DEFAULT_STATUS;
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

    function escapeHtml(s) {
        const div = document.createElement('div');
        div.textContent = s ?? '';
        return div.innerHTML;
    }

    function setLoading(isLoading) {
        if (!isLoading) return;
        tiles.innerHTML = `
            <div class="skeleton-card"><div class="skeleton-line short"></div><div class="skeleton-line"></div><div class="skeleton-line medium"></div><div class="skeleton-line"></div><div class="skeleton-line"></div></div>
            <div class="skeleton-card"><div class="skeleton-line short"></div><div class="skeleton-line"></div><div class="skeleton-line medium"></div><div class="skeleton-line"></div><div class="skeleton-line"></div></div>
            <div class="skeleton-card"><div class="skeleton-line short"></div><div class="skeleton-line"></div><div class="skeleton-line medium"></div><div class="skeleton-line"></div><div class="skeleton-line"></div></div>
        `;
    }

    function openModal(backdrop) {
        if (!backdrop) return;
        backdrop.style.display = 'flex';
        document.body.classList.add('modal-open');

        // focus first close button
        const closeBtn = backdrop.querySelector('.modal-close');
        if (closeBtn) closeBtn.focus();
    }

    function closeModal(backdrop) {
        if (!backdrop) return;
        backdrop.style.display = 'none';
        document.body.classList.remove('modal-open');
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

    function statusClass(status) {
        const s = String(status || '').toLowerCase();
        if (s === 'approved') return 'status-approved';
        if (s === 'rejected') return 'status-rejected';
        if (s === 'deactivated') return 'status-deactivated';
        return 'status-pending';
    }

    function renderAccounts(items) {
        if (!items || items.length === 0) {
            tiles.innerHTML = `
                <div class="empty-state">
                    <i class="fas fa-users"></i>
                    <p>No accounts match your filters.</p>
                </div>
            `;
            return;
        }

        tiles.innerHTML = '';

        items.forEach(u => {
            const userId = parseInt(u.user_id ?? '0', 10);
            const fullName = [u.first_name, u.middle_name, u.last_name, u.suffix].filter(Boolean).join(' ');
            const status = u.status || 'Pending';
            const active = parseInt(u.is_active ?? 1, 10) === 1;
            const userType = u.user_type || '';

            const effectiveStatus = (String(status).toLowerCase() === 'approved' && !active)
                ? 'Deactivated'
                : status;

            const card = document.createElement('div');
            card.className = 'appointment-card';
            card.dataset.userId = String(userId);

            if (viewId && userId === viewId) {
                card.classList.add('highlight');
            }

            card.innerHTML = `
                <span class="appt-status-badge ${statusClass(effectiveStatus)}">${escapeHtml(effectiveStatus)}</span>
                <div class="appointment-name">${escapeHtml(fullName || u.username || 'User')}</div>
                <div class="appointment-ref">${escapeHtml(userType)}</div>
                <div class="appointment-meta-row">
                    <span class="account-chip"><i class="fas fa-location-dot"></i> ${escapeHtml(u.region_name || '—')}</span>
                    <span class="account-chip"><i class="fas fa-building"></i> ${escapeHtml(u.branch_name || '—')}</span>
                    <span class="account-chip ${active ? '' : 'inactive'}"><i class="fas ${active ? 'fa-user-check' : 'fa-user-slash'}"></i> ${active ? 'Active' : 'Deactivated'}</span>
                </div>
                <div class="appointment-actions">
                    <button class="btn-view-details btn-inline-secondary" data-action="view" type="button"><i class="fas fa-eye"></i> View</button>
                    ${String(status).toLowerCase() === 'pending' ? `
                        <button class="btn-view-details btn-inline-success" data-action="approve" type="button"><i class="fas fa-check"></i> Approve</button>
                        <button class="btn-view-details btn-inline-danger" data-action="reject" type="button"><i class="fas fa-times"></i> Reject</button>
                    ` : ''}
                    ${String(status).toLowerCase() === 'approved' ? `
                        <button class="btn-view-details btn-inline-secondary" data-action="toggleActive" type="button"><i class="fas ${active ? 'fa-user-slash' : 'fa-user-check'}"></i> ${active ? 'Deactivate' : 'Activate'}</button>
                        ${String(userType).toLowerCase() === 'processor' ? `<button class="btn-view-details btn-inline-primary" data-action="reassign" type="button"><i class="fas fa-random"></i> Reassign</button>` : ''}
                    ` : ''}
                </div>
            `;

            // keep original object on the element for handlers
            card._user = u;
            tiles.appendChild(card);
        });
    }

    function showDetails(u) {
        if (!detailsBackdrop || !detailsKv) return;

        const title = document.getElementById('accountModalTitle');
        const sub = document.getElementById('accountModalSub');

        const fullName = [u.first_name, u.middle_name, u.last_name, u.suffix].filter(Boolean).join(' ');
        if (title) title.textContent = `Account: ${fullName || u.username || 'User'}`;
        if (sub) sub.textContent = `${u.user_type || ''}`;

        const active = (parseInt(u.is_active ?? 1, 10) === 1);
        const effectiveStatus = (String(u.status || '').toLowerCase() === 'approved' && !active)
            ? 'Deactivated'
            : (u.status || 'Pending');

        const rows = [
            ['User Type', u.user_type],
            ['Status', effectiveStatus],
            ['Active', active ? 'Yes' : 'No'],
            ['Employee ID', u.employee_id],
            ['Email', u.email_address],
            ['Contact', u.contact_number],
            ['Gender', u.gender],
            ['Region', u.region_name || '—'],
            ['Branch', u.branch_name || '—'],
        ];

        detailsKv.innerHTML = `
            <div class="modal-section" style="grid-column: 1 / -1;">
                <h3>Account Details</h3>
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

        openModal(detailsBackdrop);
    }

    async function openReassign(u) {
        if (!reassignBackdrop || !reassignRegion || !reassignBranch) return;

        selectedForReassign = u;

        const regions = await getRegions();
        fillSelect(reassignRegion, regions, 'region_id', 'region_name', 'Select Region', parseInt(u.region_id || '0', 10) || null);

        const regionId = parseInt(u.region_id || '0', 10);
        if (!regionId) {
            fillSelect(reassignBranch, [], 'branch_id', 'branch_name', 'Select Region First', null);
            reassignBranch.disabled = true;
        } else {
            const branches = await getBranches(regionId);
            fillSelect(reassignBranch, branches, 'branch_id', 'branch_name', 'Select Branch', parseInt(u.branch_id || '0', 10) || null);
            reassignBranch.disabled = false;
        }

        openModal(reassignBackdrop);
    }

    async function refresh() {
        setLoading(true);

        const rawStatus = statusSel ? statusSel.value : '';
        const status = (String(rawStatus).toLowerCase() === 'all') ? '' : rawStatus;
        const q = qInput ? qInput.value.trim() : '';
        const inc = includeInactive && includeInactive.checked ? 1 : 0;

        const url = `php_helper/api.php?action=adminListAccounts&status=${encodeURIComponent(status)}&q=${encodeURIComponent(q)}&include_inactive=${inc}`;
        const res = await fetch(url);
        const json = await res.json();

        if (!json || !json.success) {
            tiles.innerHTML = `<div class="empty-state"><i class="fas fa-triangle-exclamation"></i><p>Failed to load accounts.</p></div>`;
            return;
        }

        const items = json.items || [];
        renderAccounts(items);

        if (viewId) {
            const target = items.find(x => parseInt(x.user_id ?? '0', 10) === viewId);
            if (target) {
                // scroll to tile and highlight it (do not auto-open details)
                const card = tiles.querySelector(`.appointment-card[data-user-id="${viewId}"]`);
                if (card) {
                    card.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    card.focus?.();
                }
            }
        }
    }

    async function postJson(action, payload) {
        const res = await fetch(`php_helper/api.php?action=${encodeURIComponent(action)}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload || {})
        });

        try {
            return await res.json();
        } catch (_) {
            return { success: false, message: 'Invalid server response' };
        }
    }

    function showGlobalLoading(message) {
        if (window.NFALoading && typeof window.NFALoading.show === 'function') {
            window.NFALoading.show(message || 'Processing…');
        }
    }

    function hideGlobalLoading() {
        if (window.NFALoading && typeof window.NFALoading.hide === 'function') {
            window.NFALoading.hide();
        }
    }

    // Lightweight confirm dialog using the existing modal overlay pattern
    function confirmAction({ title, message, confirmText, danger }) {
        const backdrop = document.getElementById('confirmModalBackdrop');
        const tEl = document.getElementById('confirmModalTitle');
        const mEl = document.getElementById('confirmModalMsg');
        const cancelBtn = document.getElementById('confirmModalCancel');
        const okBtn = document.getElementById('confirmModalOk');

        if (!backdrop || !cancelBtn || !okBtn) {
            return Promise.resolve(window.confirm(`${title}\n\n${message}`));
        }

        if (tEl) tEl.textContent = title || 'Confirm';
        if (mEl) mEl.textContent = message || 'Are you sure?';

        okBtn.textContent = confirmText || 'Confirm';
        okBtn.classList.toggle('btn-inline-danger', !!danger);
        okBtn.classList.toggle('btn-inline-success', !danger);

        openModal(backdrop);

        return new Promise((resolve) => {
            const cleanup = () => {
                cancelBtn.removeEventListener('click', onCancel);
                okBtn.removeEventListener('click', onOk);
                backdrop.removeEventListener('click', onBackdrop);
            };

            const onCancel = (e) => {
                e.preventDefault();
                cleanup();
                closeModal(backdrop);
                resolve(false);
            };

            const onOk = (e) => {
                e.preventDefault();
                cleanup();
                closeModal(backdrop);
                resolve(true);
            };

            const onBackdrop = (e) => {
                if (e.target === backdrop) onCancel(e);
            };

            cancelBtn.addEventListener('click', onCancel);
            okBtn.addEventListener('click', onOk);
            backdrop.addEventListener('click', onBackdrop);
        });
    }

    // Event delegation for tile buttons
    tiles.addEventListener('click', async function (e) {
        const btn = e.target.closest('button[data-action]');
        if (!btn) return;

        e.preventDefault();
        e.stopPropagation();

        const card = btn.closest('.appointment-card');
        const u = card ? card._user : null;
        if (!u) return;

        const userId = parseInt(u.user_id ?? '0', 10);
        if (!userId) return;

        const action = btn.dataset.action;

        try {
            if (action === 'view') {
                showDetails(u);
                return;
            }

            if (action === 'approve') {
                const name = [u.first_name, u.middle_name, u.last_name, u.suffix].filter(Boolean).join(' ') || u.username || 'this user';
                const ok = await confirmAction({
                    title: 'Approve Account',
                    message: `Approve ${name}? An email notification will be sent to the user.`,
                    confirmText: 'Approve',
                    danger: false
                });
                if (!ok) return;

                btn.disabled = true;
                showGlobalLoading('Approving account…');
                const resp = await postJson('adminApproveAccount', { user_id: userId });
                hideGlobalLoading();
                btn.disabled = false;
                if (!resp?.success) throw new Error(resp?.error || resp?.message || 'Approve failed');
                setNotice('Account approved.', 'success');
                await refresh();
                return;
            }

            if (action === 'reject') {
                const name = [u.first_name, u.middle_name, u.last_name, u.suffix].filter(Boolean).join(' ') || u.username || 'this user';
                const ok = await confirmAction({
                    title: 'Reject Account',
                    message: `Reject ${name}? The user will be notified via email.`,
                    confirmText: 'Reject',
                    danger: true
                });
                if (!ok) return;

                btn.disabled = true;
                showGlobalLoading('Rejecting account…');
                const resp = await postJson('adminRejectAccount', { user_id: userId });
                hideGlobalLoading();
                btn.disabled = false;
                if (!resp?.success) throw new Error(resp?.error || resp?.message || 'Reject failed');
                setNotice('Account rejected.', 'success');
                await refresh();
                return;
            }

            if (action === 'toggleActive') {
                const active = parseInt(u.is_active ?? 1, 10) === 1;
                const name = [u.first_name, u.middle_name, u.last_name, u.suffix].filter(Boolean).join(' ') || u.username || 'this user';
                const ok = await confirmAction({
                    title: active ? 'Deactivate Account' : 'Activate Account',
                    message: `${active ? 'Deactivate' : 'Activate'} ${name}? The user will be notified via email.`,
                    confirmText: active ? 'Deactivate' : 'Activate',
                    danger: active
                });
                if (!ok) return;

                btn.disabled = true;
                showGlobalLoading(active ? 'Deactivating account…' : 'Activating account…');
                const resp = await postJson('adminSetAccountActive', { user_id: userId, is_active: active ? 0 : 1 });
                hideGlobalLoading();
                btn.disabled = false;
                if (!resp?.success) throw new Error(resp?.error || resp?.message || 'Update failed');
                setNotice(`Account ${active ? 'deactivated' : 'activated'}.`, 'success');
                await refresh();
                return;
            }

            if (action === 'reassign') {
                await openReassign(u);
                return;
            }
        } catch (err) {
            hideGlobalLoading();
            btn.disabled = false;
            setNotice(err.message || 'Action failed.', 'error');
        }
    });

    // Filters
    if (statusSel) statusSel.addEventListener('change', refresh);
    if (includeInactive) includeInactive.addEventListener('change', refresh);
    if (qInput) qInput.addEventListener('input', debounce(refresh, 250));

    if (refreshBtn) {
        refreshBtn.addEventListener('click', (e) => {
            e.preventDefault();
            refresh();
        });
    }

    if (resetBtn) {
        resetBtn.addEventListener('click', (e) => {
            e.preventDefault();
            if (statusSel) statusSel.value = 'All';
            if (qInput) qInput.value = '';
            if (includeInactive) includeInactive.checked = false;
            url.searchParams.delete('view');
            window.history.replaceState({}, '', url.toString());
            refresh();
        });
    }

    // Modals
    if (detailsClose) detailsClose.addEventListener('click', () => closeModal(detailsBackdrop));
    if (detailsBackdrop) {
        detailsBackdrop.addEventListener('click', (e) => {
            if (e.target === detailsBackdrop) closeModal(detailsBackdrop);
        });
    }

    if (reassignClose) reassignClose.addEventListener('click', () => closeModal(reassignBackdrop));
    if (reassignCancel) reassignCancel.addEventListener('click', () => closeModal(reassignBackdrop));
    if (reassignBackdrop) {
        reassignBackdrop.addEventListener('click', (e) => {
            if (e.target === reassignBackdrop) closeModal(reassignBackdrop);
        });
    }

    document.addEventListener('keydown', (e) => {
        if (e.key !== 'Escape') return;
        if (detailsBackdrop && detailsBackdrop.style.display === 'flex') closeModal(detailsBackdrop);
        if (reassignBackdrop && reassignBackdrop.style.display === 'flex') closeModal(reassignBackdrop);
        if (confirmBackdrop && confirmBackdrop.style.display === 'flex') closeModal(confirmBackdrop);
    });

    if (confirmClose) {
        confirmClose.addEventListener('click', (e) => {
            e.preventDefault();
            closeModal(confirmBackdrop);
        });
    }

    if (reassignRegion) {
        reassignRegion.addEventListener('change', async function () {
            const regionId = parseInt(this.value || '0', 10);
            if (!regionId) {
                fillSelect(reassignBranch, [], 'branch_id', 'branch_name', 'Select Region First', null);
                reassignBranch.disabled = true;
                return;
            }

            reassignBranch.disabled = true;
            fillSelect(reassignBranch, [], 'branch_id', 'branch_name', 'Loading...', null);

            const branches = await getBranches(regionId);
            fillSelect(reassignBranch, branches, 'branch_id', 'branch_name', 'Select Branch', null);
            reassignBranch.disabled = false;
        });
    }

    if (reassignSubmit) {
        reassignSubmit.addEventListener('click', async function (e) {
            e.preventDefault();
            if (!selectedForReassign) return;

            const userId = parseInt(selectedForReassign.user_id ?? '0', 10);
            const regionId = parseInt(reassignRegion?.value || '0', 10);
            const branchId = parseInt(reassignBranch?.value || '0', 10);

            if (!userId || !regionId || !branchId) {
                setNotice('Select both region and branch.', 'error');
                return;
            }

            const resp = await postJson('adminReassignAccount', {
                user_id: userId,
                region_id: regionId,
                branch_id: branchId
            });

            if (!resp?.success) {
                setNotice(resp?.error || resp?.message || 'Failed to reassign.', 'error');
                return;
            }

            closeModal(reassignBackdrop);
            setNotice('Assignment updated.', 'success');
            await refresh();
        });
    }

    function debounce(fn, ms) {
        let t = null;
        return function () {
            window.clearTimeout(t);
            t = window.setTimeout(fn, ms);
        };
    }

    refresh();
});
