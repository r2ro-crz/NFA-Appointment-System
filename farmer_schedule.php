<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="https://public-frontend-cos.metadl.com/mgx/img/favicon.png" type="image/png">
    <title>NFA Farmer's Appointment System - Schedule Your Visit</title>
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/legal_modal.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Google reCAPTCHA v2 -->
    <script src="https://www.google.com/recaptcha/api.js" async defer></script>
</head>
<body>
    <!-- Progress tracking -->
    <div class="progress-tracker">
        <div class="progress-bar" id="progressBar"></div>
        <div class="progress-steps">
            <div class="step active" data-step="1">
                <div class="step-number">1</div>
                <div class="step-label">Location</div>
            </div>
            <div class="step" data-step="2">
                <div class="step-number">2</div>
                <div class="step-label">Date & Time</div>
            </div>
            <div class="step" data-step="3">
                <div class="step-number">3</div>
                <div class="step-label">Details</div>
            </div>
            <div class="step" data-step="4">
                <div class="step-number">4</div>
                <div class="step-label">Confirmation</div>
            </div>
        </div>
    </div>
    
    <!-- Header -->
    <header class="header">
        <div class="container">
            <div class="logo">
                <img src="img/nfa-logo.png" alt="NFA Logo" class="logo-img">
                <div class="logo-text">
                    <h1>National Food Authority</h1>
                    <p>Farmer's Appointment System</p>
                </div>
            </div>
            <div class="header-actions">
                <a href="landing.html" class="main-btn">
                    <i class="fas fa-home"></i> Main Portal
                </a>
                <div class="system-status">
                    <i class="fas fa-circle status-active"></i>
                    <span>System Active</span>
                </div>
            </div>
        </div>
    </header>

    <main class="main">
        <div class="container">
            <!-- Welcome Section -->
            <section class="welcome-section">
                <div class="welcome-card">
                    <div class="welcome-icon">
                        <i class="fas fa-tractor"></i>
                    </div>
                    <div class="welcome-content">
                        <h2>Schedule Your NFA Appointment</h2>
                        <p>Welcome to the National Food Authority's online appointment system. Please follow the steps below to schedule your visit for rice delivery, assistance, or consultation.</p>
                        
                        <div class="quick-info">
                            <div class="info-item">
                                <i class="fas fa-clock"></i>
                                <div>
                                    <strong>Processing Time:</strong> 1-2 business days
                                </div>
                            </div>
                            <div class="info-item">
                                <i class="fas fa-calendar-check"></i>
                                <div>
                                    <strong>Office Hours:</strong> Mon-Fri, 8AM-5PM
                                </div>
                            </div>
                            <div class="info-item">
                                <i class="fas fa-file-alt"></i>
                                <div>
                                    <strong>Requirements:</strong> Valid Farmer ID
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Main Appointment Form -->
            <div class="appointment-wizard">
                <!-- Step 1: Location Selection -->
                <section class="wizard-step active" id="step1-container">
                    <div class="step-header">
                        <div class="step-title">
                            <span class="step-badge">Step 1</span>
                            <h3><i class="fas fa-map-marker-alt"></i> Select Location</h3>
                        </div>
                        <p class="step-description">Choose your region and branch to view available appointment slots and capacity.</p>
                    </div>
                    
                    <div class="step-content">
                        <div class="location-selection">
                            <div class="location-card">
                                <div class="location-icon">
                                    <i class="fas fa-globe-asia"></i>
                                </div>
                                <div class="location-form">
                                    <div class="form-group">
                                        <label for="region">
                                            <i class="fas fa-flag"></i> Select Region
                                        </label>
                                        <div class="select-wrapper">
                                            <select id="region" name="region" required>
                                                <option value="">Choose your region</option>
                                            </select>
                                            <i class="fas fa-chevron-down select-arrow"></i>
                                        </div>
                                        <div class="form-hint">Select the region where you want to schedule your appointment</div>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label for="branch">
                                            <i class="fas fa-building"></i> Select Branch
                                        </label>
                                        <div class="select-wrapper">
                                            <select id="branch" name="branch" required disabled>
                                                <option value="">Choose a branch</option>
                                            </select>
                                            <i class="fas fa-chevron-down select-arrow"></i>
                                        </div>
                                        <div class="form-hint">Select a specific NFA branch in your chosen region</div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="branch-info-card" id="branchInfoCard" style="display: none;">
                                <div class="branch-header">
                                    <h4><i class="fas fa-info-circle"></i> Selected Branch Information</h4>
                                    <span class="branch-status available" id="branchStatus">Available</span>
                                </div>
                                <div class="branch-details">
                                    <div class="detail-item">
                                        <i class="fas fa-map-pin"></i>
                                        <div>
                                            <strong>Branch:</strong> <span id="branchNameDisplay">--</span>
                                        </div>
                                    </div>
                                    <div class="detail-item">
                                        <i class="fas fa-warehouse"></i>
                                        <div>
                                            <strong>Warehouse Capacity:</strong> <span id="warehouseCapacity">--</span>
                                        </div>
                                    </div>
                                    <div class="detail-item">
                                        <i class="fas fa-users"></i>
                                        <div>
                                            <strong>Daily Appointments:</strong> <span id="dailyAppointments">--</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Capacity Display -->
                        <div class="capacity-display" id="capacityInfo" style="display: none;">
                            <h4><i class="fas fa-chart-bar"></i> Current Capacity Status</h4>
                            <div class="capacity-visualization">
                                <div class="capacity-meter">
                                    <div class="meter-header">
                                        <span>Available Volume</span>
                                        <span id="availableVolume">0</span>
                                    </div>
                                    <div class="meter-bar">
                                        <div class="meter-fill" id="capacityFill"></div>
                                    </div>
                                    <div class="meter-labels">
                                        <span>Empty</span>
                                        <span>Full</span>
                                    </div>
                                </div>
                                
                                <div class="capacity-stats">
                                    <div class="stat-card">
                                        <div class="stat-icon morning">
                                            <i class="fas fa-sun"></i>
                                        </div>
                                        <div class="stat-details">
                                            <span class="stat-label">Morning Slots</span>
                                            <span class="stat-value" id="amSlots">--</span>
                                            <span class="stat-sub">per day</span>
                                        </div>
                                    </div>
                                    
                                    <div class="stat-card">
                                        <div class="stat-icon afternoon">
                                            <i class="fas fa-moon"></i>
                                        </div>
                                        <div class="stat-details">
                                            <span class="stat-label">Afternoon Slots</span>
                                            <span class="stat-value" id="pmSlots">--</span>
                                            <span class="stat-sub">per day</span>
                                        </div>
                                    </div>
                                    
                                    <div class="stat-card">
                                        <div class="stat-icon total">
                                            <i class="fas fa-chart-line"></i>
                                        </div>
                                        <div class="stat-details">
                                            <span class="stat-label">Total Available</span>
                                            <span class="stat-value" id="totalSlots">--</span>
                                            <span class="stat-sub">slots today</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="capacity-note" id="capacityNote">
                                <i class="fas fa-lightbulb"></i>
                                <p><strong>Note:</strong> The capacity meter shows the current available warehouse space at this branch.</p>
                            </div>
                        </div>
                        
                        <div class="step-actions">
                            <button class="btn-next" id="nextStep1" disabled>
                                Continue to Date Selection <i class="fas fa-arrow-right"></i>
                            </button>
                        </div>
                    </div>
                </section>

                <!-- Step 2: Date & Time Selection -->
                <section class="wizard-step" id="step2-container">
                    <div class="step-header">
                        <div class="step-title">
                            <span class="step-badge">Step 2</span>
                            <h3><i class="fas fa-calendar-alt"></i> Select Date & Time</h3>
                        </div>
                        <p class="step-description">Choose a convenient date and time slot for your appointment.</p>
                    </div>
                    
                    <div class="step-content">
                        <div class="date-time-selection">
                            <div class="calendar-container" id="calendarContainer" style="display: none;">
                                <div class="calendar-header">
                                    <button id="prevMonth" class="nav-btn" aria-label="Previous month">
                                        <i class="fas fa-chevron-left"></i>
                                    </button>
                                    <div class="month-display">
                                        <span id="currentMonth"></span>
                                        <span id="currentYear"></span>
                                    </div>
                                    <button id="nextMonth" class="nav-btn" aria-label="Next month">
                                        <i class="fas fa-chevron-right"></i>
                                    </button>
                                </div>
                                
                                <div class="calendar-grid">
                                    <div class="calendar-weekdays">
                                        <div>Sun</div>
                                        <div>Mon</div>
                                        <div>Tue</div>
                                        <div>Wed</div>
                                        <div>Thu</div>
                                        <div>Fri</div>
                                        <div>Sat</div>
                                    </div>
                                    <div class="calendar-days" id="calendar"></div>
                                </div>
                                
                                <div class="calendar-legend">
                                    <div class="legend-item">
                                        <span class="legend-color available"></span>
                                        <span>Available</span>
                                    </div>
                                    <div class="legend-item">
                                        <span class="legend-color selected"></span>
                                        <span>Selected</span>
                                    </div>
                                    <div class="legend-item">
                                        <span class="legend-color unavailable"></span>
                                        <span>Unavailable</span>
                                    </div>
                                    <div class="legend-item">
                                        <span class="legend-color holiday"></span>
                                        <span>Holiday</span>
                                    </div>
                                    <div class="legend-item">
                                        <span class="legend-color weekend"></span>
                                        <span>Weekend</span>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="time-slot-container" id="timeSlots" style="display: none;">
                                <div class="time-header">
                                    <h4>Select Time Slot</h4>
                                    <p>Selected Date: <strong id="selectedDateDisplay">--</strong></p>
                                </div>
                                
                                <div class="time-slot-grid">
                                    <div class="time-slot-card" data-time="AM">
                                        <div class="slot-header morning">
                                            <i class="fas fa-sun"></i>
                                            <h5>Morning Session</h5>
                                        </div>
                                        <div class="slot-time">8:00 AM - 12:00 PM</div>
                                        <div class="slot-availability">
                                            <span class="availability-label">Available:</span>
                                            <span class="availability-count" id="amAvailability">--</span>
                                        </div>
                                        <div class="slot-action">
                                            <button class="slot-select-btn" data-time="AM">Select Morning</button>
                                        </div>
                                    </div>
                                    
                                    <div class="time-slot-card" data-time="PM">
                                        <div class="slot-header afternoon">
                                            <i class="fas fa-moon"></i>
                                            <h5>Afternoon Session</h5>
                                        </div>
                                        <div class="slot-time">1:00 PM - 5:00 PM</div>
                                        <div class="slot-availability">
                                            <span class="availability-label">Available:</span>
                                            <span class="availability-count" id="pmAvailability">--</span>
                                        </div>
                                        <div class="slot-action">
                                            <button class="slot-select-btn" data-time="PM">Select Afternoon</button>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="selected-slot-summary" id="selectedSlotSummary" style="display: none;">
                                    <div class="summary-header">
                                        <i class="fas fa-check-circle"></i>
                                        <h5>Selected Appointment Time</h5>
                                    </div>
                                    <div class="summary-details">
                                        <div class="detail">
                                            <strong>Date:</strong> <span id="summaryDateDisplay">--</span>
                                        </div>
                                        <div class="detail">
                                            <strong>Time:</strong> <span id="summaryTimeDisplay">--</span>
                                        </div>
                                        <div class="detail">
                                            <strong>Session Window:</strong> <span id="summaryWindowDisplay">--</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="step-actions">
                            <button class="btn-back" id="backStep2">
                                <i class="fas fa-arrow-left"></i> Back to Location
                            </button>
                            <button class="btn-next" id="nextStep2" disabled>
                                Continue to Details <i class="fas fa-arrow-right"></i>
                            </button>
                        </div>
                    </div>
                </section>

                <!-- Step 3: Farmer Details -->
                <section class="wizard-step" id="step3-container">
                    <div class="step-header">
                        <div class="step-title">
                            <span class="step-badge">Step 3</span>
                            <h3><i class="fas fa-user-check"></i> Farmer Details</h3>
                        </div>
                        <p class="step-description">Please provide your personal information and appointment details.</p>
                    </div>
                    
                    <div class="step-content">
                        <div class="details-container" id="appointmentForm" style="display: none;">
                            <div class="appointment-summary-card">
                                <h4><i class="fas fa-clipboard-check"></i> Appointment Summary</h4>
                                <div class="summary-grid">
                                    <div class="summary-item">
                                        <i class="fas fa-map-marker-alt"></i>
                                        <div>
                                            <strong>Location</strong>
                                            <p id="summaryBranchFull">--</p>
                                        </div>
                                    </div>
                                    <div class="summary-item">
                                        <i class="fas fa-calendar-day"></i>
                                        <div>
                                            <strong>Date & Time</strong>
                                            <p id="summaryDateTime">--</p>
                                        </div>
                                    </div>
                                    <div class="summary-item">
                                        <i class="fas fa-id-card"></i>
                                        <div>
                                            <strong>Reference No.</strong>
                                            <p class="reference-placeholder">
                                                <span id="summaryReferenceNumber">--</span>
                                                <span class="reference-note">(final upon submission)</span>
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <form id="farmerForm" class="details-form">
                                <div class="form-section">
                                    <h5><i class="fas fa-user"></i> Personal Information</h5>
                                    <div class="form-grid">
                                        <div class="form-group">
                                            <label for="firstName">
                                                <i class="fas fa-signature"></i> First Name *
                                            </label>
                                            <input type="text" id="firstName" name="firstName" required placeholder="Enter your first name">
                                        </div>
                                        
                                        <div class="form-group">
                                            <label for="middleName">
                                                <i class="fas fa-signature"></i> Middle Name
                                            </label>
                                            <input type="text" id="middleName" name="middleName" placeholder="Enter middle name (optional)">
                                        </div>
                                        
                                        <div class="form-group">
                                            <label for="lastName">
                                                <i class="fas fa-signature"></i> Last Name *
                                            </label>
                                            <input type="text" id="lastName" name="lastName" required placeholder="Enter your last name">
                                        </div>
                                        
                                        <div class="form-group">
                                            <label for="suffix">
                                                <i class="fas fa-tag"></i> Suffix
                                            </label>
                                            <select id="suffix" name="suffix">
                                                <option value="">None</option>
                                                <option value="Jr">Jr.</option>
                                                <option value="Sr">Sr.</option>
                                                <option value="II">II</option>
                                                <option value="III">III</option>
                                                <option value="IV">IV</option>
                                            </select>
                                        </div>
                                    </div>
                                    
                                    <div class="form-grid">
                                        <div class="form-group">
                                            <label for="farmerId">
                                                <i class="fas fa-id-card"></i> Farmer ID *
                                            </label>
                                            <input type="text" id="farmerId" name="farmerId" required placeholder="Enter your Farmer ID">
                                            <div class="form-hint">Your official NFA Farmer Identification Number</div>
                                        </div>
                                        
                                        <div class="form-group">
                                            <label for="farmerType">
                                                <i class="fas fa-tractor"></i> Farmer Type *
                                            </label>
                                            <select id="farmerType" name="farmerType" required>
                                                <option value="">Select Farmer Type</option>
                                            </select>
                                        </div>

                                        <div class="form-group">
                                            <label for="gender">
                                                <i class="fas fa-venus-mars"></i> Gender *
                                            </label>
                                            <select id="gender" name="gender" required>
                                                <option value="">Select Gender</option>
                                                <option value="male">Male</option>
                                                <option value="female">Female</option>
                                                <option value="other">Prefer not to say</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="form-section">
                                    <h5><i class="fas fa-address-book"></i> Contact Information</h5>
                                    <div class="form-grid">
                                        <div class="form-group">
                                            <label for="email">
                                                <i class="fas fa-envelope"></i> Email Address *
                                            </label>
                                            <input type="email" id="email" name="email" required placeholder="example@email.com">
                                            <div class="form-hint">Confirmation will be sent to this email</div>
                                        </div>
                                        
                                        <div class="form-group">
                                            <label for="contact">
                                                <i class="fas fa-phone"></i> Contact Number *
                                            </label>
                                            <input type="tel" id="contact" name="contact" required placeholder="09XX XXX XXXX">
                                            <div class="form-hint">Philippine mobile number format</div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="form-section">
                                    <h5><i class="fas fa-weight"></i> Delivery Information</h5>
                                    <div class="form-grid">
                                        <div class="form-group">
                                            <label for="volume">
                                                <i class="fas fa-balance-scale"></i> Volume (bags) *
                                            </label>
                                            <div class="volume-input-wrapper">
                                                <input type="number" id="volume" name="volume" min="1" step="1" required placeholder="Number of bags">
                                                <span class="volume-unit">bags</span>
                                            </div>
                                            <div class="volume-hint">
                                                <span id="remainingCapacity">Available capacity: -- bags</span>
                                                <span class="volume-warning" id="volumeWarning" style="display: none;">
                                                    <i class="fas fa-exclamation-triangle"></i> Exceeds available capacity
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="delivery-guidelines">
                                        <h6><i class="fas fa-clipboard-list"></i> Important Guidelines</h6>
                                        <ul>
                                            <li>Each bag should be properly labeled with your Farmer ID</li>
                                            <li>Rice should be properly dried and cleaned</li>
                                            <li>Arrive anytime within your selected session window (AM: 8:00 AM–12:00 NN, PM: 1:00 PM–5:00 PM)</li>
                                            <li>Bring your valid Farmer ID and appointment confirmation</li>
                                        </ul>
                                    </div>
                                
                                <div class="form-section">
                                    <h5><i class="fas fa-shield-alt"></i> Security Verification</h5>
                                    <div class="captcha-section">
                                        <p class="captcha-note">
                                            <i class="fas fa-info-circle"></i> Please complete the security verification to submit your appointment.
                                        </p>
                                        <div class="g-recaptcha" data-sitekey="6LcdCQwsAAAAABn2LeLiRqNAbo4pL4Uy_FyjbzPn"></div>
                                    </div>
                                    
                                    <div class="privacy-notice">
                                        <i class="fas fa-lock"></i>
                                        <p>Your information is secure and will only be used for your NFA appointment processing. By submitting, you agree to our <a href="#" data-legal-modal="privacy">Privacy Policy</a>.</p>
                                    </div>
                                </div>
                                
                                <div class="form-actions">
                                    <button type="button" class="btn-back" id="backStep3">
                                        <i class="fas fa-arrow-left"></i> Back to Date & Time
                                    </button>
                                    <button type="submit" class="btn-submit">
                                        <i class="fas fa-paper-plane"></i> Submit Appointment
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </section>

                <!-- Step 4: Confirmation (Shown after submission) -->
                <section class="wizard-step" id="step4-container">
                    <div class="step-header">
                        <div class="step-title">
                            <span class="step-badge">Step 4</span>
                            <h3><i class="fas fa-check-circle"></i> Confirmation</h3>
                        </div>
                    </div>
                    
                    <div class="step-content">
                        <div class="confirmation-container">
                            <div class="success-card">
                                <div class="success-icon">
                                    <i class="fas fa-check-circle"></i>
                                </div>
                                <div class="success-content">
                                    <h2>Appointment Submitted Successfully!</h2>
                                    <p>Your appointment request has been received and is pending approval. You will receive an email notification once your appointment has been processed.</p>
                                    
                                    <div class="appointment-details-card">
                                        <h4><i class="fas fa-file-alt"></i> Appointment Details</h4>
                                        <div class="details-grid">
                                            <div class="detail-item">
                                                <strong>Reference Number:</strong>
                                                <span class="reference-number" id="referenceNumber">NFA-2023-001234</span>
                                            </div>
                                            <div class="detail-item">
                                                <strong>Status:</strong>
                                                <span class="status-badge pending">Pending Approval</span>
                                            </div>
                                            <div class="detail-item">
                                                <strong>Branch:</strong>
                                                <span id="confirmationBranch">--</span>
                                            </div>
                                            <div class="detail-item">
                                                <strong>Date & Time:</strong>
                                                <span id="confirmationDateTime">--</span>
                                            </div>
                                            <div class="detail-item">
                                                <strong>Farmer Name:</strong>
                                                <span id="confirmationName">--</span>
                                            </div>
                                            <div class="detail-item">
                                                <strong>Farmer ID:</strong>
                                                <span id="confirmationFarmerId">--</span>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="next-steps">
                                        <h4><i class="fas fa-list-check"></i> Next Steps</h4>
                                        <ol>
                                            <li>Check your email for the confirmation message</li>
                                            <li>Print or download your appointment confirmation for your records</li>
                                            <li>Arrive anytime within your selected session window (AM: 8:00 AM–12:00 NN, PM: 1:00 PM–5:00 PM)</li>
                                            <li>Bring your Farmer ID and necessary documents</li>
                                        </ol>
                                    </div>
                                    
                                    <div class="confirmation-actions">
                                        <button class="btn-print" type="button" onclick="printConfirmationDocument()">
                                            <i class="fas fa-print"></i> Print Confirmation
                                        </button>
                                        <button class="btn-print" type="button" onclick="downloadConfirmationDocument()">
                                            <i class="fas fa-download"></i> Download Confirmation
                                        </button>
                                        <button class="btn-new" onclick="resetForm()">
                                            <i class="fas fa-plus"></i> Schedule Another Appointment
                                        </button>
                                        <a href="landing.html" class="btn-home">
                                            <i class="fas fa-home"></i> Return to Main Portal
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>
            </div>
            
            <!-- Help Section -->
            <section class="help-section">
                <div class="help-card">
                    <div class="help-header">
                        <i class="fas fa-question-circle"></i>
                        <h3>Need Help?</h3>
                    </div>
                    <div class="help-content">
                        <p>If you encounter any issues or have questions about scheduling your appointment, please contact our support team.</p>
                        <div class="contact-options">
                            <a href="tel:+63289296701" class="contact-link">
                                <i class="fas fa-phone"></i> (02) 8929-6701
                            </a>
                            <a href="mailto:support@nfa.gov.ph" class="contact-link">
                                <i class="fas fa-envelope"></i> support@nfa.gov.ph
                            </a>
                            <a href="#" class="contact-link" data-legal-modal="contact">
                                <i class="fas fa-comments"></i> Live Chat
                            </a>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </main>

    <footer class="footer">
        <div class="container">
            <div class="footer-content">
                <div class="footer-logo">
                    <img src="img/nfa-logo.png" alt="NFA Logo" class="footer-logo-img">
                    <div class="footer-text">
                        <h3>National Food Authority</h3>
                        <p>Ensuring food security for every Filipino</p>
                    </div>
                </div>
                <div class="footer-links">
                    <a href="#" data-legal-modal="privacy">Privacy Policy</a>
                    <a href="#" data-legal-modal="terms">Terms of Service</a>
                    <a href="#" data-legal-modal="faq">FAQ</a>
                    <a href="#" data-legal-modal="contact">Contact Us</a>
                </div>
                <div class="footer-info">
                    <p><i class="fas fa-copyright"></i> 2026 National Food Authority. All rights reserved.</p>
                    <p class="system-version">System Version 1 | Last Updated: January 2026</p>
                </div>
            </div>
        </div>
    </footer>

    <!-- Loading Overlay -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="loading-content">
            <div class="spinner"></div>
            <p>Processing your request...</p>
        </div>
    </div>

    <!-- Printable Confirmation (rendered only during printing) -->
    <div id="printableConfirmation" class="printable-confirmation" aria-hidden="true"></div>

    <script src="js/farmer_app.js"></script>
    <script src="js/legal_modal.js"></script>
</body>
</html>