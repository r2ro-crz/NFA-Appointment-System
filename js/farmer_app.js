// Enhanced Farmer Schedule JavaScript
document.addEventListener('DOMContentLoaded', function() {
    initializeEnhancedApp();
});

async function initializeEnhancedApp() {
    // Set up all event listeners
    setupEnhancedEventListeners();
    
    // Initialize form functionality
    await fetchRegions();
    await fetchFarmerTypes();
    
    // Set up step navigation
    setupStepNavigation();
    
    // Initialize the wizard to show step 1
    showStep(1);
    
    // Update progress tracker
    updateProgressTracker(1);
}

// --- Enhanced State Management ---
const appState = {
    currentStep: 1,
    selectedRegion: null,
    selectedBranch: null,
    selectedDate: null,
    selectedTime: null,
    holidays: [],
    holidayNames: {},
    referenceNumber: null,
    appointmentFreeze: {
        effective_frozen: false,
        source: null,
        message: ''
    },
    branchCapacity: {
        availableVolume: 0,
        amCapacity: 0,
        pmCapacity: 0,
        dailyCapacity: 0
    },
    dateAvailability: {},
    farmerData: {},
    appointmentSummary: null,
    emailDomain: {
        checking: false,
        valid: null,
        lastCheckedEmail: null,
        lastMessage: ''
    }
};

function getSessionWindowText(timeSlot) {
        return (timeSlot === 'AM') ? '8:00 AM – 12:00 NN' : '1:00 PM – 5:00 PM';
}

function getConfirmationDocumentHtml() {
        const summary = appState.appointmentSummary || {};
        const referenceNumber = summary.referenceNumber || appState.referenceNumber || '—';
        const fullName = summary.fullName || [summary.firstName, summary.middleName, summary.lastName, summary.suffix].filter(Boolean).join(' ') || '—';
        const farmerId = summary.farmerId || '—';
        const email = summary.email || '—';
        const contact = summary.contact || '—';
        const date = summary.date || '—';
        const timeSlot = summary.timeSlot || '—';
        const branchName = summary.branchName || `Branch ID ${summary.branchId || '—'}`;
        const volume = (summary.volume !== undefined && summary.volume !== null && summary.volume !== '') ? `${summary.volume} bags` : '—';
        const sessionWindow = (timeSlot === 'AM' || timeSlot === 'PM') ? getSessionWindowText(timeSlot) : '—';

        const safe = (value) => String(value ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

        return `<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>NFA Appointment Confirmation - ${safe(referenceNumber)}</title>
    <style>
        @page { size: A4; margin: 14mm; }
        body { font-family: Arial, Helvetica, sans-serif; color: #111; margin: 0; background: #f6f7f9; }
        .wrap { padding: 18px; }
        .doc { max-width: 820px; margin: 0 auto; background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; overflow: hidden; }
        .hdr { padding: 18px 22px; border-bottom: 1px solid #e5e7eb; }
        .hdr h1 { margin: 0; font-size: 18px; }
        .hdr .sub { margin-top: 4px; color: #6b7280; font-size: 12px; }
        .sec { padding: 18px 22px; }
        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px 18px; }
        .row { display: flex; justify-content: space-between; gap: 12px; padding: 10px 12px; border: 1px solid #eef0f3; border-radius: 10px; background: #fafafa; }
        .k { color: #374151; font-weight: 700; font-size: 12px; text-transform: uppercase; letter-spacing: .04em; }
        .v { font-weight: 700; color: #0b6a2b; }
        .note { margin-top: 14px; color: #374151; font-size: 13px; line-height: 1.45; }
        .muted { margin-top: 10px; color: #6b7280; font-size: 12px; }
        .foot { margin-top: 18px; padding-top: 12px; border-top: 1px dashed #e5e7eb; font-size: 12px; color: #6b7280; display: flex; justify-content: space-between; gap: 12px; }
        @media print {
            body { background: #fff; }
            .wrap { padding: 0; }
            .doc { border: none; border-radius: 0; }
        }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="doc">
            <div class="hdr">
                <h1>NFA PalayPortal — Appointment Confirmation</h1>
                <div class="sub">Last Updated: January 2026</div>
            </div>
            <div class="sec">
                <div class="grid">
                    <div class="row"><div class="k">Reference No.</div><div class="v">${safe(referenceNumber)}</div></div>
                    <div class="row"><div class="k">Status</div><div class="v">Pending Approval</div></div>
                    <div class="row"><div class="k">Branch</div><div>${safe(branchName)}</div></div>
                    <div class="row"><div class="k">Date</div><div>${safe(date)}</div></div>
                    <div class="row"><div class="k">Session</div><div>${safe(timeSlot)}${sessionWindow !== '—' ? ` (${safe(sessionWindow)})` : ''}</div></div>
                    <div class="row"><div class="k">Volume</div><div>${safe(volume)}</div></div>
                    <div class="row"><div class="k">Farmer</div><div>${safe(fullName)}</div></div>
                    <div class="row"><div class="k">Farmer ID</div><div>${safe(farmerId)}</div></div>
                    <div class="row"><div class="k">Email</div><div>${safe(email)}</div></div>
                    <div class="row"><div class="k">Contact</div><div>${safe(contact)}</div></div>
                </div>
                <div class="note">
                    <strong>Arrival guidance:</strong> You may arrive anytime within your selected session window. Please bring your Farmer ID and this confirmation.
                </div>
                <div class="muted">Keep this document for your records. For corrections, contact your NFA branch or support.</div>
                <div class="foot">
                    <div>Generated by NFA PalayPortal</div>
                    <div>${safe(new Date().toLocaleString())}</div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>`;
}

function getConfirmationDocumentPrintMarkup() {
                const summary = appState.appointmentSummary || {};
                const referenceNumber = summary.referenceNumber || appState.referenceNumber || '—';
                const fullName = summary.fullName || [summary.firstName, summary.middleName, summary.lastName, summary.suffix].filter(Boolean).join(' ') || '—';
                const farmerId = summary.farmerId || '—';
                const email = summary.email || '—';
                const contact = summary.contact || '—';
                const date = summary.date || '—';
                const timeSlot = summary.timeSlot || '—';
                const branchName = summary.branchName || `Branch ID ${summary.branchId || '—'}`;
                const volume = (summary.volume !== undefined && summary.volume !== null && summary.volume !== '') ? `${summary.volume} bags` : '—';
                const sessionWindow = (timeSlot === 'AM' || timeSlot === 'PM') ? getSessionWindowText(timeSlot) : '—';

                const safe = (value) => String(value ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

                return `
<style>
    @page { size: A4; margin: 14mm; }
    .nfa-doc { font-family: Arial, Helvetica, sans-serif; color: #111; line-height: 1.4; }
    .nfa-doc .doc { max-width: 820px; margin: 0 auto; background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; overflow: hidden; }
    .nfa-doc .hdr { padding: 18px 22px; border-bottom: 1px solid #e5e7eb; }
    .nfa-doc .hdr h1 { margin: 0; font-size: 18px; }
    .nfa-doc .hdr .sub { margin-top: 4px; color: #6b7280; font-size: 12px; }
    .nfa-doc .sec { padding: 18px 22px; }
    .nfa-doc .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px 18px; }
    .nfa-doc .row { display: flex; justify-content: space-between; gap: 12px; padding: 10px 12px; border: 1px solid #eef0f3; border-radius: 10px; background: #fafafa; }
    .nfa-doc .k { color: #374151; font-weight: 700; font-size: 12px; text-transform: uppercase; letter-spacing: .04em; }
    .nfa-doc .v { font-weight: 700; color: #0b6a2b; }
    .nfa-doc .note { margin-top: 14px; color: #374151; font-size: 13px; line-height: 1.45; }
    .nfa-doc .muted { margin-top: 10px; color: #6b7280; font-size: 12px; }
</style>

<div class="nfa-doc">
    <div class="doc">
        <div class="hdr">
            <h1>National Food Authority — Appointment Confirmation</h1>
            <div class="sub">System Version 1 • Last Updated: January 2026</div>
        </div>
        <div class="sec">
            <div class="grid">
                <div class="row"><div class="k">Reference No.</div><div class="v">${safe(referenceNumber)}</div></div>
                <div class="row"><div class="k">Status</div><div class="v">Pending Approval</div></div>
                <div class="row"><div class="k">Branch</div><div>${safe(branchName)}</div></div>
                <div class="row"><div class="k">Date</div><div>${safe(date)}</div></div>
                <div class="row"><div class="k">Session</div><div>${safe(timeSlot)}${sessionWindow !== '—' ? ` (${safe(sessionWindow)})` : ''}</div></div>
                <div class="row"><div class="k">Volume</div><div>${safe(volume)}</div></div>
                <div class="row"><div class="k">Farmer</div><div>${safe(fullName)}</div></div>
                <div class="row"><div class="k">Farmer ID</div><div>${safe(farmerId)}</div></div>
                <div class="row"><div class="k">Email</div><div>${safe(email)}</div></div>
                <div class="row"><div class="k">Contact</div><div>${safe(contact)}</div></div>
            </div>
            <div class="note"><strong>Arrival guidance:</strong> You may arrive anytime within your selected session window. Please bring your Farmer ID and this confirmation.</div>
            <div class="muted">Keep this document for your records.</div>
        </div>
    </div>
</div>`;
}

function printHtmlViaHiddenIframe(html) {
    const iframe = document.createElement('iframe');
    iframe.style.position = 'fixed';
    iframe.style.right = '0';
    iframe.style.bottom = '0';
    iframe.style.width = '0';
    iframe.style.height = '0';
    iframe.style.border = '0';
    iframe.setAttribute('aria-hidden', 'true');
    iframe.tabIndex = -1;

    const blob = new Blob([html], { type: 'text/html;charset=utf-8' });
    const url = URL.createObjectURL(blob);

    const cleanup = () => {
        try { URL.revokeObjectURL(url); } catch (e) {}
        try { iframe.remove(); } catch (e) {}
    };

    iframe.onload = () => {
        try {
            const win = iframe.contentWindow;
            if (!win) {
                cleanup();
                return;
            }

            win.focus();
            win.print();
            setTimeout(cleanup, 1000);
        } catch (e) {
            cleanup();
        }
    };

    document.body.appendChild(iframe);
    iframe.src = url;
}

function openPrintWindowWithHtml(html) {
    const w = window.open('', '_blank', 'noopener,noreferrer');
    if (!w) return false;
    w.document.open();
    w.document.write(html);
    w.document.close();
    w.focus();
    setTimeout(() => {
        w.print();
    }, 250);
    return true;
}

function downloadHtmlFile(filename, html) {
        const blob = new Blob([html], { type: 'text/html;charset=utf-8' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        a.remove();
        URL.revokeObjectURL(url);
}

// Exposed globally for inline onclick handlers
window.printConfirmationDocument = function () {
    const summary = appState.appointmentSummary || {};
    const ref = summary.referenceNumber || appState.referenceNumber;
    if (!ref) {
        alert('No confirmation details to print yet. Please submit an appointment first.');
        return;
    }

    const farmerId = summary.farmerId || '';
    const email = summary.email || '';

    const url = new URL('appointment_confirmation_print.php', window.location.href);
    url.searchParams.set('ref', String(ref));
    url.searchParams.set('auto', '1');
    if (farmerId) url.searchParams.set('farmer_id', String(farmerId));
    if (email) url.searchParams.set('email', String(email));

    // IMPORTANT:
    // - We intentionally do NOT use noopener/noreferrer here.
    //   Some browsers return `null` even when the tab opens (false "pop-up blocked" warning).
    // - We also want window.opener available so the print tab can focus/return to this page.
    const w = window.open(url.toString(), '_blank');
    if (!w) {
        alert('Print was blocked. Please allow pop-ups or try again.');
        return;
    }
    try { w.focus(); } catch (e) {}
};

window.downloadConfirmationDocument = function () {
    const summary = appState.appointmentSummary || {};
    const ref = summary.referenceNumber || appState.referenceNumber;
    if (!ref) {
        alert('No confirmation details to download yet. Please submit an appointment first.');
        return;
    }

    const farmerId = summary.farmerId || '';
    const email = summary.email || '';

    const url = new URL('appointment_confirmation_print.php', window.location.href);
    url.searchParams.set('ref', String(ref));
    url.searchParams.set('download', '1');
    if (farmerId) url.searchParams.set('farmer_id', String(farmerId));
    if (email) url.searchParams.set('email', String(email));

    window.location.href = url.toString();
};
const apiBaseUrl = 'php_helper/api.php';
let currentDate = new Date();

// --- Step Navigation System ---
function setupStepNavigation() {
    // Next button for step 1
    document.getElementById('nextStep1').addEventListener('click', () => {
        if (validateStep1()) {
            showStep(2);
            updateProgressTracker(2);
        }
    });
    
    // Back button for step 2
    document.getElementById('backStep2').addEventListener('click', () => {
        showStep(1);
        updateProgressTracker(1);
    });
    
    // Next button for step 2
    document.getElementById('nextStep2').addEventListener('click', () => {
        if (validateStep2()) {
            showStep(3);
            updateProgressTracker(3);
            updateAppointmentSummary();
            showAppointmentForm();
        }
    });
    
    // Back button for step 3
    document.getElementById('backStep3').addEventListener('click', () => {
        showStep(2);
        updateProgressTracker(2);
    });
}

function showStep(stepNumber) {
    // Hide all steps
    document.querySelectorAll('.wizard-step').forEach(step => {
        step.classList.remove('active');
    });
    
    // Show the requested step
    const stepElement = document.getElementById(`step${stepNumber}-container`);
    if (stepElement) {
        stepElement.classList.add('active');
        appState.currentStep = stepNumber;
        
        // Scroll to top of the step
        stepElement.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
}

function updateProgressTracker(step) {
    // Update progress bar
    const progressPercentage = ((step - 1) / 3) * 100;
    document.getElementById('progressBar').style.background = 
        `linear-gradient(to right, var(--nfa-green) 0%, var(--nfa-green) ${progressPercentage}%, #e0e0e0 ${progressPercentage}%, #e0e0e0 100%)`;
    
    // Update step indicators
    document.querySelectorAll('.step').forEach(stepElement => {
        const stepNum = parseInt(stepElement.dataset.step);
        stepElement.classList.remove('active');
        
        if (stepNum <= step) {
            stepElement.classList.add('active');
        }
    });
}

// --- Enhanced Event Listeners ---
function setupEnhancedEventListeners() {
    // Region selection
    document.getElementById('region').addEventListener('change', handleEnhancedRegionChange);
    
    // Branch selection
    document.getElementById('branch').addEventListener('change', handleEnhancedBranchChange);
    
    // Calendar navigation
    document.getElementById('prevMonth').addEventListener('click', () => changeMonth(-1));
    document.getElementById('nextMonth').addEventListener('click', () => changeMonth(1));
    
    // Time slot selection
    document.querySelectorAll('.slot-select-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const timeSlot = this.dataset.time;
            selectTimeSlot(timeSlot);
        });
    });
    
    // Form submission
    document.getElementById('farmerForm').addEventListener('submit', handleEnhancedFormSubmission);
    
    // Volume input validation
    document.getElementById('volume').addEventListener('input', validateVolumeInput);
    
    // Real-time form validation
    setupRealTimeValidation();
}

async function handleEnhancedRegionChange() {
    const regionSelect = document.getElementById('region');
    const regionId = regionSelect.value;
    const regionName = regionSelect.options[regionSelect.selectedIndex].dataset.name || '';
    
    appState.selectedRegion = regionId ? {
        id: regionId,
        name: regionName
    } : null;
    
    appState.selectedBranch = null;
    appState.appointmentFreeze = { effective_frozen: false, source: null, message: '' };
    
    // Reset dependent fields
    resetBranchSelection();
    resetCalendar();
    resetTimeSelection();
    hideAppointmentForm();

    renderAppointmentFreeze();
    
    if (regionId) {
        await fetchBranches(regionId);
        updateStep1NextButton();
    } else {
        disableBranchSelection();
    }
}

async function handleEnhancedBranchChange() {
    const branchSelect = document.getElementById('branch');
    const branchId = branchSelect.value;
    const branchName = branchSelect.options[branchSelect.selectedIndex].dataset.name || '';
    
    appState.selectedBranch = branchId ? {
        id: branchId,
        name: branchName
    } : null;
    
    appState.selectedDate = null;
    appState.selectedTime = null;
    appState.appointmentFreeze = { effective_frozen: false, source: null, message: '' };
    
    // Reset dependent fields
    resetCalendar();
    resetTimeSelection();
    hideAppointmentForm();

    renderAppointmentFreeze();
    
    if (branchId) {
        // Update branch info display
        updateBranchInfoDisplay();
        
        // Fetch branch capacity and availability
        currentDate = new Date();
        await fetchBranchInfo(branchId);
        
        // Show capacity info
        document.getElementById('capacityInfo').style.display = 'block';
        
        // Update step 1 next button
        updateStep1NextButton();
    } else {
        hideCapacityInfo();
        renderAppointmentFreeze();
    }
}

function updateBranchInfoDisplay() {
    const branchInfoCard = document.getElementById('branchInfoCard');
    const branchNameDisplay = document.getElementById('branchNameDisplay');
    const warehouseCapacity = document.getElementById('warehouseCapacity');
    
    if (appState.selectedBranch) {
        branchInfoCard.style.display = 'block';
        branchNameDisplay.textContent = appState.selectedBranch.name;
        warehouseCapacity.textContent = `${formatNumber(appState.branchCapacity.availableVolume)} bags`;
        
        // Animate the appearance
        branchInfoCard.style.animation = 'slideInUp 0.5s ease';
    } else {
        branchInfoCard.style.display = 'none';
    }
}

function updateStep1NextButton() {
    const nextButton = document.getElementById('nextStep1');
    const isFrozen = !!(appState.appointmentFreeze && appState.appointmentFreeze.effective_frozen);
    const isValid = appState.selectedRegion && appState.selectedBranch && !isFrozen;
    
    nextButton.disabled = !isValid;
    
    if (isFrozen) {
        nextButton.innerHTML = 'Appointment Intake Paused <i class="fas fa-lock"></i>';
        nextButton.style.cursor = 'not-allowed';
    } else if (isValid) {
        nextButton.innerHTML = 'Continue to Date Selection <i class="fas fa-arrow-right"></i>';
        nextButton.style.cursor = 'pointer';
    } else {
        nextButton.innerHTML = 'Select Location First <i class="fas fa-lock"></i>';
        nextButton.style.cursor = 'not-allowed';
    }
}

// --- Enhanced Calendar Functions ---
function changeMonth(direction) {
    currentDate.setMonth(currentDate.getMonth() + direction);
    
    // When a branch is selected, refresh availability from the database
    if (appState.selectedBranch && appState.selectedBranch.id) {
        fetchBranchInfo(appState.selectedBranch.id);
    } else {
        updateCalendarDisplay();
    }
    
    resetTimeSelection();
    updateStep2NextButton();
}

function selectDate(date, dayElement) {
    const minSelectable = getMinAppointmentDate();
    const selected = new Date(date);
    selected.setHours(0, 0, 0, 0);
    if (selected < minSelectable) {
        alert('Appointments must be scheduled at least 1 day ahead. Please select a date starting tomorrow.');
        return;
    }

    // Remove selection from all days
    document.querySelectorAll('.calendar-day').forEach(day => {
        day.classList.remove('selected');
    });
    
    // Add selection to clicked day
    dayElement.classList.add('selected');
    appState.selectedDate = date;
    
    // Reset time selection
    appState.selectedTime = null;
    resetTimeSelection();
    
    // Show time slots for selected date
    showTimeSlots(formatDate(date));
    
    // Update step 2 next button
    updateStep2NextButton();
    
    // Animate the selection
    dayElement.style.animation = 'pulse 0.5s ease';
    setTimeout(() => {
        dayElement.style.animation = '';
    }, 500);
}

function selectTimeSlot(timeSlot) {
    // Update state
    appState.selectedTime = timeSlot;
    
    // Update UI
    document.querySelectorAll('.time-slot-card').forEach(card => {
        card.classList.remove('selected');
    });
    
    const selectedCard = document.querySelector(`.time-slot-card[data-time="${timeSlot}"]`);
    selectedCard.classList.add('selected');
    
    // Update selected slot summary
    updateSelectedSlotSummary();
    
    // Enable step 2 next button
    updateStep2NextButton();
}

function updateSelectedSlotSummary() {
    const summary = document.getElementById('selectedSlotSummary');
    const dateDisplay = document.getElementById('summaryDateDisplay');
    const timeDisplay = document.getElementById('summaryTimeDisplay');
    const windowDisplay = document.getElementById('summaryWindowDisplay');
    
    if (appState.selectedDate && appState.selectedTime) {
        dateDisplay.textContent = formatDateDisplay(appState.selectedDate);
        const isMorning = appState.selectedTime === 'AM';
        timeDisplay.textContent = isMorning ? 'Morning Session (AM)' : 'Afternoon Session (PM)';

        if (windowDisplay) {
            windowDisplay.textContent = isMorning ? '8:00 AM - 12:00 PM' : '1:00 PM - 5:00 PM';
        }
        
        summary.style.display = 'block';
        summary.style.animation = 'slideInUp 0.5s ease';
    } else {
        summary.style.display = 'none';
    }
}

function updateStep2NextButton() {
    const nextButton = document.getElementById('nextStep2');
    const isFrozen = !!(appState.appointmentFreeze && appState.appointmentFreeze.effective_frozen);
    const isValid = appState.selectedDate && appState.selectedTime && !isFrozen;
    
    nextButton.disabled = !isValid;
    
    if (isFrozen) {
        nextButton.innerHTML = 'Appointment Intake Paused <i class="fas fa-lock"></i>';
        nextButton.style.cursor = 'not-allowed';
    } else if (isValid) {
        nextButton.innerHTML = 'Continue to Details <i class="fas fa-arrow-right"></i>';
        nextButton.style.cursor = 'pointer';
    } else {
        nextButton.innerHTML = 'Select Date & Time First <i class="fas fa-lock"></i>';
        nextButton.style.cursor = 'not-allowed';
    }
}

// --- Form Validation ---
function validateStep1() {
    const isFrozen = !!(appState.appointmentFreeze && appState.appointmentFreeze.effective_frozen);
    return appState.selectedRegion && appState.selectedBranch && !isFrozen;
}

function validateStep2() {
    const isFrozen = !!(appState.appointmentFreeze && appState.appointmentFreeze.effective_frozen);
    return appState.selectedDate && appState.selectedTime && !isFrozen;
}

function renderAppointmentFreeze() {
    const noticeEl = document.getElementById('freezeNotice');
    const noticeTextEl = document.getElementById('freezeNoticeText');
    const statusEl = document.getElementById('branchStatus');

    const freeze = appState.appointmentFreeze || { effective_frozen: false, message: '' };
    const frozen = !!freeze.effective_frozen;

    if (noticeEl && noticeTextEl) {
        if (frozen && appState.selectedBranch) {
            noticeEl.style.display = 'flex';
            noticeTextEl.textContent = (freeze.message || 'New appointments are temporarily frozen for this branch.');
        } else {
            noticeEl.style.display = 'none';
            noticeTextEl.textContent = '';
        }
    }

    if (statusEl && appState.selectedBranch && frozen) {
        statusEl.className = 'branch-status frozen';
        statusEl.textContent = 'Frozen';
    }

    if (frozen && appState.currentStep && appState.currentStep > 1) {
        resetCalendar();
        resetTimeSelection();
        hideAppointmentForm();
        showStep(1);
    }

    updateStep1NextButton();
    updateStep2NextButton();
}

function setupRealTimeValidation() {
    setupContactNumberMasking();
    setupEmailDomainValidation();

    // Email validation
    document.getElementById('email').addEventListener('blur', function() {
        validateEmail(this);
    });
    
    // Contact number validation
    document.getElementById('contact').addEventListener('blur', function() {
        validateContactNumber(this);
    });
    
    // Name validation
    document.getElementById('firstName').addEventListener('blur', function() {
        validateName(this, 'First name');
    });
    
    document.getElementById('lastName').addEventListener('blur', function() {
        validateName(this, 'Last name');
    });
}

function setupContactNumberMasking() {
    const input = document.getElementById('contact');
    if (!input || input.dataset.masked === '1') return;
    input.dataset.masked = '1';

    const allowedControlKeys = new Set([
        'Backspace', 'Delete', 'Tab', 'Escape', 'Enter',
        'ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown',
        'Home', 'End'
    ]);

    input.addEventListener('keydown', (e) => {
        if (allowedControlKeys.has(e.key)) return;
        if (e.ctrlKey || e.metaKey) return;

        // Block letters/symbols from being entered.
        if (!/^[0-9]$/.test(e.key)) {
            e.preventDefault();
            return;
        }

        // Stop at 11 digits.
        const digits = normalizePHMobileDigits(input.value);
        if (digits.length >= 11) {
            e.preventDefault();
        }
    });

    input.addEventListener('paste', (e) => {
        e.preventDefault();
        const text = (e.clipboardData || window.clipboardData).getData('text') || '';
        input.value = formatPHMobile(normalizePHMobileDigits(text));
        validateContactNumber(input);
    });

    input.addEventListener('input', () => {
        const formatted = formatPHMobile(normalizePHMobileDigits(input.value));
        if (input.value !== formatted) input.value = formatted;
        validateContactNumber(input);
    });
}

function normalizePHMobileDigits(value) {
    const rawDigits = String(value || '').replace(/\D+/g, '');

    // Normalize +63 / 63 into 09XXXXXXXXX.
    if (rawDigits.startsWith('63') && rawDigits.length >= 12) {
        return ('0' + rawDigits.slice(2)).slice(0, 11);
    }

    return rawDigits.slice(0, 11);
}

function formatPHMobile(digits) {
    const d = String(digits || '').replace(/\D+/g, '').slice(0, 11);
    if (d.length <= 4) return d;
    if (d.length <= 7) return `${d.slice(0, 4)}-${d.slice(4)}`;
    return `${d.slice(0, 4)}-${d.slice(4, 7)}-${d.slice(7)}`;
}

function setupEmailDomainValidation() {
    const input = document.getElementById('email');
    if (!input || input.dataset.domainWired === '1') return;
    input.dataset.domainWired = '1';

    let debounceTimer = null;
    input.addEventListener('input', () => {
        // Reset domain state as user edits.
        appState.emailDomain.valid = null;
        appState.emailDomain.checking = false;
        appState.emailDomain.lastCheckedEmail = null;
        appState.emailDomain.lastMessage = '';
        input.classList.remove('valid', 'checking');
        clearStatusMessage(input);

        if (debounceTimer) clearTimeout(debounceTimer);
        debounceTimer = setTimeout(() => {
            validateEmail(input);
        }, 450);
    });
}

function showStatusMessage(input, message) {
    let statusElement = input.parentNode.querySelector('.status-message');
    if (!statusElement) {
        statusElement = document.createElement('div');
        statusElement.className = 'status-message';
        input.parentNode.appendChild(statusElement);
    }
    statusElement.textContent = message;
}

function clearStatusMessage(input) {
    const statusElement = input.parentNode.querySelector('.status-message');
    if (statusElement) statusElement.remove();
}

async function validateEmailDomainOnServer(email, input) {
    if (appState.emailDomain.checking && appState.emailDomain.lastCheckedEmail === email) return;

    appState.emailDomain.checking = true;
    appState.emailDomain.valid = null;
    appState.emailDomain.lastCheckedEmail = email;
    appState.emailDomain.lastMessage = '';

    input.classList.add('checking');
    input.classList.remove('valid');
    clearInputError(input);
    showStatusMessage(input, 'Checking email domain…');

    try {
        const resp = await fetch(`${apiBaseUrl}?action=validateEmailDomain`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ email })
        });

        const data = await resp.json().catch(() => null);
        const ok = !!(data && data.success && data.valid === true);

        appState.emailDomain.valid = ok;
        appState.emailDomain.lastMessage = (data && data.message) ? String(data.message) : '';

        if (ok) {
            input.classList.remove('checking');
            input.classList.add('valid');
            clearStatusMessage(input);
            clearInputError(input);
        } else {
            input.classList.remove('checking', 'valid');
            clearStatusMessage(input);
            showInputError(input, appState.emailDomain.lastMessage || 'Email domain does not appear to exist');
        }
    } catch (e) {
        appState.emailDomain.valid = false;
        appState.emailDomain.lastMessage = 'Unable to validate email domain right now. Please check the email and try again.';
        input.classList.remove('checking', 'valid');
        clearStatusMessage(input);
        showInputError(input, appState.emailDomain.lastMessage);
    } finally {
        appState.emailDomain.checking = false;
    }
}

function validateEmail(input) {
    const email = input.value.trim();
    const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    
    if (!emailPattern.test(email)) {
        showInputError(input, 'Please enter a valid email address');
        return false;
    }

    const domain = (email.split('@')[1] || '').toLowerCase();
    const commonDomainTypos = {
        'gmial.com': 'gmail.com',
        'gmal.com': 'gmail.com',
        'gmail.con': 'gmail.com',
        'hotmial.com': 'hotmail.com',
        'hotmal.com': 'hotmail.com',
        'outlook.con': 'outlook.com',
        'yaho.com': 'yahoo.com',
        'yahoo.con': 'yahoo.com'
    };

    if (commonDomainTypos[domain]) {
        showInputError(input, `Email domain looks incorrect. Did you mean ${email.replace(/@.*/, '@' + commonDomainTypos[domain])}?`);
        return false;
    }

    // Require a realistic domain form.
    if (!/^[a-z0-9.-]+\.[a-z]{2,}$/.test(domain) || domain.includes('..') || domain.startsWith('.') || domain.endsWith('.')) {
        showInputError(input, 'Please enter an email with a valid domain (e.g., gmail.com)');
        return false;
    }

    // If already validated for this email, accept.
    if (appState.emailDomain.lastCheckedEmail === email && appState.emailDomain.valid === true) {
        clearInputError(input);
        clearStatusMessage(input);
        input.classList.remove('checking');
        input.classList.add('valid');
        return true;
    }

    // Kick off async validation and block submission until it's done.
    validateEmailDomainOnServer(email, input);
    return false;
    
    // (Validation result will be applied asynchronously.)
}

function validateContactNumber(input) {
    const contactDigits = normalizePHMobileDigits(input.value);
    const contactPattern = /^09\d{9}$/;
    
    if (!contactPattern.test(contactDigits) || contactDigits.length !== 11) {
        showInputError(input, 'Please enter a valid Philippine mobile number (09XX-XXX-XXXX)');
        input.classList.remove('valid');
        return false;
    }
    
    clearInputError(input);
    input.classList.add('valid');
    return true;
}

function validateName(input, fieldName) {
    const name = input.value.trim();
    const namePattern = /^[a-zA-Z\s\-']+$/;
    
    if (!namePattern.test(name) || name.length < 2) {
        showInputError(input, `${fieldName} must contain only letters and be at least 2 characters`);
        return false;
    }
    
    clearInputError(input);
    return true;
}

function validateVolumeInput() {
    const volumeInput = document.getElementById('volume');
    const volume = parseInt(volumeInput.value) || 0;
    const remainingCapacity = appState.branchCapacity.availableVolume;
    const warningElement = document.getElementById('volumeWarning');
    
    // Update remaining capacity display
    document.getElementById('remainingCapacity').textContent = 
        `Available capacity: ${formatNumber(remainingCapacity)} bags`;
    
    if (volume > remainingCapacity) {
        volumeInput.classList.add('error');
        warningElement.style.display = 'block';
        return false;
    } else {
        volumeInput.classList.remove('error');
        warningElement.style.display = 'none';
        return true;
    }
}

function showInputError(input, message) {
    input.classList.add('error');
    input.classList.remove('valid', 'checking');
    clearStatusMessage(input);
    
    // Create or update error message
    let errorElement = input.parentNode.querySelector('.error-message');
    if (!errorElement) {
        errorElement = document.createElement('div');
        errorElement.className = 'error-message';
        input.parentNode.appendChild(errorElement);
    }
    
    errorElement.textContent = message;
    errorElement.style.color = 'var(--error-red)';
    errorElement.style.fontSize = '0.85rem';
    errorElement.style.marginTop = '0.25rem';
}

function clearInputError(input) {
    input.classList.remove('error');
    
    // Remove error message
    const errorElement = input.parentNode.querySelector('.error-message');
    if (errorElement) {
        errorElement.remove();
    }
}

// --- Enhanced Form Submission ---
async function handleEnhancedFormSubmission(e) {
    e.preventDefault();
    
    // Validate all fields
    if (!validateStep3()) {
        return;
    }
    
    // Validate reCAPTCHA
    if (!validateRecaptcha()) {
        alert('Please complete the security verification.');
        return;
    }
    
    // Validate volume against capacity
    const volume = parseInt(document.getElementById('volume').value);
    if (volume > appState.branchCapacity.availableVolume) {
        alert(`Volume exceeds available capacity. Maximum allowed: ${formatNumber(appState.branchCapacity.availableVolume)} bags`);
        return;
    }
    
    // Show loading overlay
    showLoadingOverlay();
    
    // Prepare form data
    const formData = prepareFormData();
    
    // Submit appointment
    const result = await submitAppointment(formData);
    
    // Hide loading overlay
    hideLoadingOverlay();
    
    if (result.success) {
        // Store appointment summary for confirmation page
        const sessionLabel = (appState.selectedTime === 'AM') ? 'Morning' : 'Afternoon';
        const fullName = [formData.firstName, (formData.middleName || '').trim(), formData.lastName, (formData.suffix || '').trim()]
            .filter(part => part && String(part).trim().length > 0)
            .join(' ');
        appState.appointmentSummary = {
            referenceNumber: result.referenceNumber,
            branch: appState.selectedBranch.name,
            branchName: appState.selectedBranch.name,
            branchId: appState.selectedBranch.id,
            date: formatDateDisplay(appState.selectedDate),
            timeSlot: appState.selectedTime,
            dateTime: `${formatDateDisplay(appState.selectedDate)} (${sessionLabel})`,
            farmerName: fullName,
            fullName,
            firstName: formData.firstName,
            middleName: formData.middleName,
            lastName: formData.lastName,
            suffix: formData.suffix,
            farmerId: formData.farmer_id,
            email: formData.email,
            contact: formData.contact,
            volume: formData.volume
        };
        
        // Update confirmation page
        updateConfirmationPage();
        
        // Move to step 4 (confirmation)
        showStep(4);
        updateProgressTracker(4);
        
        // Reset reCAPTCHA
        if (typeof grecaptcha !== 'undefined' && grecaptcha.reset) {
            grecaptcha.reset();
        }
    } else {
        alert(`Failed to submit appointment: ${result.error}`);
    }
}

function validateStep3() {
    let isValid = true;
    
    // Validate all required fields
    const requiredFields = [
        { id: 'firstName', validator: (input) => validateName(input, 'First name') },
        { id: 'lastName', validator: (input) => validateName(input, 'Last name') },
        { id: 'farmerId', validator: (input) => input.value.trim().length > 0 },
        { id: 'farmerType', validator: (input) => input.value !== '' },
        { id: 'email', validator: (input) => validateEmail(input) },
        { id: 'contact', validator: (input) => validateContactNumber(input) },
        { id: 'gender', validator: (input) => input.value !== '' },
        { id: 'volume', validator: (input) => {
            const val = parseInt(input.value, 10) || 0;
            return validateVolumeInput() && val > 0;
        } }
    ];
    
    requiredFields.forEach(field => {
        const input = document.getElementById(field.id);
        
        if (!field.validator(input)) {
            isValid = false;
            input.classList.add('error');
        } else {
            input.classList.remove('error');
        }
    });
    
    return isValid;
}

function validateRecaptcha() {
    if (typeof grecaptcha === 'undefined') return true;
    
    const response = grecaptcha.getResponse();
    return response.length > 0;
}

function prepareFormData() {
    return {
        // Personal Information
        farmer_id: document.getElementById('farmerId').value.trim(),
        suffix: document.getElementById('suffix').value,
        firstName: document.getElementById('firstName').value.trim(),
        middleName: document.getElementById('middleName').value.trim(),
        lastName: document.getElementById('lastName').value.trim(),
        email: document.getElementById('email').value.trim(),
        contact: document.getElementById('contact').value.trim(),
        gender: document.getElementById('gender').value,
        volume: parseInt(document.getElementById('volume').value),
        farmer_type_id: document.getElementById('farmerType').value,
        
        // Appointment Information
        branch_id: appState.selectedBranch.id,
        date: formatDate(appState.selectedDate),
        time_slot: appState.selectedTime,

        // Reference number shown in UI (final upon submission)
        reference_number: appState.referenceNumber || '',
        
        // Security
        g_recaptcha_response: (typeof grecaptcha !== 'undefined') ? grecaptcha.getResponse() : ''
    };
}

function updateConfirmationPage() {
    const summary = appState.appointmentSummary;
    
    if (summary) {
        document.getElementById('referenceNumber').textContent = summary.referenceNumber;
        document.getElementById('confirmationBranch').textContent = summary.branch;
        document.getElementById('confirmationDateTime').textContent = summary.dateTime;
        document.getElementById('confirmationName').textContent = summary.farmerName;
        document.getElementById('confirmationFarmerId').textContent = summary.farmerId;

        const trackLink = document.getElementById('trackStatusLink');
        if (trackLink && summary.referenceNumber) {
            trackLink.href = `appointment_tracker.php?ref=${encodeURIComponent(summary.referenceNumber)}`;
        }
    }
}

// --- UI Helper Functions ---
function updateAppointmentSummary() {
    if (appState.selectedBranch && appState.selectedDate && appState.selectedTime) {
        document.getElementById('summaryBranchFull').textContent = appState.selectedBranch.name;
        document.getElementById('summaryDateTime').textContent = 
            `${formatDateDisplay(appState.selectedDate)} (${appState.selectedTime === 'AM' ? 'Morning' : 'Afternoon'})`;

        ensureReferenceNumber();
    }
}

function resetBranchSelection() {
    const branchSelect = document.getElementById('branch');
    branchSelect.innerHTML = '<option value="">Choose a branch</option>';
    branchSelect.disabled = true;
    
    document.getElementById('branchInfoCard').style.display = 'none';
    document.getElementById('capacityInfo').style.display = 'none';

    resetReferenceNumber();
}

function disableBranchSelection() {
    document.getElementById('branch').disabled = true;
}

function hideCapacityInfo() {
    document.getElementById('capacityInfo').style.display = 'none';
}

function resetCalendar() {
    appState.selectedDate = null;
    appState.selectedTime = null;
    
    document.getElementById('calendarContainer').style.display = 'none';
    document.getElementById('timeSlots').style.display = 'none';
    document.getElementById('selectedSlotSummary').style.display = 'none';
    
    document.querySelectorAll('.calendar-day.selected').forEach(day => {
        day.classList.remove('selected');
    });
    
    document.querySelectorAll('.time-slot-card.selected').forEach(card => {
        card.classList.remove('selected');
    });

    resetReferenceNumber();
}

function resetTimeSelection() {
    appState.selectedTime = null;
    
    document.getElementById('timeSlots').style.display = 'none';
    document.getElementById('selectedSlotSummary').style.display = 'none';
    
    document.querySelectorAll('.time-slot-card.selected').forEach(card => {
        card.classList.remove('selected');
    });

    resetReferenceNumber();
}

function hideAppointmentForm() {
    document.getElementById('appointmentForm').style.display = 'none';
}

function showAppointmentForm() {
    document.getElementById('appointmentForm').style.display = 'block';
    ensureReferenceNumber();
}

function showLoadingOverlay() {
    if (window.NFALoading && typeof window.NFALoading.show === 'function') {
        window.NFALoading.show('Processing your request…');
        return;
    }
    document.getElementById('loadingOverlay').classList.add('active');
}

function hideLoadingOverlay() {
    if (window.NFALoading && typeof window.NFALoading.hide === 'function') {
        window.NFALoading.hide();
        return;
    }
    document.getElementById('loadingOverlay').classList.remove('active');
}

function resetReferenceNumber() {
    appState.referenceNumber = null;
    const el = document.getElementById('summaryReferenceNumber');
    if (el) el.textContent = '--';
}

async function ensureReferenceNumber() {
    if (!appState.selectedBranch || !appState.selectedDate || !appState.selectedTime) return;

    const el = document.getElementById('summaryReferenceNumber');
    if (appState.referenceNumber) {
        if (el) el.textContent = appState.referenceNumber;
        return;
    }

    if (el) el.textContent = 'Generating...';

    try {
        const resp = await fetch(`${apiBaseUrl}?action=generateReferenceNumber`);
        const data = await resp.json();
        if (!data || !data.success || !data.referenceNumber) {
            throw new Error((data && data.error) ? data.error : 'Failed to generate reference number');
        }
        appState.referenceNumber = data.referenceNumber;
        if (el) el.textContent = appState.referenceNumber;
    } catch (err) {
        console.error('Reference number generation failed:', err);
        appState.referenceNumber = null;
        if (el) el.textContent = '--';
    }
}

// --- Utility Functions (Keep from original but update as needed) ---
function formatDate(date) {
    if (!date) return '';
    const d = new Date(date);
    const month = '' + (d.getMonth() + 1);
    const day = '' + d.getDate();
    const year = d.getFullYear();

    return [year, month.padStart(2, '0'), day.padStart(2, '0')].join('-');
}

function formatDateDisplay(date) {
    if (!date) return 'N/A';
    const options = { 
        weekday: 'long', 
        year: 'numeric', 
        month: 'long', 
        day: 'numeric' 
    };
    return date.toLocaleDateString('en-US', options);
}

function formatNumber(number) {
    return new Intl.NumberFormat('en-PH', { maximumFractionDigits: 0 }).format(Math.round(number));
}

function getMinAppointmentDate() {
    // Branches accept appointments at least 1 day ahead (tomorrow and beyond)
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    const minDate = new Date(today);
    minDate.setDate(minDate.getDate() + 1);

    // Skip holidays (if holidays were returned by the API for the current view)
    const holidaySet = new Set(Array.isArray(appState.holidays) ? appState.holidays : []);
    while (holidaySet.size > 0 && holidaySet.has(formatDate(minDate))) {
        minDate.setDate(minDate.getDate() + 1);
    }

    return minDate;
}

// --- Calendar Rendering & Time Slots ---
function updateCalendarDisplay() {
    const monthNames = [
        'January','February','March','April','May','June',
        'July','August','September','October','November','December'
    ];
    const calendarEl = document.getElementById('calendar');
    const monthEl = document.getElementById('currentMonth');
    const yearEl = document.getElementById('currentYear');
    const calendarContainer = document.getElementById('calendarContainer');
    
    if (!calendarEl) return;
    calendarContainer.style.display = 'block';
    
    const year = currentDate.getFullYear();
    const month = currentDate.getMonth();
    monthEl.textContent = monthNames[month];
    yearEl.textContent = year;
    
    calendarEl.innerHTML = '';
    const firstDay = new Date(year, month, 1);
    const lastDay = new Date(year, month + 1, 0);
    const startWeekday = firstDay.getDay();
    const minSelectable = getMinAppointmentDate();
    const holidaySet = new Set(Array.isArray(appState.holidays) ? appState.holidays : []);
    
    // Leading blanks
    for (let i = 0; i < startWeekday; i++) {
        const blank = document.createElement('div');
        blank.className = 'calendar-day blank';
        calendarEl.appendChild(blank);
    }
    
    for (let day = 1; day <= lastDay.getDate(); day++) {
        const dateObj = new Date(year, month, day);
        const dateStr = formatDate(dateObj);
        const dayEl = document.createElement('div');
        dayEl.className = 'calendar-day';
        const dayNumberEl = document.createElement('div');
        dayNumberEl.className = 'day-number';
        dayNumberEl.textContent = day;
        dayEl.appendChild(dayNumberEl);
        
        const availability = appState.dateAvailability[dateStr];
        const isWeekend = dateObj.getDay() === 0 || dateObj.getDay() === 6;
        const isHoliday = holidaySet.has(dateStr);
        const isTooSoon = dateObj < minSelectable;
        const isDisabled = isTooSoon || isHoliday || (availability && availability.is_disabled);
        
        if (isWeekend) {
            dayEl.classList.add('weekend');
        }

        if (isHoliday) {
            dayEl.classList.add('holiday');

            const holidayName = (appState.holidayNames && appState.holidayNames[dateStr])
                ? appState.holidayNames[dateStr]
                : 'Holiday';

            dayEl.dataset.holiday = holidayName;
            dayEl.title = holidayName;

            const holidayLabel = document.createElement('div');
            holidayLabel.className = 'day-holiday';
            holidayLabel.textContent = holidayName;
            dayEl.appendChild(holidayLabel);
        }
        
        if (isDisabled) {
            dayEl.classList.add('unavailable');
        } else {
            dayEl.classList.add('available');
            dayEl.addEventListener('click', () => selectDate(dateObj, dayEl));
        }
        
        calendarEl.appendChild(dayEl);
    }
}

function showTimeSlots(dateStr) {
    const slotData = appState.dateAvailability[dateStr] || {};
    const amRemaining = typeof slotData.am_remaining === 'number' ? slotData.am_remaining : appState.branchCapacity.amCapacity;
    const pmRemaining = typeof slotData.pm_remaining === 'number' ? slotData.pm_remaining : appState.branchCapacity.pmCapacity;
    const isDisabled = slotData.is_disabled;
    
    const timeSlotsEl = document.getElementById('timeSlots');
    const selectedDateDisplay = document.getElementById('selectedDateDisplay');
    const amAvailEl = document.getElementById('amAvailability');
    const pmAvailEl = document.getElementById('pmAvailability');
    
    selectedDateDisplay.textContent = formatDateDisplay(new Date(dateStr));
    amAvailEl.textContent = amRemaining;
    pmAvailEl.textContent = pmRemaining;
    timeSlotsEl.style.display = 'block';
    
    ['AM','PM'].forEach(slot => {
        const card = document.querySelector(`.time-slot-card[data-time="${slot}"]`);
        const button = card ? card.querySelector('.slot-select-btn') : null;
        if (!card || !button) return;
        
        let remaining = slot === 'AM' ? amRemaining : pmRemaining;
        const disabled = isDisabled || remaining <= 0;
        
        if (disabled) {
            card.classList.add('disabled');
            button.disabled = true;
        } else {
            card.classList.remove('disabled');
            button.disabled = false;
        }
    });
}

// --- API Functions ---
async function fetchRegions() {
    const regionSelect = document.getElementById('region');
    if (!regionSelect) return;
    
    regionSelect.innerHTML = '<option value="">Choose your region</option>';
    
    try {
        const response = await fetch(`${apiBaseUrl}?action=getRegions`);
        const data = await response.json();
        if (!data.success) throw new Error(data.error || 'Failed to load regions');
        
        data.data.forEach(region => {
            const opt = document.createElement('option');
            opt.value = region.region_id;
            opt.textContent = region.region_name;
            opt.dataset.name = region.region_name;
            regionSelect.appendChild(opt);
        });
    } catch (err) {
        console.error('Error fetching regions:', err);
        alert('Failed to load regions. Please refresh the page.');
    }
}

async function fetchBranches(regionId) {
    const branchSelect = document.getElementById('branch');
    if (!branchSelect) return;
    
    branchSelect.disabled = true;
    branchSelect.innerHTML = '<option value="">Loading branches...</option>';
    
    try {
        const response = await fetch(`${apiBaseUrl}?action=getBranches&region_id=${encodeURIComponent(regionId)}`);
        const data = await response.json();
        if (!data.success) throw new Error(data.error || 'Failed to load branches');
        
        branchSelect.innerHTML = '<option value="">Choose a branch</option>';
        data.data.forEach(branch => {
            const opt = document.createElement('option');
            opt.value = branch.branch_id;
            opt.textContent = branch.branch_name;
            opt.dataset.name = branch.branch_name;
            branchSelect.appendChild(opt);
        });
        branchSelect.disabled = false;
    } catch (err) {
        console.error('Error fetching branches:', err);
        alert('Failed to load branches. Please try again.');
        branchSelect.innerHTML = '<option value="">Choose a branch</option>';
        branchSelect.disabled = true;
    }
}

async function fetchFarmerTypes() {
    const farmerTypeSelect = document.getElementById('farmerType');
    if (!farmerTypeSelect) return;
    
    farmerTypeSelect.innerHTML = '<option value="">Loading types...</option>';
    
    try {
        const response = await fetch(`${apiBaseUrl}?action=getFarmerTypes`);
        const data = await response.json();
        if (!data.success) throw new Error(data.error || 'Failed to load farmer types');
        
        farmerTypeSelect.innerHTML = '<option value="">Select Farmer Type</option>';
        data.data.forEach(type => {
            const opt = document.createElement('option');
            opt.value = type.farmer_type_id;
            opt.textContent = type.type_name;
            farmerTypeSelect.appendChild(opt);
        });
    } catch (err) {
        console.error('Error fetching farmer types:', err);
        alert('Failed to load farmer types. Please refresh the page.');
        farmerTypeSelect.innerHTML = '<option value="">Select Farmer Type</option>';
    }
}

async function fetchBranchInfo(branchId) {
    const baseDate = currentDate instanceof Date ? currentDate : new Date();
    const startDate = new Date(baseDate.getFullYear(), baseDate.getMonth(), 1);
    const endDate = new Date(baseDate.getFullYear(), baseDate.getMonth() + 1, 0);
    
    const startStr = formatDate(startDate);
    const endStr = formatDate(endDate);
    
    try {
        const response = await fetch(`${apiBaseUrl}?action=getBranchInfo&branch_id=${encodeURIComponent(branchId)}&start_date=${startStr}&end_date=${endStr}`);
        const data = await response.json();
        if (!data.success) throw new Error(data.error || 'Failed to load branch info');
        
        const capacityInfo = data.capacity_info || {};
        const slotCap = data.default_slot_capacity || {};

        const freezeInfo = data.appointment_freeze || {};
        appState.appointmentFreeze = {
            effective_frozen: !!freezeInfo.effective_frozen,
            source: freezeInfo.source || null,
            message: (freezeInfo.message || '').toString()
        };
        
        appState.branchCapacity = {
            availableVolume: capacityInfo.available_volume || 0,
            amCapacity: slotCap.capacity_am || 0,
            pmCapacity: slotCap.capacity_pm || 0,
            dailyCapacity: (slotCap.capacity_am || 0) + (slotCap.capacity_pm || 0)
        };
        appState.dateAvailability = data.daily_availability || {};
        appState.holidays = Array.isArray(data.holidays) ? data.holidays : [];

        appState.holidayNames = {};
        if (Array.isArray(data.holiday_details)) {
            data.holiday_details.forEach((row) => {
                const d = row && row.holiday_date;
                if (!d) return;
                appState.holidayNames[d] = (row.holiday_name || 'Holiday').toString();
            });
        }
        
        // Update capacity UI
        const totalCapacity = capacityInfo.total_capacity || 0;
        document.getElementById('availableVolume').textContent = `${formatNumber(appState.branchCapacity.availableVolume)} bags`;
        document.getElementById('warehouseCapacity').textContent = `${formatNumber(totalCapacity)} bags`;

        const dailyAppointmentsEl = document.getElementById('dailyAppointments');
        if (dailyAppointmentsEl) {
            dailyAppointmentsEl.textContent = `${appState.branchCapacity.dailyCapacity} slots/day`;
        }

        document.getElementById('amSlots').textContent = appState.branchCapacity.amCapacity;
        document.getElementById('pmSlots').textContent = appState.branchCapacity.pmCapacity;
        document.getElementById('totalSlots').textContent = appState.branchCapacity.dailyCapacity;
        
        const capacityFill = document.getElementById('capacityFill');
        if (totalCapacity > 0) {
            const pct = Math.max(0, Math.min(100, (appState.branchCapacity.availableVolume / totalCapacity) * 100));
            capacityFill.style.width = `${pct}%`;
        } else {
            capacityFill.style.width = '0%';
        }
        
        const statusEl = document.getElementById('branchStatus');
        if (statusEl) {
            let statusClass = 'available';
            let statusText = 'Available';
            if (appState.branchCapacity.availableVolume <= 0) {
                statusClass = 'full';
                statusText = 'Full';
            } else if (totalCapacity > 0 && (appState.branchCapacity.availableVolume / totalCapacity) < 0.3) {
                statusClass = 'limited';
                statusText = 'Limited';
            }
            statusEl.className = `branch-status ${statusClass}`;
            statusEl.textContent = statusText;
        }

        renderAppointmentFreeze();
        
        updateCalendarDisplay();
    } catch (err) {
        console.error('Error fetching branch info:', err);
        alert('Failed to load branch capacity and availability. Please try again later.');
    }
}

async function submitAppointment(formData) {
    try {
        const response = await fetch(`${apiBaseUrl}?action=submitAppointment`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(formData)
        });
        return await response.json();
    } catch (err) {
        console.error('Error submitting appointment:', err);
        return { success: false, error: 'Network error. Please try again.' };
    }
}

// --- Public helper for "Schedule Another Appointment" button ---
function resetForm() {
    const farmerForm = document.getElementById('farmerForm');
    if (farmerForm) {
        farmerForm.reset();
    }
    
    // Reset app state
    appState.currentStep = 1;
    appState.selectedRegion = null;
    appState.selectedBranch = null;
    appState.selectedDate = null;
    appState.selectedTime = null;
    appState.branchCapacity = { availableVolume: 0, amCapacity: 0, pmCapacity: 0, dailyCapacity: 0 };
    appState.dateAvailability = {};
    appState.appointmentSummary = null;
    
    // Reset UI
    document.getElementById('region').value = '';
    resetBranchSelection();
    resetCalendar();
    resetTimeSelection();
    hideAppointmentForm();
    updateProgressTracker(1);
    showStep(1);
    
    if (typeof grecaptcha !== 'undefined' && grecaptcha.reset) {
        grecaptcha.reset();
    }
}