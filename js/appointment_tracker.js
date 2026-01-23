document.addEventListener('DOMContentLoaded', () => {
    const apiBase = 'php_helper/api.php';

    const form = document.getElementById('trackForm');
    const refInput = document.getElementById('referenceInput');
    const farmerIdInput = document.getElementById('farmerIdInput');
    const emailInput = document.getElementById('emailInput');

    const btnTrack = document.getElementById('trackBtn');
    const btnClear = document.getElementById('clearBtn');
    const btnPaste = document.getElementById('pasteBtn');

    const alertBox = document.getElementById('alertBox');
    const results = document.getElementById('results');

    const statusPill = document.getElementById('statusPill');
    const statusNote = document.getElementById('statusNote');

    const refValue = document.getElementById('refValue');
    const branchValue = document.getElementById('branchValue');
    const regionValue = document.getElementById('regionValue');
    const dateValue = document.getElementById('dateValue');
    const slotValue = document.getElementById('slotValue');
    const volumeValue = document.getElementById('volumeValue');
    const farmerValue = document.getElementById('farmerValue');

    const btnCopyRef = document.getElementById('copyRefBtn');
    const btnPrint = document.getElementById('printBtn');

    const btnConfirmRescheduled = document.getElementById('confirmRescheduledBtn');
    const btnCancelAppointment = document.getElementById('cancelAppointmentBtn');

    const cancelModal = document.getElementById('cancelModal');
    const cancelModalClose = document.getElementById('cancelModalClose');
    const cancelModalBack = document.getElementById('cancelModalBack');
    const cancelForm = document.getElementById('cancelForm');
    const cancelDetails = document.getElementById('cancelDetails');
    const cancelFormAlert = document.getElementById('cancelFormAlert');
    const cancelSubmitBtn = document.getElementById('cancelSubmitBtn');

    const confirmModal = document.getElementById('confirmModal');
    const confirmModalClose = document.getElementById('confirmModalClose');
    const confirmMessage = document.getElementById('confirmMessage');
    const confirmMeta = document.getElementById('confirmMeta');
    const confirmModalAlert = document.getElementById('confirmModalAlert');
    const confirmCancelBtn = document.getElementById('confirmCancelBtn');
    const confirmOkBtn = document.getElementById('confirmOkBtn');

    let lastLoaded = null;
    let confirmAction = null;

    const showAlert = (message, type = 'error') => {
        if (!alertBox) return;
        if (!message) {
            alertBox.style.display = 'none';
            alertBox.textContent = '';
            alertBox.classList.remove('success');
            return;
        }
        alertBox.textContent = message;
        alertBox.style.display = 'block';
        if (type === 'success') alertBox.classList.add('success');
        else alertBox.classList.remove('success');
    };

    const setLoading = (isLoading) => {
        if (btnTrack) {
            btnTrack.disabled = isLoading;
            btnTrack.innerHTML = isLoading
                ? '<i class="fas fa-spinner fa-spin"></i> Tracking…'
                : '<i class="fas fa-magnifying-glass"></i> Track Appointment';
        }
        if (btnClear) btnClear.disabled = isLoading;
        if (btnPaste) btnPaste.disabled = isLoading;
    };

    const showInlineAlert = (el, message, type = 'error') => {
        if (!el) return;
        if (!message) {
            el.style.display = 'none';
            el.textContent = '';
            el.classList.remove('success');
            return;
        }
        el.textContent = message;
        el.style.display = 'block';
        if (type === 'success') el.classList.add('success');
        else el.classList.remove('success');
    };

    const openModal = (overlayEl) => {
        if (!overlayEl) return;
        overlayEl.style.display = 'flex';
        overlayEl.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
    };

    const closeModal = (overlayEl) => {
        if (!overlayEl) return;
        overlayEl.style.display = 'none';
        overlayEl.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
    };

    const needsIdentity = () => {
        const farmerId = String(farmerIdInput?.value || '').trim();
        const email = String(emailInput?.value || '').trim();
        return !(farmerId || email);
    };

    const postJson = async (action, payload) => {
        const url = new URL(apiBase, window.location.href);
        url.searchParams.set('action', action);
        const resp = await fetch(url.toString(), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload || {})
        });
        const json = await resp.json().catch(() => null);
        if (!resp.ok || !json || !json.success) {
            const msg = (json && json.error) ? json.error : 'Request failed.';
            throw new Error(msg);
        }
        return json;
    };

    const verifyIdentityNow = async () => {
        const payload = getIdentityPayload();
        await postJson('verifyTrackerIdentity', payload);
    };

    const setActionButtons = (status) => {
        const s = String(status || '').toLowerCase();
        const cancelled = s === 'cancelled' || s === 'canceled';
        const completed = s === 'completed';

        if (btnCancelAppointment) {
            btnCancelAppointment.style.display = (lastLoaded ? 'inline-flex' : 'none');
            btnCancelAppointment.disabled = cancelled || completed;
            btnCancelAppointment.title = completed
                ? 'Completed appointments cannot be cancelled.'
                : (cancelled ? 'This appointment is already cancelled.' : '');
        }

        if (btnConfirmRescheduled) {
            btnConfirmRescheduled.style.display = (s === 'rescheduled') ? 'inline-flex' : 'none';
            btnConfirmRescheduled.disabled = false;
        }
    };

    const normalizeRef = (value) => {
        return String(value || '').trim().replace(/\s+/g, '').toUpperCase();
    };

    const isValidReference = (value) => {
        // Current system reference format: NFA + YYYYMMDD + 6 alnum
        return /^NFA\d{8}[A-Z0-9]{6}$/.test(value);
    };

    const statusLabel = (status) => {
        switch (status) {
            case 'pending': return 'Pending';
            case 'confirmed': return 'Confirmed';
            case 'rescheduled': return 'Rescheduled';
            case 'completed': return 'Completed';
            case 'cancelled':
            case 'canceled': return 'Cancelled';
            default: return 'Unknown';
        }
    };

    const statusSubtext = (status) => {
        switch (status) {
            case 'pending': return 'Your request is received and waiting for review.';
            case 'confirmed': return 'Your appointment is approved. Please proceed on your scheduled date.';
            case 'rescheduled': return 'Your appointment schedule was updated by staff. Please check your updated date and time.';
            case 'completed': return 'Delivery has been recorded by the branch.';
            case 'cancelled':
            case 'canceled': return 'This appointment was cancelled. You may schedule again.';
            default: return 'Status is unavailable.';
        }
    };

    const formatDate = (isoYmd) => {
        if (!isoYmd) return '—';
        const d = new Date(isoYmd + 'T00:00:00');
        if (Number.isNaN(d.getTime())) return isoYmd;
        return d.toLocaleDateString(undefined, { year: 'numeric', month: 'long', day: 'numeric' });
    };

    const slotLabel = (slot) => {
        const s = String(slot || '').toUpperCase();
        return s === 'PM' ? 'Afternoon (1:00 PM – 5:00 PM)' : 'Morning (8:00 AM – 12:00 NN)';
    };

    const markTimeline = (status) => {
        const submittedEl = document.querySelector('.timeline-step[data-step="submitted"]');
        const confirmedEl = document.querySelector('.timeline-step[data-step="confirmed"]');
        const completedEl = document.querySelector('.timeline-step[data-step="completed"]');

        const clear = (el) => {
            if (!el) return;
            el.classList.remove('done', 'current', 'blocked');
        };

        [submittedEl, confirmedEl, completedEl].forEach(clear);

        const s = String(status || '').toLowerCase();
        const cancelled = s === 'cancelled' || s === 'canceled';

        if (submittedEl) submittedEl.classList.add('done');

        if (cancelled) {
            if (confirmedEl) confirmedEl.classList.add('blocked');
            if (completedEl) completedEl.classList.add('blocked');
            return;
        }

        if (s === 'pending') {
            if (submittedEl) {
                submittedEl.classList.remove('done');
                submittedEl.classList.add('current');
            }
            return;
        }

        if (s === 'rescheduled') {
            if (confirmedEl) confirmedEl.classList.add('current');
            return;
        }

        if (s === 'confirmed') {
            if (confirmedEl) confirmedEl.classList.add('current');
            return;
        }

        if (s === 'completed') {
            if (confirmedEl) confirmedEl.classList.add('done');
            if (completedEl) completedEl.classList.add('current');
        }
    };

    const renderResult = (data) => {
        lastLoaded = data || null;
        const s = String(data.status || '').toLowerCase();
        if (statusPill) {
            statusPill.textContent = statusLabel(s);
            statusPill.className = `status-pill ${s || ''}`.trim();
        }
        if (statusNote) statusNote.textContent = statusSubtext(s);

        if (refValue) refValue.textContent = data.reference_number || '—';
        if (branchValue) branchValue.textContent = data.branch_name || '—';
        if (regionValue) regionValue.textContent = data.region_name || '—';

        const dateText = formatDate(data.date);
        const slotText = String(data.time_slot || '').toUpperCase();
        if (dateValue) dateValue.textContent = dateText;
        if (slotValue) slotValue.textContent = slotText ? slotLabel(slotText) : '—';

        if (volumeValue) {
            const v = Number(data.volume || 0);
            volumeValue.textContent = Number.isFinite(v) && v > 0 ? `${v.toLocaleString()} bag(s)` : '—';
        }

        if (farmerValue) {
            const name = data.farmer_name || '—';
            const farmerId = data.farmer_id ? ` (${data.farmer_id})` : '';
            farmerValue.textContent = name + farmerId;
        }

        markTimeline(s);

        setActionButtons(s);

        if (results) results.style.display = 'block';
    };

    const openConfirm = ({ message, metaHtml, okLabel, danger }) => {
        confirmAction = null;
        showInlineAlert(confirmModalAlert, '');

        if (confirmMessage) confirmMessage.textContent = message || 'Are you sure?';
        if (confirmMeta) {
            if (metaHtml) {
                confirmMeta.innerHTML = metaHtml;
                confirmMeta.style.display = 'block';
            } else {
                confirmMeta.innerHTML = '';
                confirmMeta.style.display = 'none';
            }
        }
        if (confirmOkBtn) {
            confirmOkBtn.textContent = okLabel || 'Yes';
            confirmOkBtn.className = `tracker-btn ${danger ? 'tracker-btn-danger' : 'tracker-btn-primary'}`;
        }
        openModal(confirmModal);
    };

    const doLookup = async () => {
        const reference = normalizeRef(refInput?.value);
        const farmerId = String(farmerIdInput?.value || '').trim();
        const email = String(emailInput?.value || '').trim();

        if (!reference) {
            showAlert('Please enter your reference number.');
            refInput && refInput.focus();
            return;
        }

        if (!isValidReference(reference)) {
            showAlert('Invalid reference format. Example: NFA20260112A1B89E');
            refInput && refInput.focus();
            return;
        }

        showAlert('');
        setLoading(true);
        try {
            window.NFALoading && typeof window.NFALoading.show === 'function' && window.NFALoading.show('Tracking appointment…');
        } catch (e) {
            // ignore
        }

        try {
            const url = new URL(apiBase, window.location.href);
            url.searchParams.set('action', 'trackAppointment');
            url.searchParams.set('reference_number', reference);
            if (farmerId) url.searchParams.set('farmer_id', farmerId);
            if (email) url.searchParams.set('email', email);

            const resp = await fetch(url.toString(), { method: 'GET' });
            const json = await resp.json();

            if (!json || !json.success) {
                throw new Error((json && json.error) ? json.error : 'Unable to find the appointment.');
            }

            const data = json.data || {};

            // Reflect URL (useful when arriving from the confirmation page)
            const newUrl = new URL(window.location.href);
            newUrl.searchParams.set('ref', reference);
            window.history.replaceState({}, '', newUrl.toString());

            renderResult(data);
            showAlert('Appointment loaded.', 'success');
        } catch (e) {
            showAlert(e?.message || 'Something went wrong while fetching the appointment.');
            if (results) results.style.display = 'none';
            lastLoaded = null;
            setActionButtons('');
        } finally {
            try {
                window.NFALoading && typeof window.NFALoading.hide === 'function' && window.NFALoading.hide();
            } catch (e) {
                // ignore
            }
            setLoading(false);
        }
    };

    const getIdentityPayload = () => {
        return {
            reference_number: normalizeRef(refValue?.textContent || refInput?.value),
            farmer_id: String(farmerIdInput?.value || '').trim(),
            email: String(emailInput?.value || '').trim(),
        };
    };

    const handleCancelClick = () => {
        if (!lastLoaded) return;

        (async () => {
            if (needsIdentity()) {
                showAlert('For security, please enter your Farmer ID or Email before cancelling.', 'error');
                farmerIdInput && farmerIdInput.focus();
                return;
            }

            if (btnCancelAppointment) btnCancelAppointment.disabled = true;
            if (btnConfirmRescheduled) btnConfirmRescheduled.disabled = true;
            try {
                window.NFALoading && typeof window.NFALoading.show === 'function' && window.NFALoading.show('Verifying…');
            } catch {
                // ignore
            }

            try {
                await verifyIdentityNow();

                showInlineAlert(cancelFormAlert, '');
                if (cancelForm) cancelForm.reset();
                if (cancelDetails) cancelDetails.value = '';
                try { syncOtherRequirement(); } catch { /* ignore */ }
                openModal(cancelModal);
            } catch (e) {
                showAlert(e?.message || 'Verification failed. Please check your Farmer ID/Email.', 'error');
            } finally {
                try {
                    window.NFALoading && typeof window.NFALoading.hide === 'function' && window.NFALoading.hide();
                } catch {
                    // ignore
                }
                if (btnCancelAppointment) btnCancelAppointment.disabled = false;
                if (btnConfirmRescheduled) btnConfirmRescheduled.disabled = false;
            }
        })();
    };

    const handleConfirmRescheduledClick = () => {
        if (!lastLoaded) return;

        (async () => {
            if (needsIdentity()) {
                showAlert('For security, please enter your Farmer ID or Email before confirming.', 'error');
                farmerIdInput && farmerIdInput.focus();
                return;
            }

            if (btnCancelAppointment) btnCancelAppointment.disabled = true;
            if (btnConfirmRescheduled) btnConfirmRescheduled.disabled = true;
            try {
                window.NFALoading && typeof window.NFALoading.show === 'function' && window.NFALoading.show('Verifying…');
            } catch {
                // ignore
            }

            try {
                await verifyIdentityNow();

                const ref = String(lastLoaded.reference_number || '').trim();
                const meta = `
                    <div><strong>Reference:</strong> ${ref || '—'}</div>
                    <div><strong>New schedule:</strong> ${formatDate(lastLoaded.date)} • ${slotLabel(lastLoaded.time_slot)}</div>
                `;

                openConfirm({
                    message: 'Confirm your updated schedule? This will set your appointment status to Confirmed.',
                    metaHtml: meta,
                    okLabel: 'Yes, confirm',
                    danger: false,
                });

                confirmAction = async () => {
                    const payload = getIdentityPayload();
                    await postJson('confirmRescheduledByTracker', payload);
                    await doLookup();
                    showAlert('Appointment confirmed successfully.', 'success');
                };
            } catch (e) {
                showAlert(e?.message || 'Verification failed. Please check your Farmer ID/Email.', 'error');
            } finally {
                try {
                    window.NFALoading && typeof window.NFALoading.hide === 'function' && window.NFALoading.hide();
                } catch {
                    // ignore
                }
                if (btnCancelAppointment) btnCancelAppointment.disabled = false;
                if (btnConfirmRescheduled) btnConfirmRescheduled.disabled = false;
            }
        })();
    };

    const validateCancelForm = () => {
        const reason = cancelForm ? (cancelForm.querySelector('input[name="cancel_reason"]:checked')?.value || '') : '';
        const details = String(cancelDetails?.value || '').trim();
        if (!reason) {
            return { ok: false, message: 'Please select a cancellation reason.' };
        }
        if (reason === 'other' && details === '') {
            return { ok: false, message: 'Please provide details for the “Other” reason.' };
        }
        return { ok: true, reason, details };
    };

    // Prefill only from URL
    const urlRef = normalizeRef(new URLSearchParams(window.location.search).get('ref'));

    if (refInput) {
        refInput.value = urlRef || '';
    }

    if (urlRef) {
        // Auto lookup when arriving from a link
        doLookup();
    }

    form && form.addEventListener('submit', (e) => {
        e.preventDefault();
        doLookup();
    });

    btnClear && btnClear.addEventListener('click', () => {
        if (refInput) refInput.value = '';
        if (farmerIdInput) farmerIdInput.value = '';
        if (emailInput) emailInput.value = '';
        showAlert('');
        if (results) results.style.display = 'none';
        const newUrl = new URL(window.location.href);
        newUrl.searchParams.delete('ref');
        window.history.replaceState({}, '', newUrl.toString());
        refInput && refInput.focus();
    });

    btnPaste && btnPaste.addEventListener('click', async () => {
        if (!navigator.clipboard?.readText) {
            showAlert('Clipboard paste is not supported in this browser.');
            return;
        }
        try {
            const text = await navigator.clipboard.readText();
            const ref = normalizeRef(text);
            if (!ref) return;
            if (refInput) refInput.value = ref;
            showAlert('Pasted from clipboard.', 'success');
            refInput && refInput.focus();
        } catch {
            showAlert('Unable to read clipboard. Please paste manually.');
        }
    });

    btnCopyRef && btnCopyRef.addEventListener('click', async () => {
        const ref = String(refValue?.textContent || '').trim();
        if (!ref) return;
        try {
            await navigator.clipboard.writeText(ref);
            showAlert('Reference copied.', 'success');
        } catch {
            showAlert('Copy not supported in this browser.');
        }
    });

    btnPrint && btnPrint.addEventListener('click', () => {
        // Clear any previous alert (especially "Pop-up blocked" from earlier attempts)
        showAlert('');

        const reference = normalizeRef(refValue?.textContent || refInput?.value);
        const farmerId = String(farmerIdInput?.value || '').trim();
        const email = String(emailInput?.value || '').trim();

        if (!reference || !isValidReference(reference)) {
            showAlert('Please track a valid appointment first before printing.');
            refInput && refInput.focus();
            return;
        }

        const url = new URL('appointment_tracker_print.php', window.location.href);
        url.searchParams.set('ref', reference);
        if (farmerId) url.searchParams.set('farmer_id', farmerId);
        if (email) url.searchParams.set('email', email);

        // Use a normal navigation-style new tab open (more reliable than window.open return value).
        const a = document.createElement('a');
        a.href = url.toString();
        a.target = '_blank';
        a.rel = 'noopener noreferrer';
        document.body.appendChild(a);
        a.click();
        a.remove();
    });

    // --- Cancel / Confirm actions ---
    btnCancelAppointment && btnCancelAppointment.addEventListener('click', handleCancelClick);
    btnConfirmRescheduled && btnConfirmRescheduled.addEventListener('click', handleConfirmRescheduledClick);

    const closeCancel = () => closeModal(cancelModal);
    cancelModalClose && cancelModalClose.addEventListener('click', closeCancel);
    cancelModalBack && cancelModalBack.addEventListener('click', closeCancel);
    cancelModal && cancelModal.addEventListener('click', (e) => {
        if (e.target === cancelModal) closeCancel();
    });

    const closeConfirm = () => {
        closeModal(confirmModal);
        confirmAction = null;
        showInlineAlert(confirmModalAlert, '');
    };
    confirmModalClose && confirmModalClose.addEventListener('click', closeConfirm);
    confirmCancelBtn && confirmCancelBtn.addEventListener('click', closeConfirm);
    confirmModal && confirmModal.addEventListener('click', (e) => {
        if (e.target === confirmModal) closeConfirm();
    });

    confirmOkBtn && confirmOkBtn.addEventListener('click', async () => {
        if (typeof confirmAction !== 'function') {
            closeConfirm();
            return;
        }

        showInlineAlert(confirmModalAlert, '');
        confirmOkBtn.disabled = true;
        confirmCancelBtn && (confirmCancelBtn.disabled = true);

        try {
            window.NFALoading && typeof window.NFALoading.show === 'function' && window.NFALoading.show('Processing…');
        } catch {
            // ignore
        }

        try {
            await confirmAction();
            closeConfirm();
        } catch (e) {
            showInlineAlert(confirmModalAlert, e?.message || 'Unable to process your request.');
        } finally {
            try {
                window.NFALoading && typeof window.NFALoading.hide === 'function' && window.NFALoading.hide();
            } catch {
                // ignore
            }
            confirmOkBtn.disabled = false;
            confirmCancelBtn && (confirmCancelBtn.disabled = false);
        }
    });

    cancelForm && cancelForm.addEventListener('submit', (e) => {
        e.preventDefault();
        showInlineAlert(cancelFormAlert, '');

        const v = validateCancelForm();
        if (!v.ok) {
            showInlineAlert(cancelFormAlert, v.message);
            return;
        }

        const ref = String(lastLoaded?.reference_number || '').trim();
        const reasonLabelMap = {
            schedule_conflict: 'Schedule conflict',
            no_longer_available: 'No longer available to deliver',
            wrong_details: 'Wrong details / need to rebook',
            other: 'Other'
        };

        const meta = `
            <div><strong>Reference:</strong> ${ref || '—'}</div>
            <div><strong>Reason:</strong> ${reasonLabelMap[v.reason] || v.reason}</div>
            ${v.details ? `<div><strong>Details:</strong> ${v.details.replace(/</g, '&lt;')}</div>` : ''}
        `;

        closeModal(cancelModal);
        openConfirm({
            message: 'Are you sure you want to cancel this appointment? This action cannot be undone.',
            metaHtml: meta,
            okLabel: 'Yes, cancel',
            danger: true,
        });

        confirmAction = async () => {
            const payload = {
                ...getIdentityPayload(),
                reason_code: v.reason,
                reason_detail: v.details || ''
            };
            await postJson('cancelAppointmentByTracker', payload);
            await doLookup();
            showAlert('Appointment cancelled successfully.', 'success');
        };
    });

    // Toggle textarea required when "Other" is selected
    const syncOtherRequirement = () => {
        const reason = cancelForm ? (cancelForm.querySelector('input[name="cancel_reason"]:checked')?.value || '') : '';
        const isOther = reason === 'other';
        if (cancelDetails) {
            cancelDetails.required = isOther;
        }
        const help = document.getElementById('cancelDetailsHelp');
        if (help) {
            help.textContent = isOther
                ? 'Details are required when you choose “Other”.'
                : 'If you choose “Other”, details are required.';
        }
        const label = document.querySelector('label[for="cancelDetails"]');
        if (label) {
            label.textContent = isOther ? 'Details (required)' : 'Details (optional)';
        }
    };

    cancelForm && cancelForm.addEventListener('change', (e) => {
        if (e && e.target && e.target.name === 'cancel_reason') {
            syncOtherRequirement();
        }
    });

    // ESC closes modals
    document.addEventListener('keydown', (e) => {
        if (e.key !== 'Escape') return;
        if (confirmModal && confirmModal.style.display !== 'none') {
            closeConfirm();
        } else if (cancelModal && cancelModal.style.display !== 'none') {
            closeCancel();
        }
    });
});
