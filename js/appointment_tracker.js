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

        if (results) results.style.display = 'block';
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
        } finally {
            try {
                window.NFALoading && typeof window.NFALoading.hide === 'function' && window.NFALoading.hide();
            } catch (e) {
                // ignore
            }
            setLoading(false);
        }
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

        const w = window.open(url.toString(), '_blank', 'noopener,noreferrer');
        if (!w) showAlert('Pop-up blocked. Please allow pop-ups to open the print view.');
    });
});
