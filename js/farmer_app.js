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
    branchCapacity: {
        availableVolume: 0,
        amCapacity: 0,
        pmCapacity: 0,
        dailyCapacity: 0
    },
    dateAvailability: {},
    farmerData: {},
    appointmentSummary: null
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
    
    // Reset dependent fields
    resetBranchSelection();
    resetCalendar();
    resetTimeSelection();
    hideAppointmentForm();
    
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
    
    // Reset dependent fields
    resetCalendar();
    resetTimeSelection();
    hideAppointmentForm();
    
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
    const isValid = appState.selectedRegion && appState.selectedBranch;
    
    nextButton.disabled = !isValid;
    
    if (isValid) {
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
    
    if (appState.selectedDate && appState.selectedTime) {
        dateDisplay.textContent = formatDateDisplay(appState.selectedDate);
        timeDisplay.textContent = appState.selectedTime === 'AM' 
            ? 'Morning (8:00 AM - 12:00 PM)' 
            : 'Afternoon (1:00 PM - 5:00 PM)';
        
        summary.style.display = 'block';
        summary.style.animation = 'slideInUp 0.5s ease';
    } else {
        summary.style.display = 'none';
    }
}

function updateStep2NextButton() {
    const nextButton = document.getElementById('nextStep2');
    const isValid = appState.selectedDate && appState.selectedTime;
    
    nextButton.disabled = !isValid;
    
    if (isValid) {
        nextButton.innerHTML = 'Continue to Details <i class="fas fa-arrow-right"></i>';
        nextButton.style.cursor = 'pointer';
    } else {
        nextButton.innerHTML = 'Select Date & Time First <i class="fas fa-lock"></i>';
        nextButton.style.cursor = 'not-allowed';
    }
}

// --- Form Validation ---
function validateStep1() {
    return appState.selectedRegion && appState.selectedBranch;
}

function validateStep2() {
    return appState.selectedDate && appState.selectedTime;
}

function setupRealTimeValidation() {
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

function validateEmail(input) {
    const email = input.value.trim();
    const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    
    if (!emailPattern.test(email)) {
        showInputError(input, 'Please enter a valid email address');
        return false;
    }
    
    clearInputError(input);
    return true;
}

function validateContactNumber(input) {
    const contact = input.value.trim().replace(/[\s\-\(\)]/g, '');
    const contactPattern = /^(09|\+639)\d{9}$/;
    
    if (!contactPattern.test(contact)) {
        showInputError(input, 'Please enter a valid 11-digit Philippine mobile number');
        return false;
    }
    
    clearInputError(input);
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
        appState.appointmentSummary = {
            referenceNumber: result.referenceNumber,
            branch: appState.selectedBranch.name,
            dateTime: `${formatDateDisplay(appState.selectedDate)} (${appState.selectedTime === 'AM' ? 'Morning' : 'Afternoon'})`,
            farmerName: `${formData.firstName} ${formData.lastName}`,
            farmerId: formData.farmer_id
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
    }
}

// --- UI Helper Functions ---
function updateAppointmentSummary() {
    if (appState.selectedBranch && appState.selectedDate && appState.selectedTime) {
        document.getElementById('summaryBranchFull').textContent = appState.selectedBranch.name;
        document.getElementById('summaryDateTime').textContent = 
            `${formatDateDisplay(appState.selectedDate)} (${appState.selectedTime === 'AM' ? 'Morning' : 'Afternoon'})`;
    }
}

function resetBranchSelection() {
    const branchSelect = document.getElementById('branch');
    branchSelect.innerHTML = '<option value="">Choose a branch</option>';
    branchSelect.disabled = true;
    
    document.getElementById('branchInfoCard').style.display = 'none';
    document.getElementById('capacityInfo').style.display = 'none';
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
}

function resetTimeSelection() {
    appState.selectedTime = null;
    
    document.getElementById('timeSlots').style.display = 'none';
    document.getElementById('selectedSlotSummary').style.display = 'none';
    
    document.querySelectorAll('.time-slot-card.selected').forEach(card => {
        card.classList.remove('selected');
    });
}

function hideAppointmentForm() {
    document.getElementById('appointmentForm').style.display = 'none';
}

function showAppointmentForm() {
    document.getElementById('appointmentForm').style.display = 'block';
}

function showLoadingOverlay() {
    document.getElementById('loadingOverlay').classList.add('active');
}

function hideLoadingOverlay() {
    document.getElementById('loadingOverlay').classList.remove('active');
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
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    
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
        dayEl.textContent = day;
        
        const availability = appState.dateAvailability[dateStr];
        const isWeekend = dateObj.getDay() === 0 || dateObj.getDay() === 6;
        const isPast = dateObj < today;
        const isDisabled = isPast || (availability && availability.is_disabled);
        
        if (isWeekend) {
            dayEl.classList.add('weekend');
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
        
        appState.branchCapacity = {
            availableVolume: capacityInfo.available_volume || 0,
            amCapacity: slotCap.capacity_am || 0,
            pmCapacity: slotCap.capacity_pm || 0,
            dailyCapacity: (slotCap.capacity_am || 0) + (slotCap.capacity_pm || 0)
        };
        appState.dateAvailability = data.daily_availability || {};
        
        // Update capacity UI
        const totalCapacity = capacityInfo.total_capacity || 0;
        document.getElementById('availableVolume').textContent = `${formatNumber(appState.branchCapacity.availableVolume)} bags`;
        document.getElementById('warehouseCapacity').textContent = `${formatNumber(totalCapacity)} bags`;
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