(function () {
    const qs = (s, r = document) => r.querySelector(s);

    let regionsCache = [];

    async function postJson(action, payload) {
        const res = await fetch(`php_helper/api.php?action=${encodeURIComponent(action)}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify(payload || {})
        });
        const json = await res.json().catch(() => null);
        if (!res.ok || !json) throw new Error(json?.error || 'Request failed');
        if (!json.success) throw new Error(json.error || 'Request failed');
        return json;
    }

    async function getJson(action) {
        const res = await fetch(`php_helper/api.php?action=${encodeURIComponent(action)}`, {
            cache: 'no-store',
            headers: { 'Accept': 'application/json' }
        });
        const json = await res.json().catch(() => null);
        if (!res.ok || !json) throw new Error(json?.error || 'Request failed');
        if (!json.success) throw new Error(json.error || 'Request failed');
        return json;
    }

    function setStatus(id, msg, isError) {
        const el = qs(id);
        if (!el) return;
        el.textContent = msg || '';
        el.classList.toggle('md-error', !!isError);
        el.classList.toggle('md-ok', !isError && !!msg);
    }

    function setSelectOptions(selectEl, options, { includeAll } = {}) {
        if (!selectEl) return;
        const current = String(selectEl.value || '0');
        selectEl.innerHTML = '';

        if (includeAll) {
            const optAll = document.createElement('option');
            optAll.value = '0';
            optAll.textContent = includeAll;
            selectEl.appendChild(optAll);
        } else {
            const opt = document.createElement('option');
            opt.value = '0';
            opt.textContent = 'Select region…';
            selectEl.appendChild(opt);
        }

        options.forEach(r => {
            const opt = document.createElement('option');
            opt.value = String(r.region_id);
            opt.textContent = String(r.region_name || '');
            selectEl.appendChild(opt);
        });

        // Restore selection if possible
        if (Array.from(selectEl.options).some(o => o.value === current)) {
            selectEl.value = current;
        }
    }

    function renderEditableRow({ id, name, idLabel, nameLabel, onSave, onDelete }) {
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td class="md-muted">${id}</td>
            <td>
                <span class="v" data-role="value"></span>
                <input class="edit" data-role="input" type="text" style="display:none; width:100%; padding:10px 12px; border-radius:12px; border:1px solid rgba(0,0,0,0.12);" />
            </td>
            <td style="text-align:right;">
                <div class="md-actions">
                    <button type="button" class="btn-outline" data-role="edit"><i class="fas fa-pen"></i></button>
                    <button type="button" class="btn-outline" data-role="save" style="display:none;"><i class="fas fa-save"></i></button>
                    <button type="button" class="btn-outline" data-role="cancel" style="display:none;"><i class="fas fa-xmark"></i></button>
                    <button type="button" class="btn-outline" data-role="delete"><i class="fas fa-trash"></i></button>
                </div>
            </td>
        `;

        const valueEl = tr.querySelector('[data-role="value"]');
        const inputEl = tr.querySelector('[data-role="input"]');
        const editBtn = tr.querySelector('[data-role="edit"]');
        const saveBtn = tr.querySelector('[data-role="save"]');
        const cancelBtn = tr.querySelector('[data-role="cancel"]');
        const delBtn = tr.querySelector('[data-role="delete"]');

        const setEditMode = (on) => {
            valueEl.style.display = on ? 'none' : '';
            inputEl.style.display = on ? '' : 'none';
            editBtn.style.display = on ? 'none' : '';
            saveBtn.style.display = on ? '' : 'none';
            cancelBtn.style.display = on ? '' : 'none';
        };

        const setName = (v) => {
            valueEl.textContent = v;
            inputEl.value = v;
        };

        setName(name);

        editBtn.addEventListener('click', () => {
            setEditMode(true);
            inputEl.focus();
            inputEl.select();
        });

        cancelBtn.addEventListener('click', () => {
            setEditMode(false);
            inputEl.value = valueEl.textContent;
        });

        saveBtn.addEventListener('click', async () => {
            const next = String(inputEl.value || '').trim();
            if (!next) {
                alert(`${nameLabel} is required.`);
                return;
            }
            try {
                saveBtn.disabled = true;
                await onSave(id, next);
                setName(next);
                setEditMode(false);
            } catch (e) {
                alert(e?.message || 'Save failed');
            } finally {
                saveBtn.disabled = false;
            }
        });

        inputEl.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') saveBtn.click();
            if (e.key === 'Escape') cancelBtn.click();
        });

        delBtn.addEventListener('click', async () => {
            if (!confirm(`Delete ${idLabel} #${id}? This cannot be undone.`)) return;
            try {
                delBtn.disabled = true;
                await onDelete(id);
                tr.remove();
            } catch (e) {
                alert(e?.message || 'Delete failed');
            } finally {
                delBtn.disabled = false;
            }
        });

        return tr;
    }

    async function loadRegions() {
        setStatus('#regionsStatus', 'Loading…', false);
        const tbody = qs('#regionsTbody');
        if (!tbody) return;
        tbody.innerHTML = `<tr><td colspan="3" class="md-muted">Loading…</td></tr>`;

        try {
            const json = await getJson('adminListRegions');
            const list = Array.isArray(json.data) ? json.data : [];
            regionsCache = list;
            qs('#regionsCount').textContent = String(list.length);

            // Update region selects used by Branches panel
            setSelectOptions(qs('#branchRegionSelect'), list);
            setSelectOptions(qs('#branchRegionFilter'), list, { includeAll: 'All regions' });

            tbody.innerHTML = '';
            if (!list.length) {
                tbody.innerHTML = `<tr><td colspan="3" class="md-muted">No regions found.</td></tr>`;
                setStatus('#regionsStatus', '', false);
                return;
            }

            list.forEach(r => {
                const id = Number(r.region_id || 0);
                const name = String(r.region_name || '');
                const tr = renderEditableRow({
                    id,
                    name,
                    idLabel: 'Region',
                    nameLabel: 'Region name',
                    onSave: async (regionId, regionName) => {
                        await postJson('adminUpdateRegion', { region_id: regionId, region_name: regionName });
                        setStatus('#regionsStatus', 'Saved', false);
                        setTimeout(() => setStatus('#regionsStatus', '', false), 1200);
                    },
                    onDelete: async (regionId) => {
                        await postJson('adminDeleteRegion', { region_id: regionId });
                        setStatus('#regionsStatus', 'Deleted', false);
                        setTimeout(() => setStatus('#regionsStatus', '', false), 1200);
                    }
                });
                tbody.appendChild(tr);
            });

            setStatus('#regionsStatus', '', false);
        } catch (e) {
            tbody.innerHTML = `<tr><td colspan="3" class="md-muted">Failed to load.</td></tr>`;
            setStatus('#regionsStatus', e?.message || 'Failed to load', true);
        }
    }

    function renderBranchRow({ branchId, regionName, branchName, onSave, onDelete }) {
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td class="md-muted">${branchId}</td>
            <td class="md-muted">${regionName}</td>
            <td>
                <span class="v" data-role="value"></span>
                <input class="edit" data-role="input" type="text" style="display:none; width:100%; padding:10px 12px; border-radius:12px; border:1px solid rgba(0,0,0,0.12);" />
            </td>
            <td style="text-align:right;">
                <div class="md-actions">
                    <button type="button" class="btn-outline" data-role="edit"><i class="fas fa-pen"></i></button>
                    <button type="button" class="btn-outline" data-role="save" style="display:none;"><i class="fas fa-save"></i></button>
                    <button type="button" class="btn-outline" data-role="cancel" style="display:none;"><i class="fas fa-xmark"></i></button>
                    <button type="button" class="btn-outline" data-role="delete"><i class="fas fa-trash"></i></button>
                </div>
            </td>
        `;

        const valueEl = tr.querySelector('[data-role="value"]');
        const inputEl = tr.querySelector('[data-role="input"]');
        const editBtn = tr.querySelector('[data-role="edit"]');
        const saveBtn = tr.querySelector('[data-role="save"]');
        const cancelBtn = tr.querySelector('[data-role="cancel"]');
        const delBtn = tr.querySelector('[data-role="delete"]');

        const setEditMode = (on) => {
            valueEl.style.display = on ? 'none' : '';
            inputEl.style.display = on ? '' : 'none';
            editBtn.style.display = on ? 'none' : '';
            saveBtn.style.display = on ? '' : 'none';
            cancelBtn.style.display = on ? '' : 'none';
        };

        const setName = (v) => {
            valueEl.textContent = v;
            inputEl.value = v;
        };

        setName(branchName);

        editBtn.addEventListener('click', () => {
            setEditMode(true);
            inputEl.focus();
            inputEl.select();
        });

        cancelBtn.addEventListener('click', () => {
            setEditMode(false);
            inputEl.value = valueEl.textContent;
        });

        saveBtn.addEventListener('click', async () => {
            const next = String(inputEl.value || '').trim();
            if (!next) {
                alert('Branch name is required.');
                return;
            }
            try {
                saveBtn.disabled = true;
                await onSave(branchId, next);
                setName(next);
                setEditMode(false);
            } catch (e) {
                alert(e?.message || 'Save failed');
            } finally {
                saveBtn.disabled = false;
            }
        });

        inputEl.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') saveBtn.click();
            if (e.key === 'Escape') cancelBtn.click();
        });

        delBtn.addEventListener('click', async () => {
            if (!confirm(`Delete branch #${branchId}? This cannot be undone.`)) return;
            try {
                delBtn.disabled = true;
                await onDelete(branchId);
                tr.remove();
            } catch (e) {
                alert(e?.message || 'Delete failed');
            } finally {
                delBtn.disabled = false;
            }
        });

        return tr;
    }

    async function loadBranches() {
        const tbody = qs('#branchesTbody');
        if (!tbody) return;

        setStatus('#branchesStatus', 'Loading…', false);
        tbody.innerHTML = `<tr><td colspan="4" class="md-muted">Loading…</td></tr>`;

        try {
            const filter = parseInt(qs('#branchRegionFilter')?.value || '0', 10) || 0;
            const url = new URL('php_helper/api.php', window.location.href);
            url.searchParams.set('action', 'adminListBranches');
            if (filter > 0) url.searchParams.set('region_id', String(filter));
            const res = await fetch(url.toString(), { cache: 'no-store', headers: { 'Accept': 'application/json' } });
            const json = await res.json().catch(() => null);
            if (!res.ok || !json || !json.success) throw new Error(json?.error || 'Failed to load branches');
            const list = Array.isArray(json.data) ? json.data : [];
            qs('#branchesCount').textContent = String(list.length);

            tbody.innerHTML = '';
            if (!list.length) {
                tbody.innerHTML = `<tr><td colspan="4" class="md-muted">No branches found.</td></tr>`;
                setStatus('#branchesStatus', '', false);
                return;
            }

            list.forEach(b => {
                const branchId = Number(b.branch_id || 0);
                const regionName = String(b.region_name || '');
                const branchName = String(b.branch_name || '');

                const tr = renderBranchRow({
                    branchId,
                    regionName,
                    branchName,
                    onSave: async (id, name) => {
                        await postJson('adminUpdateBranch', { branch_id: id, branch_name: name });
                        setStatus('#branchesStatus', 'Saved', false);
                        setTimeout(() => setStatus('#branchesStatus', '', false), 1200);
                    },
                    onDelete: async (id) => {
                        await postJson('adminDeleteBranch', { branch_id: id });
                        setStatus('#branchesStatus', 'Deleted', false);
                        setTimeout(() => setStatus('#branchesStatus', '', false), 1200);
                    }
                });
                tbody.appendChild(tr);
            });

            setStatus('#branchesStatus', '', false);
        } catch (e) {
            tbody.innerHTML = `<tr><td colspan="4" class="md-muted">Failed to load.</td></tr>`;
            setStatus('#branchesStatus', e?.message || 'Failed to load', true);
        }
    }

    async function loadTypes() {
        setStatus('#typesStatus', 'Loading…', false);
        const tbody = qs('#typesTbody');
        if (!tbody) return;
        tbody.innerHTML = `<tr><td colspan="3" class="md-muted">Loading…</td></tr>`;

        try {
            const json = await getJson('adminListFarmerTypes');
            const list = Array.isArray(json.data) ? json.data : [];
            qs('#typesCount').textContent = String(list.length);

            tbody.innerHTML = '';
            if (!list.length) {
                tbody.innerHTML = `<tr><td colspan="3" class="md-muted">No farmer types found.</td></tr>`;
                setStatus('#typesStatus', '', false);
                return;
            }

            list.forEach(r => {
                const id = Number(r.farmer_type_id || 0);
                const name = String(r.type_name || '');
                const tr = renderEditableRow({
                    id,
                    name,
                    idLabel: 'Farmer type',
                    nameLabel: 'Type name',
                    onSave: async (typeId, typeName) => {
                        await postJson('adminUpdateFarmerType', { farmer_type_id: typeId, type_name: typeName });
                        setStatus('#typesStatus', 'Saved', false);
                        setTimeout(() => setStatus('#typesStatus', '', false), 1200);
                    },
                    onDelete: async (typeId) => {
                        await postJson('adminDeleteFarmerType', { farmer_type_id: typeId });
                        setStatus('#typesStatus', 'Deleted', false);
                        setTimeout(() => setStatus('#typesStatus', '', false), 1200);
                    }
                });
                tbody.appendChild(tr);
            });

            setStatus('#typesStatus', '', false);
        } catch (e) {
            tbody.innerHTML = `<tr><td colspan="3" class="md-muted">Failed to load.</td></tr>`;
            setStatus('#typesStatus', e?.message || 'Failed to load', true);
        }
    }

    async function addRegion() {
        const input = qs('#newRegionName');
        const branchInput = qs('#newRegionBranchName');
        const btn = qs('#addRegionBtn');
        const name = String(input?.value || '').trim();
        const branchName = String(branchInput?.value || '').trim();
        if (!name) return;
        if (!branchName) {
            setStatus('#regionsStatus', 'Initial branch name is required.', true);
            return;
        }
        try {
            btn.disabled = true;
            await postJson('adminCreateRegion', { region_name: name, initial_branch_name: branchName });
            input.value = '';
            if (branchInput) branchInput.value = '';
            await loadRegions();
            await loadBranches();
            setStatus('#regionsStatus', 'Added', false);
            setTimeout(() => setStatus('#regionsStatus', '', false), 1200);
        } catch (e) {
            setStatus('#regionsStatus', e?.message || 'Failed to add', true);
        } finally {
            btn.disabled = false;
        }
    }

    async function addBranch() {
        const sel = qs('#branchRegionSelect');
        const input = qs('#newBranchName');
        const btn = qs('#addBranchBtn');
        const regionId = parseInt(sel?.value || '0', 10) || 0;
        const name = String(input?.value || '').trim();

        if (regionId <= 0) {
            setStatus('#branchesStatus', 'Select a region first.', true);
            return;
        }
        if (!name) return;

        try {
            btn.disabled = true;
            await postJson('adminCreateBranch', { region_id: regionId, branch_name: name });
            input.value = '';
            // Keep filter in sync with selected region
            const filter = qs('#branchRegionFilter');
            if (filter) filter.value = String(regionId);
            await loadBranches();
            setStatus('#branchesStatus', 'Added', false);
            setTimeout(() => setStatus('#branchesStatus', '', false), 1200);
        } catch (e) {
            setStatus('#branchesStatus', e?.message || 'Failed to add', true);
        } finally {
            btn.disabled = false;
        }
    }

    async function addType() {
        const input = qs('#newTypeName');
        const btn = qs('#addTypeBtn');
        const name = String(input?.value || '').trim();
        if (!name) return;
        try {
            btn.disabled = true;
            await postJson('adminCreateFarmerType', { type_name: name });
            input.value = '';
            await loadTypes();
            setStatus('#typesStatus', 'Added', false);
            setTimeout(() => setStatus('#typesStatus', '', false), 1200);
        } catch (e) {
            setStatus('#typesStatus', e?.message || 'Failed to add', true);
        } finally {
            btn.disabled = false;
        }
    }

    document.addEventListener('DOMContentLoaded', () => {
        qs('#addRegionBtn')?.addEventListener('click', addRegion);
        qs('#addTypeBtn')?.addEventListener('click', addType);
        qs('#addBranchBtn')?.addEventListener('click', addBranch);
        qs('#refreshBranchesBtn')?.addEventListener('click', loadBranches);
        qs('#branchRegionFilter')?.addEventListener('change', loadBranches);

        qs('#newRegionName')?.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') addRegion();
        });
        qs('#newRegionBranchName')?.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') addRegion();
        });
        qs('#newTypeName')?.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') addType();
        });
        qs('#newBranchName')?.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') addBranch();
        });

        loadRegions();
        loadTypes();
        loadBranches();
    });
})();
