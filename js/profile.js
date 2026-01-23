(function () {
    const apiBase = 'php_helper/api.php';

    const qs = (sel) => document.querySelector(sel);
    const qsa = (sel) => Array.from(document.querySelectorAll(sel));

    function notify(message, type = 'info') {
        if (typeof window.showToast === 'function') {
            window.showToast(message, type);
            return;
        }
        alert(message);
    }

    function validateEmailFormat(email) {
        return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(email || '').trim());
    }

    function normalizeContactNumber(contact) {
        const digits = String(contact || '').replace(/\D/g, '');
        if (/^639\d{9}$/.test(digits)) return '0' + digits.slice(2);
        if (/^09\d{9}$/.test(digits)) return digits;
        return String(contact || '').trim();
    }

    function validateContactFormat(contact) {
        const digits = String(contact || '').replace(/\D/g, '');
        return /^09\d{9}$/.test(digits) || /^639\d{9}$/.test(digits);
    }

    function setFormDisabled(form, disabled) {
        qsa('#profileForm input, #profileForm select').forEach((el) => {
            if (el.hasAttribute('data-readonly')) return;
            if (el.disabled === true && !disabled) {
                el.disabled = false;
                return;
            }
            if (disabled) el.disabled = true;
        });
    }

    const btnEdit = qs('#btnEditProfile');
    const btnSave = qs('#btnSaveProfile');
    const btnCancel = qs('#btnCancelEdit');
    const profileForm = qs('#profileForm');

    const initialValues = {};
    qsa('#profileForm input, #profileForm select').forEach((el) => {
        if (!el.name) return;
        initialValues[el.name] = el.value;
    });

    function enterEditMode() {
        btnEdit.classList.add('hidden');
        btnSave.classList.remove('hidden');
        btnCancel.classList.remove('hidden');

        // Enable only editable fields
        qsa('#profileForm input, #profileForm select').forEach((el) => {
            const name = el.getAttribute('name');
            const editable = ['first_name', 'middle_name', 'last_name', 'suffix', 'email_address', 'contact_number', 'gender'];
            if (editable.includes(name)) {
                el.disabled = false;
            }
        });
    }

    function exitEditMode(reset = false) {
        btnEdit.classList.remove('hidden');
        btnSave.classList.add('hidden');
        btnCancel.classList.add('hidden');

        qsa('#profileForm input, #profileForm select').forEach((el) => {
            el.disabled = true;
            if (reset && el.name && Object.prototype.hasOwnProperty.call(initialValues, el.name)) {
                el.value = initialValues[el.name];
            }
        });
    }

    async function saveProfile() {
        const payload = {
            first_name: qs('#first_name')?.value?.trim() || '',
            middle_name: qs('#middle_name')?.value?.trim() || '',
            last_name: qs('#last_name')?.value?.trim() || '',
            suffix: qs('#suffix')?.value?.trim() || '',
            email_address: qs('#email_address')?.value?.trim() || '',
            contact_number: normalizeContactNumber(qs('#contact_number')?.value || ''),
            gender: (qs('#gender')?.value || '').trim()
        };

        if (!payload.first_name || !payload.middle_name || !payload.last_name || !payload.email_address || !payload.contact_number || !payload.gender) {
            notify('Please complete all required fields.', 'warning');
            return;
        }
        if (!validateEmailFormat(payload.email_address)) {
            notify('Please enter a valid email.', 'warning');
            return;
        }
        if (!validateContactFormat(payload.contact_number)) {
            notify('Please enter a valid contact number (09XXXXXXXXX or +639XXXXXXXXX).', 'warning');
            return;
        }
        if (!['male', 'female', 'other'].includes(payload.gender)) {
            notify('Please select a valid gender.', 'warning');
            return;
        }

        const res = await fetch(`${apiBase}?action=updateMyProfile`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });

        const data = await res.json();
        if (!data.success) throw new Error(data.error || 'Failed');

        // Update form + initial snapshot
        qs('#contact_number').value = payload.contact_number;
        Object.keys(payload).forEach((k) => { initialValues[k] = payload[k]; });

        notify('Profile updated successfully.', 'success');
        exitEditMode(false);
    }

    // Profile actions
    btnEdit?.addEventListener('click', enterEditMode);
    btnCancel?.addEventListener('click', () => exitEditMode(true));
    btnSave?.addEventListener('click', async () => {
        btnSave.disabled = true;
        try {
            await saveProfile();
        } catch (e) {
            notify(e?.message || 'Failed to update profile', 'error');
        } finally {
            btnSave.disabled = false;
        }
    });

    // Password change
    const passwordForm = qs('#passwordForm');
    passwordForm?.addEventListener('submit', async (e) => {
        e.preventDefault();

        const current_password = qs('#current_password')?.value || '';
        const new_password = qs('#new_password')?.value || '';
        const confirm_password = qs('#confirm_password')?.value || '';

        if (!current_password || !new_password || !confirm_password) {
            notify('Please complete all password fields.', 'warning');
            return;
        }
        if (new_password.length < 8) {
            notify('New password must be at least 8 characters.', 'warning');
            return;
        }
        if (new_password !== confirm_password) {
            notify('New passwords do not match.', 'warning');
            return;
        }

        const btn = qs('#btnChangePassword');
        if (btn) btn.disabled = true;
        try {
            const resp = await fetch(`${apiBase}?action=changeMyPassword`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ current_password, new_password })
            });
            const data = await resp.json();
            if (!data.success) throw new Error(data.error || 'Failed');

            qs('#current_password').value = '';
            qs('#new_password').value = '';
            qs('#confirm_password').value = '';

            notify('Password changed successfully.', 'success');
        } catch (err) {
            notify(err?.message || 'Failed to change password', 'error');
        } finally {
            if (btn) btn.disabled = false;
        }
    });

    // Normalize contact field on blur
    const contactEl = qs('#contact_number');
    contactEl?.addEventListener('blur', () => {
        if (contactEl.disabled) return;
        contactEl.value = normalizeContactNumber(contactEl.value);
    });
})();
