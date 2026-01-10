document.addEventListener('DOMContentLoaded', () => {
    const apiBase = 'php_helper/api.php';

    // Main details modal
    const modal = document.getElementById('detailsModal');
    if (!modal) return;

    const modalName = document.getElementById('modalName');
    const modalRef = document.getElementById('modalReference');
    const modalDate = document.getElementById('modalDate');
    const modalSlot = document.getElementById('modalSlot');
    const modalVolume = document.getElementById('modalVolume');
    const modalStatus = document.getElementById('modalStatus');
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
    let selectedReschedDate = null;
    let selectedSlot = null;

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
        const isConfirmed = statusLower === 'confirmed';

        if (btnConfirm) {
            btnConfirm.disabled = isConfirmed;
        }
        if (btnReschedule) {
            btnReschedule.disabled = isConfirmed;
        }
        if (btnReceive) {
            // Only allow receive for confirmed appointments
            btnReceive.disabled = !isConfirmed;
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

    // Confirm appointment
    if (btnConfirm) {
        btnConfirm.addEventListener('click', async () => {
            if (!currentAppointmentId) return;
            if (!confirm('Confirm this appointment?')) return;
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

                    alert('Appointment confirmed.');
                } else {
                    alert(result.error || 'Failed to confirm appointment.');
                }
            } catch (err) {
                alert('Error confirming appointment.');
            }
        });
    }

    // Receive delivery
    const openReceiveModal = () => {
        if (!currentAppointmentId) return;
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
            const newVolume = parseFloat(receiveInput.value || '0');
            if (!newVolume || newVolume < 0) {
                alert('Please enter a valid number of bags.');
                return;
            }
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
                    alert('Delivery recorded successfully.');
                } else {
                    alert(result.error || 'Failed to record delivery.');
                }
            } catch (err) {
                alert('Error recording delivery.');
            }
        });
    }

    // Reschedule helpers
    const openReschedModal = () => {
        if (!currentAppointmentId || !branchId) return;
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

        try {
            const url = `${apiBase}?action=getBranchInfo&branch_id=${encodeURIComponent(branchId)}&start_date=${encodeURIComponent(startDate)}&end_date=${encodeURIComponent(endDate)}`;
            const response = await fetch(url);
            const data = await response.json();
            availability = (data && data.success && data.daily_availability) ? data.daily_availability : {};
            renderReschedGrid(startDate, lastDay);
        } catch (err) {
            availability = {};
            renderReschedGrid(startDate, lastDay);
        }
    };

    const renderReschedGrid = (startDate, daysInMonth) => {
        const firstDay = new Date(startDate);
        const year = firstDay.getFullYear();
        const monthIndex = firstDay.getMonth(); // 0-11
        const weekdays = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

        const today = new Date();
        today.setHours(0, 0, 0, 0);
        const minDate = new Date(today.getTime() + 24 * 60 * 60 * 1000); // 1 day ahead

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

            const dateObj = new Date(dateStr + 'T00:00:00');
            const isWeekend = dateObj.getDay() === 0 || dateObj.getDay() === 6;
            const isBeforeMin = dateObj < minDate;

            const cell = document.createElement('a');
            cell.href = 'javascript:void(0)';
            let classes = 'calendar-cell calendar-day';

            const disabledByAvailability = info ? info.is_disabled : false;
            const disabled = disabledByAvailability || isWeekend || isBeforeMin;
            if (disabled) {
                classes += ' disabled';
            } else {
                classes += ' has-appointments';
            }

            cell.className = classes;
            cell.dataset.date = dateStr;

            const num = document.createElement('div');
            num.className = 'day-number';
            num.textContent = d;
            cell.appendChild(num);

            // In reschedule calendar we only show plain disabled/enabled tiles, no counts

            cell.addEventListener('click', () => {
                if (disabled || !info) return;
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
            try {
                const result = await postJson('rescheduleAppointment', {
                    appointment_id: currentAppointmentId,
                    date: selectedReschedDate,
                    time_slot: selectedSlot
                });
                if (result && result.success) {
                    // Update local state and UI without navigating away
                    currentStatus = 'confirmed';
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

                    const cardBtn = document.querySelector('.appointments-tiles .btn-view-details[data-appointment-id="' + currentAppointmentId + '"]');
                    if (cardBtn) {
                        cardBtn.dataset.dateIso = selectedReschedDate;
                        cardBtn.dataset.date = formattedDate;
                        cardBtn.dataset.slot = selectedSlot === 'PM' ? 'Afternoon' : 'Morning';
                        cardBtn.dataset.status = 'confirmed';

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
                    alert('Appointment rescheduled successfully. Refresh the calendar if needed to see it on the new date.');
                } else {
                    alert(result.error || 'Failed to reschedule appointment.');
                }
            } catch (err) {
                alert('Error rescheduling appointment.');
            }
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