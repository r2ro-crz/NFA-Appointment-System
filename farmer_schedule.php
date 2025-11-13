<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="https://public-frontend-cos.metadl.com/mgx/img/favicon.png" type="image/png">
    <title>NFA Farmer's Appointment System</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <div class="progress-bar-container">
        <div class="progress-bar" id="progressBar"></div>
    </div>
    
    <header class="header">
        <div class="container">
            <div class="logo">
                <h1>National Food Authority</h1>
                <p>Farmer's Appointment System</p>
            </div>
            <nav class="nav">
                <a href="landing.html" class="login-btn">Main Page</a>
            </nav>
        </div>
    </header>

    <main class="main">
        <div class="container">
            <section class="appointment-section">
                <div id="step-indicator" style="text-align: center; margin-bottom: 2rem;">
                    <span id="step1" style="font-weight: bold; color: #3498db;">Step 1: Location</span> &rarr;
                    <span id="step2">Step 2: Date & Time</span> &rarr;
                    <span id="step3">Step 3: Details & Submit</span>
                </div>
                
                <h2>Schedule Your Appointment</h2>
                <p>Select your region and branch to view available appointment slots</p>

                <div class="selection-panel">
                    <div class="dropdown-group">
                        <label for="region">Region: *</label>
                        <select id="region" name="region" required>
                            <option value="">Select Region</option>
                            </select>
                    </div>

                    <div class="dropdown-group">
                        <label for="branch">Branch: *</label>
                        <select id="branch" name="branch" required disabled>
                            <option value="">Select Branch</option>
                        </select>
                    </div>
                </div>

                <div class="capacity-info" id="capacityInfo" style="display: none;">
                    <h3>Branch Volume Capacity</h3>
                    <div class="capacity-grid">
                        
                        <div class="capacity-card total">
                            <div class="capacity-icon">🍚</div> <div class="capacity-details">
                                <h4>Available Volume to Accept</h4>
                                <p class="capacity-number" id="availableVolume">0</p>
                                <span class="capacity-label">kilograms (kg) remaining</span>
                            </div>
                        </div>
                        
                        <input type="hidden" id="amSlotCapacity" value="0">
                        <input type="hidden" id="pmSlotCapacity" value="0">
                        
                    </div>
                    
                    <div class="capacity-note">
                        <p><strong>Note:</strong> The number above reflects the **current available warehouse space**.</p>
                    </div>
                </div>

                <div class="calendar-container" id="calendarContainer" style="display: none;">
                    <h3>Available Appointment Dates</h3>
                    <div class="calendar-header">
                        <button id="prevMonth" class="nav-btn">&lt;</button>
                        <span id="currentMonth"></span>
                        <button id="nextMonth" class="nav-btn">&gt;</button>
                    </div>
                    <div class="calendar" id="calendar"></div>
                    
                    <div class="time-slots" id="timeSlots" style="display: none;">
                        <h4>Select Time Slot for <span id="selectedDateDisplay"></span></h4>
                        <div class="slots">
                            <button class="time-slot" data-time="AM">
                                <div class="slot-header">Morning (8:00 AM - 12:00 PM)</div>
                                <div class="slot-availability" id="amAvailability">Available slots: --</div>
                            </button>
                            <button class="time-slot" data-time="PM">
                                <div class="slot-header">Afternoon (1:00 PM - 5:00 PM)</div>
                                <div class="slot-availability" id="pmAvailability">Available slots: --</div>
                            </button>
                        </div>
                    </div>
                </div>

                <div class="appointment-form" id="appointmentForm" style="display: none;">
                    <h3>Appointment Details</h3>
                    <form id="farmerForm">
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="firstName">First Name *</label>
                                <input type="text" id="firstName" name="firstName" required>
                            </div>
                            <div class="form-group">
                                <label for="middleName">Middle Name</label>
                                <input type="text" id="middleName" name="middleName">
                            </div>
                            <div class="form-group">
                                <label for="lastName">Last Name *</label>
                                <input type="text" id="lastName" name="lastName" required>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="email">Email Address *</label>
                                <input type="email" id="email" name="email" required>
                            </div>
                            <div class="form-group">
                                <label for="contact">Contact Number *</label>
                                <input type="tel" id="contact" name="contact" required>
                            </div>
                            <div class="form-group">
                                <label for="gender">Gender *</label>
                                <select id="gender" name="gender" required>
                                    <option value="">Select Gender</option>
                                    <option value="male">Male</option>
                                    <option value="female">Female</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="volume">Volume (in Kilograms - kg) *</label>
                                <input type="number" id="volume" name="volume" min="1" step="any" required>
                            </div>
                            <div class="form-group">
                                <label for="farmerType">Farmer Type *</label>
                                <select id="farmerType" name="farmerType" required>
                                    <option value="">Select Farmer Type</option>
                                    </select>
                            </div>
                             <div class="form-group" style="visibility: hidden;">
                                <label>&nbsp;</label>
                                <input type="hidden">
                            </div>
                        </div>

                        <div class="appointment-summary">
                            <h4>Appointment Summary</h4>
                            <p><strong>Region:</strong> <span id="summaryRegion"></span></p>
                            <p><strong>Branch:</strong> <span id="summaryBranch"></span></p>
                            <p><strong>Date:</strong> <span id="summaryDate"></span></p>
                            <p><strong>Time:</strong> <span id="summaryTime"></span></p>
                        </div>

                        <div class="captcha-section">
                            <label for="captcha">Please type the following word: <span id="captchaQuestion" style="font-family: cursive; font-size: 1.2em; color: #3498db;"></span></label>
                            <input type="text" id="captcha" name="captcha" required autocomplete="off">
                            <button type="button" id="refreshCaptcha" class="nav-btn" style="margin-top: 5px; padding: 0.5rem;">Refresh</button>
                        </div>

                        <button type="submit" class="submit-btn">Submit Appointment</button>
                    </form>
                </div>
            </section>
        </div>
    </main>

    <footer class="footer">
        <div class="container">
            <p>&copy; 2025 National Food Authority. All rights reserved.</p>
        </div>
    </footer>

    <div class="modal" id="successModal">
        <div class="modal-content">
            <h3>Appointment Confirmed!</h3>
            <p>Your appointment has been successfully scheduled. You will receive a confirmation email shortly.</p>
            <p><strong>Reference Number:</strong> <span id="referenceNumber"></span></p>
            <button onclick="closeModal()" class="modal-btn">Close</button>
        </div>
    </div>

    <script src="js/farmer_app.js"></script>
</body>
</html>