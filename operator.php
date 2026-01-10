<?php
session_start();
require_once 'php_helper/db_config.php';

if (!isset($_SESSION["loggedin"]) || $_SESSION["user_type"] !== 'Processor') {
    header("location: login.php");
    exit;
}

$branch_id = $_SESSION["branch_id"];
$user_id = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;

// Fetch basic user info for header
$first_name = $_SESSION['username'] ?? 'Processor';
$last_name = '';
$user_email = '';

if ($user_id) {
    $user_stmt = $pdo->prepare("SELECT first_name, last_name, email_address FROM users WHERE user_id = ?");
    $user_stmt->execute([$user_id]);
    if ($user = $user_stmt->fetch(PDO::FETCH_ASSOC)) {
        $first_name = $user['first_name'];
        $last_name = $user['last_name'];
        $user_email = $user['email_address'];
    }
}

// Generate initials for avatar
$initials = '';
if (!empty($first_name)) {
    $initials .= strtoupper(substr($first_name, 0, 1));
}
if (!empty($last_name)) {
    $initials .= strtoupper(substr($last_name, 0, 1));
}
if ($initials === '' && !empty($_SESSION['username'])) {
    $initials = strtoupper(substr($_SESSION['username'], 0, 1));
}

// Determine if we are in calendar view or date view
$selectedDate = isset($_GET['date']) ? $_GET['date'] : null;
$viewId = isset($_GET['view']) ? (int)$_GET['view'] : null;

// If a specific appointment is requested, resolve its date then switch to that date view
$highlightId = null;
if ($viewId) {
    $viewStmt = $pdo->prepare("SELECT appointment_id, date FROM appointments WHERE appointment_id = ? AND branch_id = ?");
    $viewStmt->execute([$viewId, $branch_id]);
    if ($row = $viewStmt->fetch(PDO::FETCH_ASSOC)) {
        $selectedDate = $row['date'];
        $highlightId = (int)$row['appointment_id'];
    }
}

// Basic sanitization for date
if ($selectedDate && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDate)) {
    $selectedDate = null;
}

// Calendar parameters
$year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
$month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('n');
if ($month < 1 || $month > 12) {
    $month = (int)date('n');
}
if ($year < 2000 || $year > 2100) {
    $year = (int)date('Y');
}

$firstOfMonth = sprintf('%04d-%02d-01', $year, $month);
$daysInMonth = (int)date('t', strtotime($firstOfMonth));
$lastOfMonth  = sprintf('%04d-%02d-%02d', $year, $month, $daysInMonth);

// Fetch appointment counts per day/slot for this branch and month
$calendarData = [];
$calendar_stmt = $pdo->prepare("SELECT date, time_slot, COUNT(*) as count
    FROM appointments
    WHERE branch_id = ? AND date BETWEEN ? AND ?
    GROUP BY date, time_slot");
$calendar_stmt->execute([$branch_id, $firstOfMonth, $lastOfMonth]);
foreach ($calendar_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $d = $row['date'];
    if (!isset($calendarData[$d])) {
        $calendarData[$d] = ['AM' => 0, 'PM' => 0];
    }
    $slot = strtoupper($row['time_slot']) === 'PM' ? 'PM' : 'AM';
    $calendarData[$d][$slot] = (int)$row['count'];
}

// If a specific date is selected, load its appointments
$appointmentsByDate = [];
if ($selectedDate) {
    $appt_stmt = $pdo->prepare("SELECT a.*, r.region_name, b.branch_name, f.type_name
        FROM appointments a
        JOIN regions r ON a.region_id = r.region_id
        JOIN branch b ON a.branch_id = b.branch_id
        LEFT JOIN farmer_type f ON a.farmer_type_id = f.farmer_type_id
        WHERE a.branch_id = ? AND a.date = ?
        ORDER BY FIELD(a.time_slot, 'AM', 'PM'), a.appointment_id");
    $appt_stmt->execute([$branch_id, $selectedDate]);
    $appointmentsByDate = $appt_stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Helper to build previous/next month links
$prevMonth = $month - 1;
$prevYear  = $year;
if ($prevMonth < 1) {
    $prevMonth = 12;
    $prevYear--;
}
$nextMonth = $month + 1;
$nextYear  = $year;
if ($nextMonth > 12) {
    $nextMonth = 1;
    $nextYear++;
}

$monthName = date('F', strtotime($firstOfMonth));
$today = date('Y-m-d');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>All Appointments - Calendar View</title>
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/processor.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="dashboard">
    <!-- Reuse top navigation from processor dashboard -->
    <nav class="top-nav">
        <div class="logo">
            <img src="img/nfa-logo.png" alt="NFA" class="nfa-logo">
            <div class="logo-text">
                <h1 class="nfa-title">National Food Authority</h1>
                <p class="nfa-subtitle">Appointment Overview</p>
            </div>
        </div>
        <div class="nav-center">
            <div class="nav-links">
                <a href="processor_dashboard.php" class="nav-link">
                    <i class="fas fa-chart-line"></i>
                    <span>Dashboard</span>
                </a>
                <a href="operator.php" class="nav-link active">
                    <i class="fas fa-calendar-check"></i>
                    <span>Appointments</span>
                </a>
                <a href="capacity_management.php" class="nav-link">
                    <i class="fas fa-warehouse"></i>
                    <span>Capacity</span>
                </a>
                <a href="reports.php" class="nav-link">
                    <i class="fas fa-chart-bar"></i>
                    <span>Reports</span>
                </a>
                <a href="farmers.php" class="nav-link">
                    <i class="fas fa-users"></i>
                    <span>Farmers</span>
                </a>
            </div>
        </div>
        <div class="user-actions">
            <div class="user-profile">
                <div class="user-avatar">
                    <?php echo htmlspecialchars($initials); ?>
                </div>
                <div class="user-info">
                    <span class="user-name"><?php echo htmlspecialchars($first_name . ' ' . $last_name); ?></span>
                    <span class="user-role">Processor</span>
                </div>
            </div>
        </div>
    </nav>

    <div class="container-fluid">
        <?php if (!$selectedDate): ?>
            <!-- Calendar View -->
            <section class="appointments-calendar">
                <header class="calendar-header">
                    <div>
                        <h1>All Appointments</h1>
                        <p>Select a date tile to see all appointments in detail.</p>
                    </div>
                    <div class="calendar-nav">
                        <a href="operator.php?month=<?php echo $prevMonth; ?>&year=<?php echo $prevYear; ?>" class="btn-nav-month"><i class="fas fa-chevron-left"></i></a>
                        <div class="calendar-current">
                            <span class="month-name"><?php echo htmlspecialchars($monthName); ?></span>
                            <span class="year-label"><?php echo $year; ?></span>
                        </div>
                        <a href="operator.php?month=<?php echo $nextMonth; ?>&year=<?php echo $nextYear; ?>" class="btn-nav-month"><i class="fas fa-chevron-right"></i></a>
                    </div>
                </header>

                <div class="calendar-legend">
                    <div class="legend-item">
                        <span class="legend-dot am"></span> AM Appointments
                    </div>
                    <div class="legend-item">
                        <span class="legend-dot pm"></span> PM Appointments
                    </div>
                </div>

                <div class="calendar-grid appointments-grid">
                    <?php
                    // Weekday headers (Sun-Sat)
                    $weekdays = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
                    foreach ($weekdays as $wd) {
                        echo '<div class="calendar-weekday">' . $wd . '</div>';
                    }

                    $firstWeekday = (int)date('w', strtotime($firstOfMonth)); // 0 (Sun) - 6 (Sat)

                    // Leading empty cells
                    for ($i = 0; $i < $firstWeekday; $i++) {
                        echo '<div class="calendar-cell empty"></div>';
                    }

                    for ($day = 1; $day <= $daysInMonth; $day++) {
                        $dateStr = sprintf('%04d-%02d-%02d', $year, $month, $day);
                        $hasData = isset($calendarData[$dateStr]);
                        $amCount = $hasData ? $calendarData[$dateStr]['AM'] : 0;
                        $pmCount = $hasData ? $calendarData[$dateStr]['PM'] : 0;
                        $isToday = ($dateStr === $today);

                        $classes = 'calendar-cell calendar-day';
                        if ($hasData) $classes .= ' has-appointments';
                        if ($isToday) $classes .= ' today';

                        echo '<a href="operator.php?date=' . $dateStr . '&month=' . $month . '&year=' . $year . '" class="' . $classes . '">';
                        echo '<div class="day-number">' . $day . '</div>';
                        if ($hasData && ($amCount > 0 || $pmCount > 0)) {
                            echo '<div class="slot-pills">';
                            if ($amCount > 0) {
                                echo '<div class="slot-pill am"><span>AM</span><strong>' . $amCount . '</strong></div>';
                            }
                            if ($pmCount > 0) {
                                echo '<div class="slot-pill pm"><span>PM</span><strong>' . $pmCount . '</strong></div>';
                            }
                            echo '</div>';
                        }
                        echo '</a>';
                    }
                    ?>
                </div>
            </section>
        <?php else: ?>
            <!-- Date-specific tiled view -->
            <section class="appointments-by-date">
                <header class="appointments-header">
                    <div>
                        <h1>Appointments on <?php echo date('F j, Y', strtotime($selectedDate)); ?></h1>
                        <p>Click a tile to view full farmer details.</p>
                    </div>
                    <div class="appointments-actions">
                        <a href="operator.php?month=<?php echo $month; ?>&year=<?php echo $year; ?>" class="btn-outline-secondary"><i class="fas fa-calendar"></i> Back to Calendar</a>
                    </div>
                </header>

                <?php if (count($appointmentsByDate) === 0): ?>
                    <div class="empty-state">
                        <i class="fas fa-calendar-times"></i>
                        <p>No appointments for this date.</p>
                        <a href="operator.php?month=<?php echo $month; ?>&year=<?php echo $year; ?>" class="btn-outline">
                            <i class="fas fa-arrow-left"></i> Back to Calendar
                        </a>
                    </div>
                <?php else: ?>
                    <div class="appointments-tiles">
                        <?php foreach ($appointmentsByDate as $appt):
                            $fullName = trim($appt['first_name'] . ' ' . $appt['middle_name'] . ' ' . $appt['last_name'] . ' ' . $appt['suffix']);
                            $slotLabel = strtoupper($appt['time_slot']) === 'PM' ? 'Afternoon' : 'Morning';
                            $isHighlight = isset($highlightId) && $highlightId && (int)$appt['appointment_id'] === $highlightId;
                        ?>
                            <div class="appointment-card<?php echo $isHighlight ? ' highlight' : ''; ?>" data-appointment-id="<?php echo (int)$appt['appointment_id']; ?>">
                                <div class="appointment-main">
                                    <div class="appointment-name"><?php echo htmlspecialchars($fullName); ?></div>
                                    <div class="appointment-ref">Ref: <?php echo htmlspecialchars($appt['reference_number']); ?></div>
                                </div>
                                <div class="appointment-meta-row">
                                    <span class="appointment-date"><i class="fas fa-calendar"></i> <?php echo date('M d, Y', strtotime($appt['date'])); ?></span>
                                    <span class="appointment-slot <?php echo strtoupper($appt['time_slot']) === 'PM' ? 'pm' : 'am'; ?>">
                                        <i class="fas fa-clock"></i> <?php echo $slotLabel; ?>
                                    </span>
                                    <span class="appointment-volume"><i class="fas fa-weight-hanging"></i> <?php echo number_format($appt['volume']); ?> bags</span>
                                </div>
                                <div class="appointment-actions">
                                    <button class="btn-view-details" 
                                        data-appointment-id="<?php echo (int)$appt['appointment_id']; ?>"
                                        data-name="<?php echo htmlspecialchars($fullName, ENT_QUOTES); ?>"
                                        data-reference="<?php echo htmlspecialchars($appt['reference_number'], ENT_QUOTES); ?>"
                                        data-date="<?php echo date('F j, Y', strtotime($appt['date'])); ?>"
                                        data-date-iso="<?php echo htmlspecialchars($appt['date'], ENT_QUOTES); ?>"
                                        data-slot="<?php echo $slotLabel; ?>"
                                        data-email="<?php echo htmlspecialchars($appt['email'], ENT_QUOTES); ?>"
                                        data-contact="<?php echo htmlspecialchars($appt['contact_number'], ENT_QUOTES); ?>"
                                        data-gender="<?php echo htmlspecialchars($appt['gender'], ENT_QUOTES); ?>"
                                        data-farmer-id="<?php echo htmlspecialchars($appt['farmer_id'], ENT_QUOTES); ?>"
                                        data-farmer-type="<?php echo htmlspecialchars($appt['type_name'] ?? '', ENT_QUOTES); ?>"
                                        data-region="<?php echo htmlspecialchars($appt['region_name'], ENT_QUOTES); ?>"
                                        data-branch="<?php echo htmlspecialchars($appt['branch_name'], ENT_QUOTES); ?>"
                                        data-volume="<?php echo number_format($appt['volume']); ?>"
                                        data-volume-raw="<?php echo (float)$appt['volume']; ?>"
                                        data-status="<?php echo htmlspecialchars($appt['status'], ENT_QUOTES); ?>"
                                    >
                                        View Details
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <!-- Details Modal -->
            <div id="detailsModal" class="modal-overlay" style="display:none;">
                <div class="modal-dialog">
                    <button class="modal-close" id="modalClose"><i class="fas fa-times"></i></button>
                    <div class="modal-header">
                        <h2 id="modalName"></h2>
                        <p id="modalReference" class="modal-ref"></p>
                    </div>
                    <div class="modal-body">
                        <div class="modal-section">
                            <h3>Appointment Details</h3>
                            <p><strong>Date:</strong> <span id="modalDate"></span></p>
                            <p><strong>Time Slot:</strong> <span id="modalSlot"></span></p>
                            <p><strong>Volume:</strong> <span id="modalVolume"></span> bags</p>
                            <p><strong>Status:</strong> <span id="modalStatus"></span></p>
                        </div>
                        <div class="modal-section">
                            <h3>Farmer Information</h3>
                            <p><strong>Farmer ID:</strong> <span id="modalFarmerId"></span></p>
                            <p><strong>Type:</strong> <span id="modalFarmerType"></span></p>
                            <p><strong>Gender:</strong> <span id="modalGender"></span></p>
                            <p><strong>Email:</strong> <span id="modalEmail"></span></p>
                            <p><strong>Contact:</strong> <span id="modalContact"></span></p>
                        </div>
                        <div class="modal-section">
                            <h3>Location</h3>
                            <p><strong>Region:</strong> <span id="modalRegion"></span></p>
                            <p><strong>Branch:</strong> <span id="modalBranch"></span></p>
                        </div>
                    </div>
                    <div class="modal-footer-actions">
                        <button id="btnConfirm" class="btn-view-details btn-inline-primary">Confirm</button>
                        <button id="btnReschedule" class="btn-view-details btn-inline-secondary">Reschedule</button>
                        <button id="btnReceive" class="btn-view-details btn-inline-success">Mark as Received</button>
                    </div>
                </div>
            </div>

            <!-- Receive Delivery Modal -->
            <div id="receiveModal" class="modal-overlay" style="display:none;">
                <div class="modal-dialog">
                    <button class="modal-close" id="receiveClose"><i class="fas fa-times"></i></button>
                    <div class="modal-header">
                        <h2>Record Delivery</h2>
                        <p class="modal-ref">Adjust the actual number of bags received.</p>
                    </div>
                    <div class="modal-body">
                        <div class="modal-section">
                            <label for="receiveVolume"><strong>Bags Received</strong></label>
                            <input type="number" id="receiveVolume" min="0" step="1" class="form-control" />
                            <small class="help-text">Pre-filled from the appointment volume but still editable.</small>
                        </div>
                        <div class="modal-section">
                            <button id="receiveSubmit" class="btn-view-details btn-inline-success">Submit Delivery</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Reschedule Modal -->
            <div id="reschedModal" class="modal-overlay" style="display:none;">
                <div class="modal-dialog">
                    <button class="modal-close" id="reschedClose"><i class="fas fa-times"></i></button>
                    <div class="modal-header">
                        <h2>Reschedule Appointment</h2>
                        <p class="modal-ref">Choose a new date and time slot based on availability.</p>
                    </div>
                    <div class="modal-body">
                        <div class="modal-section">
                            <div class="calendar-nav resched-nav">
                                <button type="button" class="btn-nav-month" id="reschedPrev"><i class="fas fa-chevron-left"></i></button>
                                <div class="calendar-current">
                                    <span class="month-name" id="reschedMonthLabel"></span>
                                    <span class="year-label" id="reschedYearLabel"></span>
                                </div>
                                <button type="button" class="btn-nav-month" id="reschedNext"><i class="fas fa-chevron-right"></i></button>
                            </div>
                            <div id="reschedGrid" class="calendar-grid appointments-grid"></div>
                        </div>
                        <div class="modal-section">
                            <h3>Time Slot</h3>
                            <div class="resched-slots">
                                <button type="button" class="slot-choice" id="slotAm" data-slot="AM">Morning (<span id="reschedAmRemaining">-</span> left)</button>
                                <button type="button" class="slot-choice" id="slotPm" data-slot="PM">Afternoon (<span id="reschedPmRemaining">-</span> left)</button>
                            </div>
                        </div>
                        <div class="modal-section">
                            <button id="reschedSubmit" class="btn-view-details btn-inline-primary" disabled>Save Reschedule</button>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script>
        window.branchId = <?php echo json_encode((int)$branch_id); ?>;
    </script>
    <script src="js/operator.js?v=<?php echo urlencode((string)@filemtime(__DIR__ . '/js/operator.js')); ?>"></script>
</body>
</html>
