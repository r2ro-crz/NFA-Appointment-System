<?php
// Public appointment status tracker (for farmers)
$prefillRef = isset($_GET['ref']) ? (string)$_GET['ref'] : '';
$prefillFarmerId = isset($_GET['farmer_id']) ? (string)$_GET['farmer_id'] : '';
$prefillEmail = isset($_GET['email']) ? (string)$_GET['email'] : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NFA Appointment Status Tracker</title>
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/tracker.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="tracker-page" data-clear-inputs="true">
    <header class="header tracker-header">
        <div class="container">
            <div class="logo">
                <img src="img/nfa-logo.png" alt="NFA Logo" class="logo-img">
                <div class="logo-text">
                    <h1>National Food Authority</h1>
                    <p>Appointment Status Tracker</p>
                </div>
            </div>
            <div class="header-actions">
                <a href="farmer_schedule.php" class="main-btn">
                    <i class="fas fa-calendar-plus"></i> Schedule Appointment
                </a>
                <a href="landing.html" class="main-btn" style="background: var(--dark-gray);">
                    <i class="fas fa-home"></i> Main Portal
                </a>
            </div>
        </div>
    </header>

    <main class="tracker-main">
        <div class="container">
            <section class="tracker-hero">
                <div class="tracker-hero-card">
                    <div class="tracker-hero-left">
                        <h2>Track your appointment in seconds</h2>
                        <p>Enter your <strong>Reference Number</strong> to see your appointment status and details.</p>
                        <div class="tracker-hero-hints">
                            <div class="hint">
                                <i class="fas fa-envelope"></i>
                                <span>Tip: Check the confirmation email for your reference number.</span>
                            </div>
                            <div class="hint">
                                <i class="fas fa-shield-halved"></i>
                                <span>For added security, you can also provide Farmer ID or Email (optional).</span>
                            </div>
                        </div>
                    </div>
                    <div class="tracker-hero-right">
                        <div class="tracker-form-card">
                            <form id="trackForm" novalidate>
                                <div class="field">
                                    <label for="referenceInput">Reference Number</label>
                                    <div class="field-row">
                                        <input id="referenceInput" name="reference" class="tracker-input" type="text" placeholder="e.g., NFA20260116ABC123" value="<?php echo htmlspecialchars($prefillRef, ENT_QUOTES); ?>" autocomplete="off" required data-keep-input="true">
                                        <button type="button" id="pasteBtn" class="tracker-btn tracker-btn-ghost" title="Paste from clipboard">
                                            <i class="fas fa-paste"></i>
                                        </button>
                                    </div>
                                    <div class="field-help">Format: <span class="mono">NFA</span> + date + 6 characters (example: <span class="mono">NFA20260116ABC123</span>)</div>
                                </div>

                                <div class="field-grid">
                                    <div class="field">
                                        <label for="farmerIdInput">Farmer ID (optional)</label>
                                        <input id="farmerIdInput" name="farmer_id" class="tracker-input" type="text" placeholder="Your Farmer ID" value="<?php echo htmlspecialchars($prefillFarmerId, ENT_QUOTES); ?>" autocomplete="off" data-keep-input="true">
                                    </div>
                                    <div class="field">
                                        <label for="emailInput">Email (optional)</label>
                                        <input id="emailInput" name="email" class="tracker-input" type="email" placeholder="name@example.com" value="<?php echo htmlspecialchars($prefillEmail, ENT_QUOTES); ?>" autocomplete="off" data-keep-input="true">
                                    </div>
                                </div>

                                <div class="tracker-actions">
                                    <button type="submit" class="tracker-btn tracker-btn-primary" id="trackBtn">
                                        <i class="fas fa-magnifying-glass"></i> Track Appointment
                                    </button>
                                    <button type="button" class="tracker-btn tracker-btn-secondary" id="clearBtn">
                                        <i class="fas fa-rotate-left"></i> Clear
                                    </button>
                                </div>

                                <div class="tracker-alert" id="alertBox" style="display:none;" role="status" aria-live="polite"></div>
                            </form>
                        </div>
                    </div>
                </div>
            </section>

            <section class="tracker-results" id="results" style="display:none;">
                <div class="results-card">
                    <div class="results-header">
                        <div>
                            <h3>Appointment Details</h3>
                            <p class="sub">Reference: <span class="mono" id="refValue">—</span></p>
                        </div>
                        <div class="results-actions">
                            <button class="tracker-btn tracker-btn-ghost" type="button" id="copyRefBtn">
                                <i class="fas fa-copy"></i> Copy Reference
                            </button>
                            <button class="tracker-btn tracker-btn-ghost" type="button" id="printBtn">
                                <i class="fas fa-print"></i> Print
                            </button>
                        </div>
                    </div>

                    <div class="status-row">
                        <span class="status-pill" id="statusPill">Status</span>
                        <span class="status-note" id="statusNote">—</span>
                    </div>

                    <div class="timeline" aria-label="Appointment status timeline">
                        <div class="timeline-step" data-step="submitted">
                            <div class="dot"></div>
                            <div class="label">Submitted</div>
                        </div>
                        <div class="timeline-step" data-step="confirmed">
                            <div class="dot"></div>
                            <div class="label">Confirmed</div>
                        </div>
                        <div class="timeline-step" data-step="completed">
                            <div class="dot"></div>
                            <div class="label">Completed</div>
                        </div>
                    </div>

                    <div class="results-grid">
                        <div class="info-card">
                            <div class="k">Branch</div>
                            <div class="v" id="branchValue">—</div>
                        </div>
                        <div class="info-card">
                            <div class="k">Region</div>
                            <div class="v" id="regionValue">—</div>
                        </div>
                        <div class="info-card">
                            <div class="k">Date</div>
                            <div class="v" id="dateValue">—</div>
                        </div>
                        <div class="info-card">
                            <div class="k">Time Slot</div>
                            <div class="v" id="slotValue">—</div>
                        </div>
                        <div class="info-card">
                            <div class="k">Volume</div>
                            <div class="v" id="volumeValue">—</div>
                        </div>
                        <div class="info-card">
                            <div class="k">Farmer</div>
                            <div class="v" id="farmerValue">—</div>
                        </div>
                    </div>

                    <div class="results-footer">
                        <div class="help">
                            <i class="fas fa-circle-info"></i>
                            <div>
                                <strong>Need help?</strong>
                                <div class="muted">If your appointment stays Pending for more than 2 business days, please contact your branch for assistance.</div>
                            </div>
                        </div>
                        <div class="cta">
                            <a class="tracker-btn tracker-btn-primary" href="farmer_schedule.php">
                                <i class="fas fa-calendar-plus"></i> Schedule Another
                            </a>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </main>

    <script src="js/clear_inputs.js"></script>
    <script src="js/loading_ui.js?v=<?php echo urlencode((string)@filemtime(__DIR__ . '/js/loading_ui.js')); ?>"></script>
    <script src="js/appointment_tracker.js"></script>
</body>
</html>
