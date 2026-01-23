document.addEventListener('DOMContentLoaded', () => {
    const apiBase = 'php_helper/api.php';

    // Main details modal
    const modal = document.getElementById('detailsModal');
    if (!modal) return;

    const modalName = document.getElementById('modalName');
    const modalRef = document.getElementById('modalReference');
    const modalDate = document.getElementById('modalDate');
    const modalSlot = document.getElementById('modalSlot');
    const modalMode = document.getElementById('modalMode');
    const modalVolume = document.getElementById('modalVolume');
    const modalStatus = document.getElementById('modalStatus');

    const modalCancellation = document.getElementById('modalCancellation');
    const modalCancelledAt = document.getElementById('modalCancelledAt');
    const modalCancelReason = document.getElementById('modalCancelReason');
    const modalFarmerId = document.getElementById('modalFarmerId');
    const modalFarmerType = document.getElementById('modalFarmerType');
    const modalGender = document.getElementById('modalGender');
    const modalEmail = document.getElementById('modalEmail');
    const modalContact = document.getElementById('modalContact');
    const modalRegion = document.getElementById('modalRegion');
    const modalBranch = document.getElementById('modalBranch');

    // Action buttons
    const btnConfirm = document.getElementById('btnConfirm');
    const btnReschedule = document.getElementById('btnReschedule');
    const btnReceive = document.getElementById('btnReceive');

    // Receive modal elements
    const receiveModal = document.getElementById('receiveModal');
    const receiveClose = document.getElementById('receiveClose');
    const receiveInput = document.getElementById('receiveVolume');
    const receiveSubmit = document.getElementById('receiveSubmit');

    // Reschedule modal elements
    const reschedModal = document.getElementById('reschedModal');
    const reschedClose = document.getElementById('reschedClose');
    const reschedPrev = document.getElementById('reschedPrev');
    const reschedNext = document.getElementById('reschedNext');
    const reschedMonthLabel = document.getElementById('reschedMonthLabel');
    const reschedYearLabel = document.getElementById('reschedYearLabel');
    const reschedGrid = document.getElementById('reschedGrid');
    const reschedAmRemaining = document.getElementById('reschedAmRemaining');
    const reschedPmRemaining = document.getElementById('reschedPmRemaining');
    const slotAm = document.getElementById('slotAm');
    const slotPm = document.getElementById('slotPm');
    const reschedSubmit = document.getElementById('reschedSubmit');

    const branchId = window.branchId || null;

    let currentAppointmentId = null;
    let currentVolume = 0;
    let currentStatus = '';
    let currentDateIso = '';

    let reschedYear = null;
    let reschedMonth = null; // 1-12
    let availability = {};
    let reschedHolidays = [];
    let reschedHolidayNames = {};
    let selectedReschedDate = null;
    let selectedSlot = null;

    const notify = (message, type = 'warning') => {
        if (!message) return;

        if (typeof showToast === 'function') {
            showToast(message, type);
            return;
        }

        // Fallback: lightweight toast (auto-closes)
        try {
            const existing = document.querySelectorAll('.toast');
            existing.forEach(t => t.remove());

            const toast = document.createElement('div');
            toast.className = `toast toast-${type}`;
            toast.innerHTML = `<span class="toast-message"></span>`;
            toast.querySelector('.toast-message').textContent = message;
            document.body.appendChild(toast);
            setTimeout(() => toast.classList.add('show'), 10);
            setTimeout(() => {
                toast.classList.remove('show');
                setTimeout(() => toast.remove(), 250);
            }, 3500);
        } catch (e) {
            // Last resort
            console.warn(message);
        }
    };

    const formatDateYmd = (date) => {
        if (!date) return '';
        const d = new Date(date);
        const y = d.getFullYear();
        const m = String(d.getMonth() + 1).padStart(2, '0');
        const day = String(d.getDate()).padStart(2, '0');
        return `${y}-${m}-${day}`;
    };

    const getMinReschedDate = () => {
        // Align with farmer scheduling rules: at least 1 day ahead; skip holidays when computing min.
        const today = new Date();
        today.setHours(0, 0, 0, 0);

        const minDate = new Date(today);
        minDate.setDate(minDate.getDate() + 1);

        const holidaySet = new Set(Array.isArray(reschedHolidays) ? reschedHolidays : []);
        while (holidaySet.size > 0 && holidaySet.has(formatDateYmd(minDate))) {
            minDate.setDate(minDate.getDate() + 1);
        }

        return minDate;
    };

    const openDetailsModal = (btn) => {
        // Only allow opening details from appointment cards (must have an appointment id)
        const parsedId = parseInt(btn.dataset.appointmentId, 10);
        if (!parsedId) return;

        currentAppointmentId = parsedId;
        currentVolume = parseFloat(btn.dataset.volumeRaw || '0') || 0;
        currentStatus = btn.dataset.status || '';
        currentDateIso = btn.dataset.dateIso || '';

        modalName.textContent = btn.dataset.name || '';
        modalRef.textContent = `Reference: ${btn.dataset.reference || ''}`;
        modalDate.textContent = btn.dataset.date || '';
        modalSlot.textContent = btn.dataset.slot || '';
        if (modalMode) {
            const modeLabel = (btn.dataset.mode || 'Appointment').trim();
            modalMode.textContent = modeLabel || 'Appointment';
        }
        modalVolume.textContent = btn.dataset.volume || '';
        modalStatus.textContent = currentStatus ? currentStatus.charAt(0).toUpperCase() + currentStatus.slice(1) : '';
        modalFarmerId.textContent = btn.dataset.farmerId || '';
        modalFarmerType.textContent = btn.dataset.farmerType || '';
        modalGender.textContent = btn.dataset.gender || '';
        modalEmail.textContent = btn.dataset.email || '';
        modalContact.textContent = btn.dataset.contact || '';
        modalRegion.textContent = btn.dataset.region || '';
        modalBranch.textContent = btn.dataset.branch || '';

        // Enable/disable actions based on status
        const statusLower = currentStatus.toLowerCase();
        const isCancelled = (statusLower === 'cancelled' || statusLower === 'canceled');
        const isCompleted = (statusLower === 'completed');
        const isConfirmedLike = (statusLower === 'confirmed' || statusLower === 'rescheduled');

        // Cancellation details (only for cancelled/canceled)
        if (modalCancellation) {
            if (isCancelled) {
                const cancelledAtLabel = (btn.dataset.cancelledAtLabel || '').trim();
                const cancelReason = (btn.dataset.cancelReason || '').trim();

                if (modalCancelledAt) modalCancelledAt.textContent = cancelledAtLabel || '—';
                if (modalCancelReason) modalCancelReason.textContent = cancelReason || '—';
                modalCancellation.style.display = 'block';
            } else {
                modalCancellation.style.display = 'none';
                if (modalCancelledAt) modalCancelledAt.textContent = '';
                if (modalCancelReason) modalCancelReason.textContent = '';
            }
        }

        // Completed and cancelled are terminal states
        if (isCancelled || isCompleted) {
            if (btnConfirm) btnConfirm.disabled = true;
            if (btnReschedule) btnReschedule.disabled = true;
            if (btnReceive) btnReceive.disabled = true;
        } else {
            if (btnConfirm) {
                btnConfirm.disabled = isConfirmedLike;
            }
            if (btnReschedule) {
                btnReschedule.disabled = isConfirmedLike;
            }
            if (btnReceive) {
                // Receive click is handled with additional rules (e.g., rescheduled must be farmer-confirmed first)
                btnReceive.disabled = !isConfirmedLike;
            }
        }

        modal.style.display = 'flex';
        document.body.classList.add('modal-open');
    };

    const closeDetailsModal = () => {
        modal.style.display = 'none';
        document.body.classList.remove('modal-open');
    };

    // Helper for API calls
    const postJson = async (action, payload) => {
        const response = await fetch(`${apiBase}?action=${encodeURIComponent(action)}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload || {})
        });
        return response.json();
    };

    const withLoading = async (message, disableEls, fn) => {
        const api = window.NFALoading;
        if (api && typeof api.withLoading === 'function') {
            return api.withLoading(fn, { message: message, disable: disableEls });
        }

        const els = Array.isArray(disableEls) ? disableEls : (disableEls ? [disableEls] : []);
        const prev = els.map(el => ({ el, disabled: !!el?.disabled }));
        try {
            els.forEach(el => { if (el) el.disabled = true; });
            return await fn();
        } finally {
            prev.forEach(({ el, disabled }) => { if (el) el.disabled = disabled; });
        }
    };

    const broadcastAndReload = (reason) => {
        try {
            if (typeof window.publishAppointmentsRefresh === 'function') {
                window.publishAppointmentsRefresh({ reason: reason || 'updated' });
            }
        } catch (e) {
            // ignore
        }

        // Ensure this page reflects calendar/tile counts immediately.
        setTimeout(() => {
            try { window.location.reload(); } catch (e) {}
        }, 650);
    };

    // Confirm appointment
    if (btnConfirm) {
        btnConfirm.addEventListener('click', async () => {
            if (!currentAppointmentId) return;
            const statusLower = String(currentStatus || '').toLowerCase();
            if (statusLower === 'cancelled' || statusLower === 'canceled') {
                if (typeof showToast === 'function') showToast('Cancelled appointments cannot be modified.', 'warning');
                return;
            }
            if (statusLower === 'completed') {
                if (typeof showToast === 'function') showToast('Completed appointments cannot be modified.', 'warning');
                return;
            }
            const ok = (typeof confirmDialog === 'function')
                ? await confirmDialog({
                    title: 'Confirm Appointment',
                    message: 'Confirming will lock this appointment (reschedule becomes disabled) and allow receiving. Continue?',
                    confirmText: 'Confirm',
                    cancelText: 'Cancel',
                    tone: 'primary'
                })
                : confirm('Confirm this appointment?');

            if (!ok) return;

            await withLoading('Confirming appointment (and notifying farmer)…', [btnConfirm, btnReschedule, btnReceive], async () => {
                try {
                    const result = await postJson('confirmAppointment', { appointment_id: currentAppointmentId });
                if (result && result.success) {
                    // Update local state and UI without reloading the page
                    currentStatus = 'confirmed';
                    if (modalStatus) {
                        modalStatus.textContent = 'Confirmed';
                    }

                    if (btnConfirm) {
                        btnConfirm.disabled = true;
                    }
                    if (btnReschedule) {
                        btnReschedule.disabled = true;
                    }
                    if (btnReceive) {
                        btnReceive.disabled = false;
                    }

                    // Update the originating appointment card button dataset
                    const cardBtn = document.querySelector('.appointments-tiles .btn-view-details[data-appointment-id="' + currentAppointmentId + '"]');
                    if (cardBtn) {
                        cardBtn.dataset.status = 'confirmed';
                    }

                    if (typeof showToast === 'function') {
                        showToast('Appointment confirmed.', 'success');
                    }

                    broadcastAndReload('confirmed');
                } else {
                    if (typeof showToast === 'function') {
                        showToast(result.error || 'Failed to confirm appointment.', 'error');
                    }
                }
                } catch (err) {
                    if (typeof showToast === 'function') {
                        showToast('Error confirming appointment.', 'error');
                    }
                }
            });
        });
    }

    // Receive delivery
    const openReceiveModal = () => {
        if (!currentAppointmentId) return;
        const statusLower = String(currentStatus || '').toLowerCase();
        if (statusLower === 'cancelled' || statusLower === 'canceled') {
            notify('Cancelled appointments cannot be marked as received.', 'warning');
            return;
        }
        if (statusLower === 'completed') {
            notify('This appointment is already completed.', 'warning');
            return;
        }
        if (statusLower === 'rescheduled') {
            notify('Rescheduled appointments must be confirmed by the farmer first before accepting delivery.', 'warning');
            return;
        }
        receiveInput.value = currentVolume || 0;
        if (receiveModal) {
            receiveModal.style.display = 'flex';
        }
    };

    const closeReceiveModal = () => {
        if (receiveModal) {
            receiveModal.style.display = 'none';
        }
    };

    if (btnReceive) {
        btnReceive.addEventListener('click', openReceiveModal);
    }
    if (receiveClose) {
        receiveClose.addEventListener('click', closeReceiveModal);
    }

    if (receiveSubmit) {
        receiveSubmit.addEventListener('click', async () => {
            if (!currentAppointmentId) return;
            const statusLower = String(currentStatus || '').toLowerCase();
            if (statusLower === 'cancelled' || statusLower === 'canceled') {
                notify('Cancelled appointments cannot be marked as received.', 'warning');
                return;
            }
            if (statusLower === 'completed') {
                notify('This appointment is already completed.', 'warning');
                return;
            }
            if (statusLower === 'rescheduled') {
                notify('Rescheduled appointments must be confirmed by the farmer first before accepting delivery.', 'warning');
                return;
            }
            const newVolume = parseFloat(receiveInput?.value ?? '');
            if (Number.isNaN(newVolume) || newVolume < 0) {
                if (typeof showToast === 'function') {
                    showToast('Please enter a valid number of bags.', 'warning');
                } else {
                    alert('Please enter a valid number of bags.');
                }
                receiveInput && receiveInput.focus();
                return;
            }

            const ok = (typeof confirmDialog === 'function')
                ? await confirmDialog({
                    title: 'Submit Delivery',
                    message: `Record ${newVolume.toLocaleString()} bag(s) received and mark this appointment as Completed?`,
                    confirmText: 'Yes, Submit',
                    cancelText: 'Cancel',
                    tone: 'primary'
                })
                : confirm('Submit delivery and mark appointment as completed?');
            if (!ok) return;

            await withLoading('Submitting delivery (and emailing farmer)…', [receiveSubmit, btnConfirm, btnReschedule, btnReceive], async () => {
                try {
                    const result = await postJson('completeAppointment', {
                        appointment_id: currentAppointmentId,
                        volume: newVolume
                    });
                    if (result && result.success) {
                    // Update local state and UI without reloading the page
                    currentVolume = newVolume;

                    if (modalVolume) {
                        modalVolume.textContent = newVolume.toLocaleString();
                    }

                    currentStatus = 'completed';
                    if (modalStatus) {
                        modalStatus.textContent = 'Completed';
                    }

                    if (btnConfirm) {
                        btnConfirm.disabled = true;
                    }
                    if (btnReschedule) {
                        btnReschedule.disabled = true;
                    }
                    if (btnReceive) {
                        btnReceive.disabled = true;
                    }

                    const cardBtn = document.querySelector('.appointments-tiles .btn-view-details[data-appointment-id="' + currentAppointmentId + '"]');
                    if (cardBtn) {
                        cardBtn.dataset.volumeRaw = String(newVolume);
                        cardBtn.dataset.volume = newVolume.toLocaleString();
                        cardBtn.dataset.status = 'completed';

                        const card = cardBtn.closest('.appointment-card');
                        if (card) {
                            const volEl = card.querySelector('.appointment-volume');
                            if (volEl) {
                                volEl.innerHTML = '<i class="fas fa-weight-hanging"></i> ' + newVolume.toLocaleString() + ' bags';
                            }
                        }
                    }

                    closeReceiveModal();
                    if (typeof showToast === 'function') {
                        showToast('Delivery recorded successfully.', 'success');
                    }

                    broadcastAndReload('completed');
                    } else {
                        if (typeof showToast === 'function') {
                            showToast(result.error || 'Failed to record delivery.', 'error');
                        }
                    }
                } catch (err) {
                    if (typeof showToast === 'function') {
                        showToast('Error recording delivery.', 'error');
                    }
                }
            });
        });
    }

    // Reschedule helpers
    const openReschedModal = () => {
        if (!currentAppointmentId || !branchId) return;
        const statusLower = String(currentStatus || '').toLowerCase();
        if (statusLower === 'cancelled' || statusLower === 'canceled') {
            if (typeof showToast === 'function') showToast('Cancelled appointments cannot be rescheduled.', 'warning');
            return;
        }
        if (statusLower === 'completed') {
            if (typeof showToast === 'function') showToast('Completed appointments cannot be rescheduled.', 'warning');
            return;
        }
        const baseDate = currentDateIso ? new Date(currentDateIso) : new Date();
        reschedYear = baseDate.getFullYear();
        reschedMonth = baseDate.getMonth() + 1;
        selectedReschedDate = null;
        selectedSlot = null;
        reschedSubmit.disabled = true;
        loadReschedMonth();
        if (reschedModal) {
            reschedModal.style.display = 'flex';
        }
    };

    const closeReschedModal = () => {
        if (reschedModal) {
            reschedModal.style.display = 'none';
        }
    };

    const loadReschedMonth = async () => {
        const startDate = `${reschedYear}-${String(reschedMonth).padStart(2, '0')}-01`;
        const lastDay = new Date(reschedYear, reschedMonth, 0).getDate();
        const endDate = `${reschedYear}-${String(reschedMonth).padStart(2, '0')}-${String(lastDay).padStart(2, '0')}`;

        reschedMonthLabel.textContent = new Date(startDate).toLocaleString('default', { month: 'long' });
        reschedYearLabel.textContent = String(reschedYear);

        await withLoading('Loading schedule availability…', [reschedPrev, reschedNext], async () => {
            try {
                const url = `${apiBase}?action=getBranchInfo&branch_id=${encodeURIComponent(branchId)}&start_date=${encodeURIComponent(startDate)}&end_date=${encodeURIComponent(endDate)}`;
                const response = await fetch(url);
                const data = await response.json();
                availability = (data && data.success && data.daily_availability) ? data.daily_availability : {};

                reschedHolidays = (data && data.success && Array.isArray(data.holidays)) ? data.holidays : [];
                reschedHolidayNames = {};
                if (data && data.success && Array.isArray(data.holiday_details)) {
                    data.holiday_details.forEach((row) => {
                        const d = row && row.holiday_date;
                        if (!d) return;
                        reschedHolidayNames[d] = (row.holiday_name || 'Holiday').toString();
                    });
                }
                renderReschedGrid(startDate, lastDay);
            } catch (err) {
                availability = {};
                reschedHolidays = [];
                reschedHolidayNames = {};
                renderReschedGrid(startDate, lastDay);
            }
        });
    };

    const renderReschedGrid = (startDate, daysInMonth) => {
        const firstDay = new Date(startDate);
        const year = firstDay.getFullYear();
        const monthIndex = firstDay.getMonth(); // 0-11
        const weekdays = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

        const minDate = getMinReschedDate();
        const holidaySet = new Set(Array.isArray(reschedHolidays) ? reschedHolidays : []);

        reschedGrid.innerHTML = '';

        // Weekday headers
        weekdays.forEach(wd => {
            const head = document.createElement('div');
            head.className = 'calendar-weekday';
            head.textContent = wd;
            reschedGrid.appendChild(head);
        });

        const firstWeekday = firstDay.getDay();
        for (let i = 0; i < firstWeekday; i++) {
            const empty = document.createElement('div');
            empty.className = 'calendar-cell empty';
            reschedGrid.appendChild(empty);
        }

        const totalDays = daysInMonth;
        for (let d = 1; d <= totalDays; d++) {
            const dateStr = `${year}-${String(monthIndex + 1).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
            const info = availability[dateStr];
            const hasInfo = !!info;

            const dateObj = new Date(dateStr + 'T00:00:00');
            const isWeekend = dateObj.getDay() === 0 || dateObj.getDay() === 6;
            const isBeforeMin = dateObj < minDate;
            const isHoliday = holidaySet.has(dateStr);

            const cell = document.createElement('a');
            cell.href = 'javascript:void(0)';
            let classes = 'calendar-cell calendar-day';

            const disabledByAvailability = info ? info.is_disabled : false;
            const disabled = !hasInfo || disabledByAvailability || isWeekend || isBeforeMin || isHoliday;
            if (disabled) {
                classes += ' disabled';
            } else {
                classes += ' has-appointments';
            }

            cell.className = classes;
            cell.dataset.date = dateStr;

            if (isWeekend) {
                cell.classList.add('weekend');
            }

            if (isHoliday) {
                const holidayName = (reschedHolidayNames && reschedHolidayNames[dateStr])
                    ? reschedHolidayNames[dateStr]
                    : 'Holiday';

                cell.classList.add('holiday');
                cell.dataset.holiday = holidayName;
                cell.title = holidayName;
            }

            const num = document.createElement('div');
            num.className = 'day-number';
            num.textContent = d;
            cell.appendChild(num);

            // In reschedule calendar we only show plain disabled/enabled tiles, no counts

            cell.addEventListener('click', () => {
                if (disabled) return;
                selectedReschedDate = dateStr;
                // Update selected styling
                document.querySelectorAll('#reschedGrid .calendar-day').forEach(el => el.classList.remove('selected'));
                cell.classList.add('selected');

                reschedAmRemaining.textContent = info ? info.am_remaining : '-';
                reschedPmRemaining.textContent = info ? info.pm_remaining : '-';

                updateSlotButtons(info);
            });

            reschedGrid.appendChild(cell);
        }
    };

    const updateSlotButtons = (info) => {
        selectedSlot = null;
        reschedSubmit.disabled = true;

        const enableAm = info && info.am_remaining > 0;
        const enablePm = info && info.pm_remaining > 0;

        slotAm.disabled = !enableAm;
        slotPm.disabled = !enablePm;

        slotAm.classList.toggle('selected', false);
        slotPm.classList.toggle('selected', false);
    };

    if (slotAm) {
        slotAm.addEventListener('click', () => {
            if (slotAm.disabled || !selectedReschedDate) return;
            selectedSlot = 'AM';
            slotAm.classList.add('selected');
            slotPm.classList.remove('selected');
            reschedSubmit.disabled = false;
        });
    }

    if (slotPm) {
        slotPm.addEventListener('click', () => {
            if (slotPm.disabled || !selectedReschedDate) return;
            selectedSlot = 'PM';
            slotPm.classList.add('selected');
            slotAm.classList.remove('selected');
            reschedSubmit.disabled = false;
        });
    }

    if (btnReschedule) {
        btnReschedule.addEventListener('click', openReschedModal);
    }
    if (reschedClose) {
        reschedClose.addEventListener('click', closeReschedModal);
    }

    if (reschedPrev) {
        reschedPrev.addEventListener('click', () => {
            if (--reschedMonth < 1) {
                reschedMonth = 12;
                reschedYear--;
            }
            loadReschedMonth();
        });
    }

    if (reschedNext) {
        reschedNext.addEventListener('click', () => {
            if (++reschedMonth > 12) {
                reschedMonth = 1;
                reschedYear++;
            }
            loadReschedMonth();
        });
    }

    if (reschedSubmit) {
        reschedSubmit.addEventListener('click', async () => {
            if (!currentAppointmentId || !selectedReschedDate || !selectedSlot) return;
            const statusLower = String(currentStatus || '').toLowerCase();
            if (statusLower === 'cancelled' || statusLower === 'canceled') {
                if (typeof showToast === 'function') showToast('Cancelled appointments cannot be rescheduled.', 'warning');
                return;
            }

            await withLoading('Rescheduling appointment (and notifying farmer)…', [reschedSubmit, btnConfirm, btnReschedule, btnReceive], async () => {
                try {
                    const result = await postJson('rescheduleAppointment', {
                        appointment_id: currentAppointmentId,
                        date: selectedReschedDate,
                        time_slot: selectedSlot
                    });
                    if (result && result.success) {
                    // Update local state and UI without navigating away
                    currentStatus = 'rescheduled';
                    currentDateIso = selectedReschedDate;

                    const dateObj = new Date(selectedReschedDate + 'T00:00:00');
                    const formattedDate = dateObj.toLocaleDateString('en-US', {
                        month: 'long',
                        day: 'numeric',
                        year: 'numeric'
                    });

                    if (modalDate) {
                        modalDate.textContent = formattedDate;
                    }
                    if (modalSlot) {
                        modalSlot.textContent = selectedSlot === 'PM' ? 'Afternoon' : 'Morning';
                    }
                    if (modalStatus) {
                        modalStatus.textContent = 'Rescheduled';
                    }

                    if (btnConfirm) {
                        btnConfirm.disabled = true;
                    }
                    if (btnReschedule) {
                        btnReschedule.disabled = true;
                    }
                    if (btnReceive) {
                        btnReceive.disabled = false;
                    }

                    const cardBtn = document.querySelector('.appointments-tiles .btn-view-details[data-appointment-id="' + currentAppointmentId + '"]');
                    if (cardBtn) {
                        cardBtn.dataset.dateIso = selectedReschedDate;
                        cardBtn.dataset.date = formattedDate;
                        cardBtn.dataset.slot = selectedSlot === 'PM' ? 'Afternoon' : 'Morning';
                        cardBtn.dataset.status = 'rescheduled';

                        const card = cardBtn.closest('.appointment-card');
                        if (card) {
                            const dateEl = card.querySelector('.appointment-date');
                            const slotEl = card.querySelector('.appointment-slot');
                            if (dateEl) {
                                dateEl.innerHTML = '<i class="fas fa-calendar"></i> ' + formattedDate;
                            }
                            if (slotEl) {
                                const isPm = selectedSlot === 'PM';
                                slotEl.innerHTML = '<i class="fas fa-clock"></i> ' + (isPm ? 'Afternoon' : 'Morning');
                                slotEl.classList.toggle('pm', isPm);
                                slotEl.classList.toggle('am', !isPm);
                            }
                        }
                    }

                    closeReschedModal();
                    if (typeof showToast === 'function') {
                        showToast('Appointment rescheduled successfully.', 'success');
                    }

                    broadcastAndReload('rescheduled');
                    } else {
                        if (typeof showToast === 'function') {
                            showToast(result.error || 'Failed to reschedule appointment.', 'error');
                        }
                    }
                } catch (err) {
                    if (typeof showToast === 'function') {
                        showToast('Error rescheduling appointment.', 'error');
                    }
                }
            });
        });
    }

    // Wire up open for main details modal (ONLY the appointment cards)
    document.querySelectorAll('.appointments-tiles .appointment-actions .btn-view-details').forEach(btn => {
        btn.addEventListener('click', () => openDetailsModal(btn));
    });

    const closeBtn = document.getElementById('modalClose');
    if (closeBtn) {
        closeBtn.addEventListener('click', closeDetailsModal);
    }

    modal.addEventListener('click', (e) => {
        if (e.target === modal) {
            closeDetailsModal();
        }
    });

    document.addEventListener('keyup', (e) => {
        if (e.key === 'Escape') {
            if (reschedModal && reschedModal.style.display === 'flex') {
                closeReschedModal();
            } else if (receiveModal && receiveModal.style.display === 'flex') {
                closeReceiveModal();
            } else if (modal.style.display === 'flex') {
                closeDetailsModal();
            }
        }
    });
});