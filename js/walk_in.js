(function () {
    const apiBase = 'php_helper/api.php';

    const qs = (sel) => document.querySelector(sel);
    const qsa = (sel) => Array.from(document.querySelectorAll(sel));

    const form = qs('#walkinForm');
    const methodButtons = qsa('.walkin-method');
    const lookupBox = qs('#walkinLookup');
    const lookupFarmerId = qs('#lookupFarmerId');
    const btnLookup = qs('#btnLookup');

    const confirmModal = qs('#walkinConfirmModal');
    const confirmBody = qs('#confirmBody');
    const confirmOkBtn = qs('#confirmOkBtn');
    const confirmCancelBtn = qs('#confirmCancelBtn');
    const confirmCloseBtn = qs('#confirmCloseBtn');

    const btnClose = qs('#btnClose');

    let pendingPayload = null;
    let isSaving = false;

    function validateEmailFormat(email) {
        return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(email || '').trim());
    }

    function normalizeContactNumber(contact) {
        const digits = String(contact || '').replace(/\D/g, '');
        if (/^639\d{9}$/.test(digits)) {
            return '0' + digits.slice(2);
        }
        if (/^09\d{9}$/.test(digits)) {
            return digits;
        }
        return String(contact || '').trim();
    }

    function validateContactFormat(contact) {
        const digits = String(contact || '').replace(/\D/g, '');
        return /^09\d{9}$/.test(digits) || /^639\d{9}$/.test(digits);
    }

    function setFieldError(field, message) {
        if (!field) return;
        try {
            field.setCustomValidity(message || 'Invalid');
            field.reportValidity();
        } catch (_) {
            // ignore
        }
    }

    function clearFieldError(field) {
        if (!field) return;
        try {
            field.setCustomValidity('');
        } catch (_) {
            // ignore
        }
    }

    function notify(message, type = 'info') {
        if (typeof window.showToast === 'function') {
            window.showToast(message, type);
            return;
        }
        alert(message);
    }

    function openConfirm(html, payload) {
        pendingPayload = payload;
        confirmBody.innerHTML = html;
        confirmModal.classList.add('active');
    }

    function closeConfirm() {
        confirmModal.classList.remove('active');
        pendingPayload = null;
    }

    function serializeForm() {
        const data = new FormData(form);
        const obj = {};
        for (const [k, v] of data.entries()) {
            obj[k] = typeof v === 'string' ? v.trim() : v;
        }
        if (obj.volume !== undefined) {
            obj.volume = Number(obj.volume);
        }
        if (obj.farmer_type_id !== undefined) {
            obj.farmer_type_id = Number(obj.farmer_type_id);
        }

        if (obj.contact_number !== undefined) {
            obj.contact_number = normalizeContactNumber(obj.contact_number);
        }
        return obj;
    }

    function validateRequired(obj) {
        const required = [
            'date',
            'time_slot',
            'volume',
            'farmer_id',
            'farmer_type_id',
            'gender',
            'first_name',
            'middle_name',
            'last_name',
            'email',
            'contact_number'
        ];
        for (const key of required) {
            const val = obj[key];
            if (val === undefined || val === null || String(val).trim() === '') {
                return `Missing: ${key.replaceAll('_', ' ')}`;
            }
        }
        if (!['AM', 'PM'].includes(obj.time_slot)) return 'Invalid session';
        if (!Number.isFinite(obj.volume) || obj.volume <= 0) return 'Invalid volume';
        if (!Number.isFinite(obj.farmer_type_id) || obj.farmer_type_id <= 0) return 'Invalid farmer type';
        if (!validateEmailFormat(obj.email)) return 'Invalid email';
        if (!validateContactFormat(obj.contact_number)) return 'Invalid contact number (use 09XXXXXXXXX or +639XXXXXXXXX)';
        if (!/^\d{4}-\d{2}-\d{2}$/.test(obj.date)) return 'Invalid date';
        return null;
    }

    async function loadFarmerTypes() {
        const el = qs('#farmer_type_id');
        try {
            const resp = await fetch(`${apiBase}?action=getFarmerTypes`);
            const data = await resp.json();
            const types = Array.isArray(data?.farmerTypes) ? data.farmerTypes : (Array.isArray(data?.data) ? data.data : null);
            if (!data.success || !Array.isArray(types)) {
                throw new Error(data?.error || 'Failed to load farmer types');
            }

            el.innerHTML = '<option value="">Select</option>';
            for (const t of types) {
                const opt = document.createElement('option');
                opt.value = String(t.farmer_type_id);
                opt.textContent = t.type_name;
                el.appendChild(opt);
            }
        } catch (e) {
            el.innerHTML = '<option value="">(Failed to load)</option>';
            notify(e?.message || 'Failed to load farmer types', 'error');
        }
    }

    function setMethod(method) {
        methodButtons.forEach(b => b.classList.toggle('active', b.dataset.method === method));
        lookupBox.classList.toggle('hidden', method !== 'lookup');
    }

    async function lookup() {
        const id = (lookupFarmerId.value || '').trim();
        if (!id) {
            notify('Enter Farmer ID to lookup', 'warning');
            return;
        }
        btnLookup.disabled = true;
        try {
            const resp = await fetch(`${apiBase}?action=lookupFarmerById&farmer_id=${encodeURIComponent(id)}`);
            const data = await resp.json();
            if (!data.success) throw new Error(data.error || 'Lookup failed');
            if (!data.found) {
                notify('No previous record found for that Farmer ID.', 'info');
                return;
            }

            const f = data.farmer;
            qs('#farmer_id').value = f.farmer_id || id;
            qs('#first_name').value = f.first_name || '';
            qs('#middle_name').value = f.middle_name || '';
            qs('#last_name').value = f.last_name || '';
            qs('#suffix').value = f.suffix || '';
            qs('#email').value = f.email || '';
            qs('#contact_number').value = f.contact_number || '';

            const gender = (f.gender || '').toLowerCase();
            if (['male', 'female', 'other'].includes(gender)) {
                qs('#gender').value = gender;
            }

            if (Number(f.farmer_type_id) > 0) {
                qs('#farmer_type_id').value = String(f.farmer_type_id);
            }

            notify('Farmer details filled from latest record.', 'success');
        } catch (e) {
            notify('Lookup failed. Please try manual entry.', 'error');
        } finally {
            btnLookup.disabled = false;
        }
    }

    async function submitWalkIn(payload) {
        const resp = await fetch(`${apiBase}?action=createWalkInAppointment`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const data = await resp.json();
        if (!data.success) {
            throw new Error(data.error || 'Failed');
        }
        return data;
    }

    function confirmHtml(obj) {
        const safe = (s) => String(s || '').replace(/[&<>"']/g, (c) => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        }[c]));

        return `
            <div class="walkin-confirm-layout">
                <div class="walkin-confirm-aside">
                    <p class="walkin-confirm-lead">Confirm saving this walk-in record:</p>
                    <p class="walkin-confirm-note"><strong>Tip:</strong> Click “Confirm & Save” to write this to the database and update the branch inventory.</p>
                </div>
                <div class="walkin-summary">
                    <div class="row"><div class="k">Farmer</div><div class="v">${safe(obj.first_name)} ${safe(obj.middle_name)} ${safe(obj.last_name)} ${safe(obj.suffix)}</div></div>
                    <div class="row"><div class="k">Farmer ID</div><div class="v">${safe(obj.farmer_id)}</div></div>
                    <div class="row"><div class="k">Date / Session</div><div class="v">${safe(obj.date)} (${safe(obj.time_slot)})</div></div>
                    <div class="row"><div class="k">Volume</div><div class="v">${safe(obj.volume)} bags</div></div>
                    <div class="row"><div class="k">Mode</div><div class="v">walk-in</div></div>
                    <div class="row"><div class="k">Status</div><div class="v">completed</div></div>
                </div>
            </div>
        `;
    }

    // Wire events
    methodButtons.forEach(btn => {
        btn.addEventListener('click', () => setMethod(btn.dataset.method));
    });

    if (btnLookup) btnLookup.addEventListener('click', lookup);

    if (lookupFarmerId) {
        lookupFarmerId.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                lookup();
            }
        });
    }

    form.addEventListener('submit', (e) => {
        e.preventDefault();
        // Clear any existing browser validity messages
        ['email', 'contact_number'].forEach((id) => clearFieldError(qs(`#${id}`)));

        const obj = serializeForm();
        const err = validateRequired(obj);
        if (err) {
            // Prefer field-level messaging for the most common errors
            if (String(err).toLowerCase().includes('email')) {
                setFieldError(qs('#email'), 'Please enter a valid email (e.g., name@gmail.com).');
            } else if (String(err).toLowerCase().includes('contact')) {
                setFieldError(qs('#contact_number'), 'Please enter a valid PH mobile number (09XXXXXXXXX or +639XXXXXXXXX).');
            } else {
                notify(err, 'warning');
            }
            return;
        }
        openConfirm(confirmHtml(obj), obj);
    });

    confirmCancelBtn.addEventListener('click', closeConfirm);
    confirmCloseBtn.addEventListener('click', closeConfirm);

    confirmOkBtn.addEventListener('click', async () => {
        if (!pendingPayload || isSaving) return;
        isSaving = true;
        confirmOkBtn.disabled = true;
        try {
            const res = await submitWalkIn(pendingPayload);
            closeConfirm();

            const inv = (res && typeof res.inventory === 'number') ? res.inventory : null;
            const msg = inv !== null
                ? `Walk-in recorded. Branch inventory updated to ${inv} bags.`
                : 'Walk-in recorded successfully.';
            notify(msg, 'success');

            const apptId = res.appointment_id;
            const targetUrl = `appointments.php?view=${encodeURIComponent(apptId)}`;

            // Prefer returning focus to the dashboard and jumping to the appointment
            if (window.opener && !window.opener.closed) {
                try {
                    window.opener.location.href = targetUrl;
                    window.opener.focus();
                    window.close();
                    return;
                } catch (_) {
                    // ignore
                }
            }

            // Fallback: navigate within this tab
            window.location.href = targetUrl;
        } catch (e) {
            notify(e.message || 'Failed to save walk-in', 'error');
        } finally {
            confirmOkBtn.disabled = false;
            isSaving = false;
        }
    });

    btnClose.addEventListener('click', () => {
        try {
            if (window.opener && !window.opener.closed) {
                window.opener.focus();
            }
        } catch (_) {
            // ignore
        }

        // Attempt to close this popup/tab
        window.close();

        // Fallback if the browser blocks window.close()
        setTimeout(() => {
            // If we're still here, navigate back to dashboard
            window.location.href = 'processor_dashboard.php';
        }, 250);
    });

    // Modal click-outside to close
    confirmModal.addEventListener('click', (e) => {
        if (e.target === confirmModal) {
            closeConfirm();
        }
    });

    // init
    setMethod('manual');
    loadFarmerTypes();

    // Live validation for better UX
    const emailEl = qs('#email');
    const contactEl = qs('#contact_number');

    if (emailEl) {
        emailEl.addEventListener('blur', () => {
            const v = (emailEl.value || '').trim();
            if (v && !validateEmailFormat(v)) {
                setFieldError(emailEl, 'Please enter a valid email (e.g., name@gmail.com).');
            } else {
                clearFieldError(emailEl);
            }
        });
        emailEl.addEventListener('input', () => clearFieldError(emailEl));
    }

    if (contactEl) {
        contactEl.addEventListener('blur', () => {
            const v = (contactEl.value || '').trim();
            if (v && !validateContactFormat(v)) {
                setFieldError(contactEl, 'Use 09XXXXXXXXX or +639XXXXXXXXX.');
                return;
            }
            contactEl.value = normalizeContactNumber(contactEl.value);
            clearFieldError(contactEl);
        });
        contactEl.addEventListener('input', () => clearFieldError(contactEl));
    }
})();
