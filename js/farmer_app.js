// Main JavaScript file for NFA Farmer's Appointment System

// Global state variables
let currentDate = new Date();
let selectedDate = null;
let selectedTime = null;
let selectedRegionId = null;
let selectedBranchId = null;
let selectedRegionName = '';
let selectedBranchName = '';
// The daily_slot_capacity will be determined by PHP response
let branchCapacityInfo = { available_volume: 0, daily_slot_capacity: 0 }; 
let dateAvailability = {}; // Object to store detailed availability from api.php
let captchaText = '';

// Path to the consolidated API file
const API_URL = 'php_helper/api.php'; 

// --- Initializer ---
document.addEventListener('DOMContentLoaded', function() {
    initializeApp();
});

async function initializeApp() {
    setupEventListeners();
    await fetchRegions(); 
    await fetchFarmerTypes(); 
    generateCaptcha(); 
    updateProgress(1);
}

// --- API Functions (AJAX) ---
async function fetchApi(action, params = {}, method = 'GET', body = null) {
    try {
        const url = new URL(API_URL, window.location.href);
        url.searchParams.append('action', action);
        // Debug: show constructed URL
        console.debug(`[fetchApi] ${method} ${action} -> ${url.toString()}`, params, body);
        
        const fetchOptions = {
            method: method,
            headers: {}
        };

        if (method === 'GET') {
            for (const [key, value] of Object.entries(params)) {
                url.searchParams.append(key, value);
            }
        } else if (method === 'POST') {
            fetchOptions.headers['Content-Type'] = 'application/json';
            fetchOptions.body = JSON.stringify(body);
        }

        const response = await fetch(url, fetchOptions);
        if (!response.ok) {
            throw new Error(`HTTP error! Status: ${response.status}`);
        }
        const result = await response.json();
        console.debug('[fetchApi] result for', action, result);
        if (result.success === false) {
            // The error object comes directly from the server-side logic
            throw new Error(result.error || `API call to ${action} failed`);
        }
        return result; 
    } catch (error) {
        console.error(`Error in ${action}:`, error);
        // Keep an alert for user but also log detailed info for debugging
        alert(`Failed to load data: ${error.message}`);
        return null;
    }
}

async function fetchRegions() {
    const result = await fetchApi('getRegions');
    const select = document.getElementById('region');
    if (result && result.data) {
        result.data.forEach(region => {
            const option = document.createElement('option');
            option.value = region.region_id;
            option.textContent = region.region_name;
            option.dataset.name = region.region_name;
            select.appendChild(option);
        });
    }
}

async function fetchBranches(regionId) {
    console.debug('[fetchBranches] regionId:', regionId);
    const result = await fetchApi('getBranches', { region_id: regionId });
    const select = document.getElementById('branch');
    
    // Clear existing options, keeping the default 'Select Branch' text
    select.innerHTML = '<option value="">Select Branch</option>';
    // Always disable by default; we'll enable only if good data is returned.
    select.disabled = true; 
    
    // If successful and data exists
    if (result && Array.isArray(result.data) && result.data.length > 0) {
        // Ensure the dropdown is enabled as soon as valid data is found
        select.disabled = false;
        console.debug('[fetchBranches] branches found:', result.data.length);
        
        result.data.forEach(branch => {
            const option = document.createElement('option');
            // Data fields (branch_id, branch_name) match PHP output
            option.value = branch.branch_id;
            option.textContent = branch.branch_name;
            option.dataset.name = branch.branch_name;
            select.appendChild(option);
        });
    }
    // If no data, it remains disabled (due to the line added above)
}

async function fetchBranchInfo(branchId) {
    const monthStart = formatDate(new Date(currentDate.getFullYear(), currentDate.getMonth(), 1));
    const nextMonthEnd = new Date(currentDate.getFullYear(), currentDate.getMonth() + 3, 0);
    const monthEnd = formatDate(nextMonthEnd);

    // Call the single consolidated action
    const data = await fetchApi('getBranchInfo', { 
        branch_id: branchId,
        start_date: monthStart,
        end_date: monthEnd 
    });
    
    if (data) {
        // Correctly map volume capacity (Q3)
        branchCapacityInfo.available_volume = data.capacity_info.available_volume;
        
        // Calculate total slot capacity from AM/PM defaults
        const amCap = parseInt(data.default_slot_capacity.capacity_am) || 0;
        const pmCap = parseInt(data.default_slot_capacity.capacity_pm) || 0;
        branchCapacityInfo.daily_slot_capacity = amCap + pmCap;

        // Store detailed availability data
        dateAvailability = data.daily_availability; 
        
        updateCapacityDisplay();
        updateCalendarDisplay();
    }
}

async function fetchFarmerTypes() {
    const result = await fetchApi('getFarmerTypes');
    const select = document.getElementById('farmerType');
    if (result && result.data) {
        result.data.forEach(type => {
            const option = document.createElement('option');
            // FIX: Changed from type.type_id to type.farmer_type_id
            option.value = type.farmer_type_id; 
            option.textContent = type.type_name;
            select.appendChild(option);
        });
    }
}

// --- Event Listeners and UI Logic (Minimal changes to flow) ---

function setupEventListeners() {
    document.getElementById('region').addEventListener('change', handleRegionChange);
    document.getElementById('branch').addEventListener('change', handleBranchChange);
    document.getElementById('prevMonth').addEventListener('click', () => changeMonth(-1));
    document.getElementById('nextMonth').addEventListener('click', () => changeMonth(1));
    document.getElementById('farmerForm').addEventListener('submit', handleFormSubmission);
    document.getElementById('refreshCaptcha').addEventListener('click', generateCaptcha);
}

function handleRegionChange() {
    const regionSelect = document.getElementById('region');
    selectedRegionId = regionSelect.value;
    selectedRegionName = regionSelect.options[regionSelect.selectedIndex].dataset.name || '';
    selectedBranchId = null;
    hideCalendar();
    hideCapacityInfo();
    if (selectedRegionId) {
        fetchBranches(selectedRegionId);
        updateProgress(1);
    } else {
        document.getElementById('branch').disabled = true;
        updateProgress(0); 
    }
}

function handleBranchChange() {
    const branchSelect = document.getElementById('branch');
    selectedBranchId = branchSelect.value;
    selectedBranchName = branchSelect.options[branchSelect.selectedIndex].dataset.name || '';
    selectedDate = null;
    selectedTime = null;
    hideTimeSlots();
    hideAppointmentForm();

    if (selectedBranchId) {
        fetchBranchInfo(selectedBranchId);
        showCapacityInfo();
        showCalendar();
        updateProgress(1); 
    } else {
        hideCalendar();
        hideCapacityInfo();
    }
}

function updateProgress(step) {
    const steps = [
        document.getElementById('step1'),
        document.getElementById('step2'),
        document.getElementById('step3')
    ];
    
    steps.forEach((s, index) => {
        s.style.color = '#666';
        s.style.fontWeight = 'normal';
    });

    for (let i = 0; i < step; i++) {
        steps[i].style.color = '#27ae60'; 
    }
    if (step <= steps.length) {
        steps[step - 1].style.color = '#3498db'; 
        steps[step - 1].style.fontWeight = 'bold';
    }

    const progressBar = document.getElementById('progressBar');
    const width = (step / steps.length) * 100;
    progressBar.style.width = `${width}%`;
}

// --- Capacity & Calendar Logic (Q3, Q4) ---

function updateCapacityDisplay() {
    document.getElementById('availableVolume').textContent = formatNumber(branchCapacityInfo.available_volume);
}

function showCapacityInfo() {
    const capacityInfo = document.getElementById('capacityInfo');
    capacityInfo.style.display = 'block';
    capacityInfo.classList.add('fade-in');
    capacityInfo.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

function hideCapacityInfo() {
    document.getElementById('capacityInfo').style.display = 'none';
}

function showCalendar() {
    document.getElementById('calendarContainer').style.display = 'block';
}

function hideCalendar() {
    document.getElementById('calendarContainer').style.display = 'none';
    hideTimeSlots();
    hideAppointmentForm();
}

// Ensure hideTimeSlots exists to avoid ReferenceError when other functions call it.
function hideTimeSlots() {
    const timeSlotsContainer = document.getElementById('timeSlots');
    if (!timeSlotsContainer) return;

    // Hide the container and clear any selected slot UI state
    timeSlotsContainer.style.display = 'none';
    timeSlotsContainer.querySelectorAll('.time-slot.selected').forEach(slot => slot.classList.remove('selected'));
}

function changeMonth(direction) {
    currentDate.setMonth(currentDate.getMonth() + direction);
    // For simplicity, we re-render the calendar. In a production app, you might re-fetch data if navigating outside the initially fetched date range.
    updateCalendarDisplay();
    hideTimeSlots();
    hideAppointmentForm();
}

function updateCalendarDisplay() {
    const monthNames = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
    
    document.getElementById('currentMonth').textContent = 
        `${monthNames[currentDate.getMonth()]} ${currentDate.getFullYear()}`;
    
    generateCalendarDays();
}

function generateCalendarDays() {
    const calendar = document.getElementById('calendar');
    calendar.innerHTML = '';
    
    const dayHeaders = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    dayHeaders.forEach(day => {
        const dayHeader = document.createElement('div');
        dayHeader.className = 'calendar-day-header';
        dayHeader.textContent = day;
        dayHeader.style.fontWeight = 'bold';
        dayHeader.style.background = '#34495e';
        dayHeader.style.color = 'white';
        calendar.appendChild(dayHeader);
    });
    
    const year = currentDate.getFullYear();
    const month = currentDate.getMonth();
    const firstDay = new Date(year, month, 1);
    const startDate = new Date(firstDay);
    startDate.setDate(startDate.getDate() - firstDay.getDay());
    
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    
    selectedDate = null;
    document.querySelectorAll('.time-slot.selected').forEach(slot => slot.classList.remove('selected'));
    hideTimeSlots();
    hideAppointmentForm();

    for (let i = 0; i < 42; i++) {
        const cellDate = new Date(startDate);
        cellDate.setDate(startDate.getDate() + i);
        
        const dayElement = document.createElement('div');
        dayElement.className = 'calendar-day';
        dayElement.textContent = cellDate.getDate();
        const dateString = formatDate(cellDate); 
        dayElement.dataset.date = dateString;

        if (cellDate.getMonth() !== month) {
            dayElement.classList.add('other-month');
        }
        
        // Disable past dates
        if (cellDate < today) {
            dayElement.classList.add('disabled');
        }
        
        // Check availability (Q4 logic - relies on PHP's calculation)
        if (!dayElement.classList.contains('disabled')) {
            const availability = checkDateAvailability(dateString);
            
            // Date is available if at least one session is available AND PHP hasn't disabled it (for weekend/holiday/full)
            if (!availability.isDisabled) {
                dayElement.classList.add('available');
                dayElement.addEventListener('click', () => selectDate(cellDate, dayElement));
            } else {
                dayElement.classList.add('disabled');
            }
        }
        
        calendar.appendChild(dayElement);
    }
}

function checkDateAvailability(dateString) {
    // Retrieves detailed availability data from PHP (daily_availability)
    const defaults = {
        amRemaining: 0,
        pmRemaining: 0,
        amCapacity: 0, 
        pmCapacity: 0,
        isDisabled: true
    };
    
    const data = dateAvailability[dateString];
    
    if (data) {
        return {
            amRemaining: parseInt(data.am_remaining),
            pmRemaining: parseInt(data.pm_remaining),
            amCapacity: parseInt(data.am_capacity),
            pmCapacity: parseInt(data.pm_capacity),
            isDisabled: data.is_disabled
        };
    }
    return defaults;
}

function selectDate(date, dayElement) {
    document.querySelectorAll('.calendar-day.selected').forEach(day => {
        day.classList.remove('selected');
    });
    
    dayElement.classList.add('selected');
    selectedDate = date;
    selectedTime = null; 
    document.querySelectorAll('.time-slot.selected').forEach(slot => slot.classList.remove('selected'));
    hideAppointmentForm(); 
    showTimeSlots(formatDate(date));
    updateProgress(2); 
}

// --- Time Slot Logic (Q5) ---

function showTimeSlots(dateString) {
    const availability = checkDateAvailability(dateString);
    const timeSlotsContainer = document.getElementById('timeSlots');
    const amSlot = timeSlotsContainer.querySelector('.time-slot[data-time="AM"]');
    const pmSlot = timeSlotsContainer.querySelector('.time-slot[data-time="PM"]');
    
    document.getElementById('selectedDateDisplay').textContent = formatDateDisplay(selectedDate);
    
    // Update AM slot
    const amAvailability = document.getElementById('amAvailability');
    if (availability.amRemaining > 0) { 
        amSlot.disabled = false;
        amSlot.classList.remove('disabled');
        amSlot.onclick = (e) => selectTimeSlot('AM', amSlot); 
        amAvailability.textContent = `Available slots: ${availability.amRemaining}/${availability.amCapacity}`;
        amAvailability.style.color = availability.amRemaining > (availability.amCapacity / 2) ? '#27ae60' : '#f39c12';
    } else {
        amSlot.disabled = true;
        amSlot.classList.add('disabled');
        amSlot.onclick = null;
        amAvailability.textContent = `Fully booked (0/${availability.amCapacity})`;
        amAvailability.style.color = '#e74c3c';
    }
    
    // Update PM slot
    const pmAvailability = document.getElementById('pmAvailability');
    if (availability.pmRemaining > 0) {
        pmSlot.disabled = false;
        pmSlot.classList.remove('disabled');
        pmSlot.onclick = (e) => selectTimeSlot('PM', pmSlot); 
        pmAvailability.textContent = `Available slots: ${availability.pmRemaining}/${availability.pmCapacity}`;
        pmAvailability.style.color = availability.pmRemaining > (availability.pmCapacity / 2) ? '#27ae60' : '#f39c12';
    } else {
        pmSlot.disabled = true;
        pmSlot.classList.add('disabled');
        pmSlot.onclick = null;
        pmAvailability.textContent = `Fully booked (0/${availability.pmCapacity})`;
        pmAvailability.style.color = '#e74c3c';
    }
    
    timeSlotsContainer.style.display = 'block';
    timeSlotsContainer.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

function selectTimeSlot(time, slotElement) {
    document.querySelectorAll('.time-slot.selected').forEach(slot => {
        slot.classList.remove('selected');
    });
    
    slotElement.classList.add('selected');
    selectedTime = time;
    
    showAppointmentForm();
    updateProgress(3); 
}

// --- Form and Submission Logic (Q6, Q7) ---

function showAppointmentForm() {
    updateAppointmentSummary();
    document.getElementById('appointmentForm').style.display = 'block';
    document.getElementById('appointmentForm').scrollIntoView({ behavior: 'smooth' });
}

function hideAppointmentForm() {
    document.getElementById('appointmentForm').style.display = 'none';
}

function updateAppointmentSummary() {
    document.getElementById('summaryRegion').textContent = selectedRegionName;
    document.getElementById('summaryBranch').textContent = selectedBranchName;
    document.getElementById('summaryDate').textContent = formatDateDisplay(selectedDate);
    document.getElementById('summaryTime').textContent = selectedTime === 'AM' ? 
        'Morning (8:00 AM - 12:00 PM)' : 'Afternoon (1:00 PM - 5:00 PM)';
}

function generateCaptcha() {
    const words = ["Farmer", "Harvest", "Rice", "Grain", "NFA", "Schedule", "Portal", "Approve", "Commit"];
    captchaText = words[Math.floor(Math.random() * words.length)];
    document.getElementById('captchaQuestion').textContent = captchaText;
}

async function handleFormSubmission(e) {
    e.preventDefault();
    const form = e.target;
    const submitBtn = form.querySelector('.submit-btn');
    
    // 6. Validate captcha (case-insensitive)
    const captchaInput = document.getElementById('captcha').value;
    if (captchaInput.toLowerCase() !== captchaText.toLowerCase()) {
        alert('Incorrect verification word. Please try again.');
        generateCaptcha();
        document.getElementById('captcha').value = '';
        return;
    }
    
    // Collect form data
    const formData = {
        firstName: document.getElementById('firstName').value,
        middleName: document.getElementById('middleName').value,
        lastName: document.getElementById('lastName').value,
        email: document.getElementById('email').value,
        contact: document.getElementById('contact').value,
        gender: document.getElementById('gender').value,
        volume: parseFloat(document.getElementById('volume').value),
        farmer_type_id: document.getElementById('farmerType').value,
        
        // Appointment Info
        branch_id: selectedBranchId,
        date: formatDate(selectedDate),
        time_slot: selectedTime
    };

    // Volume validation against available capacity (Q3 logic)
    if (formData.volume > branchCapacityInfo.available_volume) {
        alert(`The volume (${formatNumber(formData.volume)} kg) exceeds the available capacity of ${formatNumber(branchCapacityInfo.available_volume)} kg. Please reduce the volume.`);
        return;
    }

    // Double-check slot availability before submitting (Q4/Q5 logic)
    const availability = checkDateAvailability(formData.date);
    const remainingSlot = formData.time_slot === 'AM' ? availability.amRemaining : availability.pmRemaining;

    if (remainingSlot <= 0) {
        alert('Sorry, this time slot is no longer available. Please select a different date or time.');
        showTimeSlots(selectedDate); 
        return;
    }

    showLoading(submitBtn);

    const result = await submitAppointment(formData);

    hideLoading(submitBtn);

    if (result && result.success) {
        document.getElementById('referenceNumber').textContent = result.referenceNumber;
        document.getElementById('successModal').style.display = 'flex';
        
        // Re-fetch branch info to update booked slots/capacity after successful booking
        if (selectedBranchId) {
            await fetchBranchInfo(selectedBranchId);
        }
        
        resetForm();
    } // Error is handled by fetchApi
}

async function submitAppointment(formData) {
    try {
        const result = await fetchApi('submitAppointment', {}, 'POST', formData);
        if (!result) {
            throw new Error('No response from server');
        }
        return { 
            success: result.success === true, 
            referenceNumber: result.referenceNumber,
            error: result.error || null
        }; 
    } catch (error) {
        console.error('Submit appointment error:', error);
        return {
            success: false,
            error: error.message || 'Failed to submit appointment'
        };
    }
}

function resetForm() {
    document.getElementById('farmerForm').reset();
    document.getElementById('region').value = '';
    document.getElementById('branch').innerHTML = '<option value="">Select Branch</option>';
    document.getElementById('branch').disabled = true;
    
    selectedDate = null;
    selectedTime = null;
    selectedRegionId = null;
    selectedBranchId = null;
    selectedRegionName = '';
    selectedBranchName = '';
    
    hideCalendar();
    hideCapacityInfo();
    hideAppointmentForm();
    generateCaptcha();
    updateProgress(0); 
    
    document.querySelectorAll('.calendar-day.selected').forEach(day => day.classList.remove('selected'));
    document.querySelectorAll('.time-slot.selected').forEach(slot => slot.classList.remove('selected'));
}

function closeModal() {
    document.getElementById('successModal').style.display = 'none';
}

// --- Utility Functions ---

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

function showLoading(element) {
    element.textContent = 'Processing...';
    element.disabled = true;
}

function hideLoading(element) {
    element.textContent = 'Submit Appointment';
    element.disabled = false;
}