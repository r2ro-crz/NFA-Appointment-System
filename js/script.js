// Main JavaScript file for NFA Farmer's Appointment System

// Global variables
let currentDate = new Date();
let selectedDate = null;
let selectedTime = null;
let selectedRegion = null;
let selectedBranch = null;
let captchaAnswer = 0;

// Sample data structure
const regionsData = {
    ncr: {
        name: 'National Capital Region (NCR)',
        branches: {
            'quezon-city': 'Quezon City Branch',
            'manila': 'Manila Branch',
            'makati': 'Makati Branch',
            'pasig': 'Pasig Branch'
        }
    },
    region1: {
        name: 'Region I - Ilocos',
        branches: {
            'laoag': 'Laoag Branch',
            'vigan': 'Vigan Branch',
            'san-fernando-la-union': 'San Fernando, La Union Branch'
        }
    },
    region2: {
        name: 'Region II - Cagayan Valley',
        branches: {
            'tuguegarao': 'Tuguegarao Branch',
            'ilagan': 'Ilagan Branch',
            'cauayan': 'Cauayan Branch'
        }
    },
    region3: {
        name: 'Region III - Central Luzon',
        branches: {
            'san-fernando': 'San Fernando Branch',
            'angeles': 'Angeles Branch',
            'olongapo': 'Olongapo Branch'
        }
    },
    region4a: {
        name: 'Region IV-A - CALABARZON',
        branches: {
            'calamba': 'Calamba Branch',
            'lipa': 'Lipa Branch',
            'lucena': 'Lucena Branch'
        }
    }
};

// Branch capacity settings (default values that can be modified by processors)
const branchCapacitySettings = {
    'ncr-quezon-city': { am: 15, pm: 12 },
    'ncr-manila': { am: 20, pm: 18 },
    'ncr-makati': { am: 10, pm: 10 },
    'ncr-pasig': { am: 12, pm: 15 },
    'region1-laoag': { am: 8, pm: 8 },
    'region1-vigan': { am: 10, pm: 8 },
    'region1-san-fernando-la-union': { am: 12, pm: 10 },
    'region2-tuguegarao': { am: 15, pm: 12 },
    'region2-ilagan': { am: 8, pm: 10 },
    'region2-cauayan': { am: 10, pm: 8 },
    'region3-san-fernando': { am: 18, pm: 15 },
    'region3-angeles': { am: 12, pm: 12 },
    'region3-olongapo': { am: 10, pm: 10 },
    'region4a-calamba': { am: 15, pm: 12 },
    'region4a-lipa': { am: 10, pm: 10 },
    'region4a-lucena': { am: 12, pm: 8 }
};

// Booked appointments (simulated)
const bookedAppointments = [
    { region: 'ncr', branch: 'quezon-city', date: '2024-11-15', time: 'AM', count: 8 },
    { region: 'ncr', branch: 'quezon-city', date: '2024-11-15', time: 'PM', count: 10 },
    { region: 'region3', branch: 'san-fernando', date: '2024-11-16', time: 'AM', count: 5 },
    { region: 'ncr', branch: 'manila', date: '2024-11-18', time: 'AM', count: 15 },
    { region: 'region1', branch: 'laoag', date: '2024-11-20', time: 'PM', count: 6 }
];

// DOM Content Loaded
document.addEventListener('DOMContentLoaded', function() {
    initializeApp();
});

function initializeApp() {
    setupEventListeners();
    generateCaptcha();
    updateCalendarDisplay();
}

function setupEventListeners() {
    // Region dropdown change
    document.getElementById('region').addEventListener('change', function() {
        const regionValue = this.value;
        selectedRegion = regionValue;
        updateBranchDropdown(regionValue);
        hideCalendar();
        hideCapacityInfo();
    });

    // Branch dropdown change
    document.getElementById('branch').addEventListener('change', function() {
        const branchValue = this.value;
        selectedBranch = branchValue;
        if (selectedRegion && branchValue) {
            showCapacityInfo();
            showCalendar();
        } else {
            hideCalendar();
            hideCapacityInfo();
        }
    });

    // Calendar navigation
    document.getElementById('prevMonth').addEventListener('click', function() {
        currentDate.setMonth(currentDate.getMonth() - 1);
        updateCalendarDisplay();
    });

    document.getElementById('nextMonth').addEventListener('click', function() {
        currentDate.setMonth(currentDate.getMonth() + 1);
        updateCalendarDisplay();
    });

    // Form submission
    document.getElementById('farmerForm').addEventListener('submit', function(e) {
        e.preventDefault();
        handleFormSubmission();
    });
}

function updateBranchDropdown(regionValue) {
    const branchSelect = document.getElementById('branch');
    branchSelect.innerHTML = '<option value="">Select Branch</option>';
    
    if (regionValue && regionsData[regionValue]) {
        branchSelect.disabled = false;
        const branches = regionsData[regionValue].branches;
        
        for (const [branchKey, branchName] of Object.entries(branches)) {
            const option = document.createElement('option');
            option.value = branchKey;
            option.textContent = branchName;
            branchSelect.appendChild(option);
        }
    } else {
        branchSelect.disabled = true;
    }
}

function showCapacityInfo() {
    if (!selectedRegion || !selectedBranch) return;
    
    const capacityKey = `${selectedRegion}-${selectedBranch}`;
    const capacity = branchCapacitySettings[capacityKey] || { am: 10, pm: 10 };
    
    // Update capacity display
    document.getElementById('totalCapacity').textContent = capacity.am + capacity.pm;
    document.getElementById('amCapacity').textContent = capacity.am;
    document.getElementById('pmCapacity').textContent = capacity.pm;
    
    // Show capacity info with animation
    const capacityInfo = document.getElementById('capacityInfo');
    capacityInfo.style.display = 'block';
    capacityInfo.classList.add('fade-in');
    
    // Scroll to capacity info
    capacityInfo.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

function hideCapacityInfo() {
    document.getElementById('capacityInfo').style.display = 'none';
}

function showCalendar() {
    document.getElementById('calendarContainer').style.display = 'block';
    updateCalendarDisplay();
}

function hideCalendar() {
    document.getElementById('calendarContainer').style.display = 'none';
    document.getElementById('timeSlots').style.display = 'none';
    document.getElementById('appointmentForm').style.display = 'none';
}

function updateCalendarDisplay() {
    const monthNames = [
        'January', 'February', 'March', 'April', 'May', 'June',
        'July', 'August', 'September', 'October', 'November', 'December'
    ];
    
    document.getElementById('currentMonth').textContent = 
        `${monthNames[currentDate.getMonth()]} ${currentDate.getFullYear()}`;
    
    generateCalendarDays();
}

function generateCalendarDays() {
    const calendar = document.getElementById('calendar');
    calendar.innerHTML = '';
    
    // Add day headers
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
    const lastDay = new Date(year, month + 1, 0);
    const startDate = new Date(firstDay);
    startDate.setDate(startDate.getDate() - firstDay.getDay());
    
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    
    for (let i = 0; i < 42; i++) {
        const cellDate = new Date(startDate);
        cellDate.setDate(startDate.getDate() + i);
        
        const dayElement = document.createElement('div');
        dayElement.className = 'calendar-day';
        dayElement.textContent = cellDate.getDate();
        
        // Add classes based on date properties
        if (cellDate.getMonth() !== month) {
            dayElement.classList.add('other-month');
        }
        
        if (cellDate < today) {
            dayElement.classList.add('disabled');
        }
        
        if (cellDate.getDay() === 0 || cellDate.getDay() === 6) {
            dayElement.classList.add('weekend', 'disabled');
        }
        
        // Check availability
        if (selectedRegion && selectedBranch && cellDate >= today && 
            cellDate.getDay() !== 0 && cellDate.getDay() !== 6) {
            const availability = checkDateAvailability(cellDate);
            if (availability.hasAvailableSlots) {
                dayElement.classList.add('available');
                dayElement.addEventListener('click', () => selectDate(cellDate));
            } else {
                dayElement.classList.add('disabled');
            }
        }
        
        calendar.appendChild(dayElement);
    }
}

function checkDateAvailability(date) {
    const dateString = formatDate(date);
    const capacityKey = `${selectedRegion}-${selectedBranch}`;
    const capacity = branchCapacitySettings[capacityKey] || { am: 10, pm: 10 };
    
    // Check booked appointments for this date
    const bookedAM = bookedAppointments.find(apt => 
        apt.region === selectedRegion && 
        apt.branch === selectedBranch && 
        apt.date === dateString && 
        apt.time === 'AM'
    );
    
    const bookedPM = bookedAppointments.find(apt => 
        apt.region === selectedRegion && 
        apt.branch === selectedBranch && 
        apt.date === dateString && 
        apt.time === 'PM'
    );
    
    const amBooked = bookedAM ? bookedAM.count : 0;
    const pmBooked = bookedPM ? bookedPM.count : 0;
    const amAvailable = amBooked < capacity.am;
    const pmAvailable = pmBooked < capacity.pm;
    
    return {
        hasAvailableSlots: amAvailable || pmAvailable,
        amAvailable: amAvailable,
        pmAvailable: pmAvailable,
        amBooked: amBooked,
        pmBooked: pmBooked,
        amCapacity: capacity.am,
        pmCapacity: capacity.pm,
        amRemaining: capacity.am - amBooked,
        pmRemaining: capacity.pm - pmBooked
    };
}

function selectDate(date) {
    // Remove previous selection
    document.querySelectorAll('.calendar-day.selected').forEach(day => {
        day.classList.remove('selected');
    });
    
    // Add selection to clicked date
    event.target.classList.add('selected');
    selectedDate = date;
    
    showTimeSlots(date);
}

function showTimeSlots(date) {
    const availability = checkDateAvailability(date);
    const timeSlotsContainer = document.getElementById('timeSlots');
    const slots = timeSlotsContainer.querySelectorAll('.time-slot');
    
    // Update selected date display
    document.getElementById('selectedDateDisplay').textContent = formatDateDisplay(date);
    
    // Update AM slot
    const amSlot = slots[0];
    const amAvailability = document.getElementById('amAvailability');
    if (availability.amAvailable) {
        amSlot.disabled = false;
        amSlot.classList.remove('disabled');
        amSlot.onclick = () => selectTimeSlot('AM');
        amAvailability.textContent = `Available slots: ${availability.amRemaining}/${availability.amCapacity}`;
        amAvailability.style.color = availability.amRemaining > 5 ? '#27ae60' : '#f39c12';
    } else {
        amSlot.disabled = true;
        amSlot.classList.add('disabled');
        amSlot.onclick = null;
        amAvailability.textContent = `Fully booked (${availability.amCapacity}/${availability.amCapacity})`;
        amAvailability.style.color = '#e74c3c';
    }
    
    // Update PM slot
    const pmSlot = slots[1];
    const pmAvailability = document.getElementById('pmAvailability');
    if (availability.pmAvailable) {
        pmSlot.disabled = false;
        pmSlot.classList.remove('disabled');
        pmSlot.onclick = () => selectTimeSlot('PM');
        pmAvailability.textContent = `Available slots: ${availability.pmRemaining}/${availability.pmCapacity}`;
        pmAvailability.style.color = availability.pmRemaining > 5 ? '#27ae60' : '#f39c12';
    } else {
        pmSlot.disabled = true;
        pmSlot.classList.add('disabled');
        pmSlot.onclick = null;
        pmAvailability.textContent = `Fully booked (${availability.pmCapacity}/${availability.pmCapacity})`;
        pmAvailability.style.color = '#e74c3c';
    }
    
    timeSlotsContainer.style.display = 'block';
    timeSlotsContainer.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

function selectTimeSlot(time) {
    // Remove previous selection
    document.querySelectorAll('.time-slot.selected').forEach(slot => {
        slot.classList.remove('selected');
    });
    
    // Add selection to clicked slot
    event.target.classList.add('selected');
    selectedTime = time;
    
    showAppointmentForm();
}

function showAppointmentForm() {
    updateAppointmentSummary();
    document.getElementById('appointmentForm').style.display = 'block';
    document.getElementById('appointmentForm').scrollIntoView({ behavior: 'smooth' });
}

function updateAppointmentSummary() {
    document.getElementById('summaryRegion').textContent = regionsData[selectedRegion].name;
    document.getElementById('summaryBranch').textContent = regionsData[selectedRegion].branches[selectedBranch];
    document.getElementById('summaryDate').textContent = formatDateDisplay(selectedDate);
    document.getElementById('summaryTime').textContent = selectedTime === 'AM' ? 
        'Morning (8:00 AM - 12:00 PM)' : 'Afternoon (1:00 PM - 5:00 PM)';
}

function generateCaptcha() {
    const num1 = Math.floor(Math.random() * 10) + 1;
    const num2 = Math.floor(Math.random() * 10) + 1;
    captchaAnswer = num1 + num2;
    document.getElementById('captchaQuestion').textContent = `${num1} + ${num2} = ?`;
}

function handleFormSubmission() {
    // Validate captcha
    const captchaInput = document.getElementById('captcha').value;
    if (parseInt(captchaInput) !== captchaAnswer) {
        alert('Incorrect captcha answer. Please try again.');
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
        region: selectedRegion,
        branch: selectedBranch,
        date: formatDate(selectedDate),
        time: selectedTime
    };
    
    // Validate required fields
    if (!formData.firstName || !formData.lastName || !formData.email || 
        !formData.contact || !formData.gender) {
        alert('Please fill in all required fields.');
        return;
    }
    
    // Validate email format
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    if (!emailRegex.test(formData.email)) {
        alert('Please enter a valid email address.');
        return;
    }
    
    // Validate contact number (Philippine format)
    const contactRegex = /^(09|\+639)\d{9}$/;
    if (!contactRegex.test(formData.contact.replace(/\s/g, ''))) {
        alert('Please enter a valid Philippine contact number (e.g., 09123456789).');
        return;
    }
    
    // Double-check availability before submitting
    const availability = checkDateAvailability(selectedDate);
    const isSlotAvailable = selectedTime === 'AM' ? availability.amAvailable : availability.pmAvailable;
    
    if (!isSlotAvailable) {
        alert('Sorry, this time slot is no longer available. Please select a different date or time.');
        showTimeSlots(selectedDate); // Refresh time slots
        return;
    }
    
    // Submit appointment
    submitAppointment(formData);
}

function submitAppointment(formData) {
    // Show loading state
    const submitBtn = document.querySelector('.submit-btn');
    const originalText = submitBtn.textContent;
    submitBtn.textContent = 'Submitting...';
    submitBtn.disabled = true;
    
    // Generate reference number
    const referenceNumber = 'NFA' + Date.now().toString().slice(-6);
    
    // Simulate API call
    setTimeout(() => {
        // Update booked appointments
        const existingBooking = bookedAppointments.find(apt => 
            apt.region === formData.region && 
            apt.branch === formData.branch && 
            apt.date === formData.date && 
            apt.time === formData.time
        );
        
        if (existingBooking) {
            existingBooking.count++;
        } else {
            bookedAppointments.push({
                region: formData.region,
                branch: formData.branch,
                date: formData.date,
                time: formData.time,
                count: 1
            });
        }
        
        // Store appointment in localStorage (for demo purposes)
        const appointments = JSON.parse(localStorage.getItem('appointments') || '[]');
        appointments.push({
            ...formData,
            referenceNumber: referenceNumber,
            status: 'pending',
            createdAt: new Date().toISOString()
        });
        localStorage.setItem('appointments', JSON.stringify(appointments));
        
        // Reset button state
        submitBtn.textContent = originalText;
        submitBtn.disabled = false;
        
        // Show success modal
        document.getElementById('referenceNumber').textContent = referenceNumber;
        document.getElementById('successModal').style.display = 'flex';
        
        // Reset form
        resetForm();
        
    }, 2000); // Simulate network delay
}

function resetForm() {
    document.getElementById('farmerForm').reset();
    document.getElementById('region').value = '';
    document.getElementById('branch').innerHTML = '<option value="">Select Branch</option>';
    document.getElementById('branch').disabled = true;
    
    selectedDate = null;
    selectedTime = null;
    selectedRegion = null;
    selectedBranch = null;
    
    hideCalendar();
    hideCapacityInfo();
    generateCaptcha();
    
    // Remove selections
    document.querySelectorAll('.calendar-day.selected').forEach(day => {
        day.classList.remove('selected');
    });
    document.querySelectorAll('.time-slot.selected').forEach(slot => {
        slot.classList.remove('selected');
    });
}

function closeModal() {
    document.getElementById('successModal').style.display = 'none';
}

// Utility functions
function formatDate(date) {
    return date.toISOString().split('T')[0];
}

function formatDateDisplay(date) {
    const options = { 
        weekday: 'long', 
        year: 'numeric', 
        month: 'long', 
        day: 'numeric' 
    };
    return date.toLocaleDateString('en-US', options);
}

// Form validation helpers
function validateEmail(email) {
    const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    return re.test(email);
}

function validatePhoneNumber(phone) {
    const re = /^(09|\+639)\d{9}$/;
    return re.test(phone.replace(/\s/g, ''));
}

// Accessibility enhancements
document.addEventListener('keydown', function(e) {
    // Close modal with Escape key
    if (e.key === 'Escape') {
        const modals = document.querySelectorAll('.modal');
        modals.forEach(modal => {
            if (modal.style.display === 'flex') {
                modal.style.display = 'none';
            }
        });
    }
});

// Auto-resize text areas and improve UX
document.addEventListener('input', function(e) {
    if (e.target.tagName === 'TEXTAREA') {
        e.target.style.height = 'auto';
        e.target.style.height = e.target.scrollHeight + 'px';
    }
});

// Add loading states for better UX
function showLoading(element) {
    element.classList.add('loading');
    element.disabled = true;
}

function hideLoading(element) {
    element.classList.remove('loading');
    element.disabled = false;
}

// Error handling
window.addEventListener('error', function(e) {
    console.error('Application error:', e.error);
    // You could show a user-friendly error message here
});

// Service worker registration (for offline functionality)
if ('serviceWorker' in navigator) {
    window.addEventListener('load', function() {
        navigator.serviceWorker.register('/sw.js')
            .then(function(registration) {
                console.log('ServiceWorker registration successful');
            })
            .catch(function(err) {
                console.log('ServiceWorker registration failed');
            });
    });
}

// Export functions for testing (if needed)
if (typeof module !== 'undefined' && module.exports) {
    module.exports = {
        validateEmail,
        validatePhoneNumber,
        formatDate,
        formatDateDisplay,
        checkDateAvailability
    };
}