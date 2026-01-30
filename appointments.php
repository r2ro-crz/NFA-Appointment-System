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
    // Optional: include cancellation details if the log table exists
    $hasCancelLog = false;
    $cancelTable = null;
    try {
        $t = $pdo->query("SHOW TABLES LIKE 'cancelled_appointments'");
        $hasCancelLog = $t && $t->fetchColumn();
        if ($hasCancelLog) {
            $cancelTable = 'cancelled_appointments';
        } else {
            // Backward-compat fallback (legacy name)
            $t2 = $pdo->query("SHOW TABLES LIKE 'appointment_cancellations'");
            $hasCancelLog = $t2 && $t2->fetchColumn();
            if ($hasCancelLog) {
                $cancelTable = 'appointment_cancellations';
            }
        }
    } catch (Exception $e) {
        $hasCancelLog = false;
    }

    $cancelSelect = '';
    $cancelJoin = '';
    if ($hasCancelLog && $cancelTable) {
        $cancelSelect = ", ac.reason_code AS cancel_reason_code, ac.reason_detail AS cancel_reason_detail, ac.cancelled_at AS cancel_cancelled_at";
        $cancelJoin = "
        LEFT JOIN (
            SELECT c1.appointment_id, c1.reason_code, c1.reason_detail, c1.cancelled_at
            FROM {$cancelTable} c1
            INNER JOIN (
                SELECT appointment_id, MAX(cancellation_id) AS max_id
                FROM {$cancelTable}
                GROUP BY appointment_id
            ) c2
                ON c1.appointment_id = c2.appointment_id
               AND c1.cancellation_id = c2.max_id
        ) ac ON ac.appointment_id = a.appointment_id";
    }

    $appt_stmt = $pdo->prepare("SELECT a.*, r.region_name, b.branch_name, f.type_name{$cancelSelect}
        FROM appointments a
        JOIN regions r ON a.region_id = r.region_id
        JOIN branch b ON a.branch_id = b.branch_id
        LEFT JOIN farmer_type f ON a.farmer_type_id = f.farmer_type_id
        {$cancelJoin}
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

// Notifications (same behavior as processor dashboard)
$has_notif_deleted = false;
try {
    $col = $pdo->query("SHOW COLUMNS FROM appointments LIKE 'notif_deleted'")->fetch(PDO::FETCH_ASSOC);
    $has_notif_deleted = !empty($col);
} catch (PDOException $e) {
    $has_notif_deleted = false;
}

$notif_sql = "SELECT appointment_id, first_name, last_name, status, date, time_slot, volume, is_read FROM appointments WHERE branch_id = ? AND status IN ('pending', 'cancelled')";
if ($has_notif_deleted) {
    $notif_sql .= " AND (notif_deleted IS NULL OR notif_deleted = 0)";
}
$notif_sql .= " ORDER BY appointment_id DESC LIMIT 10";

$notif_stmt = $pdo->prepare($notif_sql);
$notif_stmt->execute([$branch_id]);
$notifications = $notif_stmt->fetchAll(PDO::FETCH_ASSOC);
$new_count = count(array_filter($notifications, fn($n) => (empty($n['is_read']) || $n['is_read'] == 0)));
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
                <a href="appointments.php" class="nav-link active">
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
            </div>
        </div>
        <div class="user-actions">
            <div class="notif-wrapper" id="notifWrapper">
                <div class="notif-icon">
                    <i class="fas fa-bell"></i>
                    <?php if ($new_count > 0): ?>
                        <span class="notif-badge pulse"><?php echo $new_count; ?></span>
                    <?php endif; ?>
                </div>
                <div class="notif-dropdown" id="notifDropdown">
                    <div class="notif-header">
                        <h4><i class="fas fa-bell"></i> Notifications</h4>
                        <?php if ($new_count > 0): ?>
                            <button class="mark-all-read" id="markAllRead">
                                <i class="fas fa-check-double"></i> Mark All Read
                            </button>
                        <?php endif; ?>
                    </div>
                    <div class="notif-list" id="notifList">
                        <?php if (count($notifications) > 0): ?>
                            <?php foreach ($notifications as $n):
                                $unread = (empty($n['is_read']) || $n['is_read'] == 0);
                                $status_class = $n['status'] == 'pending' ? 'status-pending' : 'status-cancelled';
                                $time_label = $n['time_slot'] == 'AM' ? 'Morning' : 'Afternoon';
                            ?>
                                <a class="notif-item <?php echo $unread ? 'unread' : ''; ?>"
                                   href="appointments.php?view=<?php echo (int)$n['appointment_id']; ?>"
                                   data-appointment-id="<?php echo (int)$n['appointment_id']; ?>"
                                   data-is-read="<?php echo $unread ? '0' : '1'; ?>">
                                    <div class="notif-icon-small">
                                        <?php if ($n['status'] == 'pending'): ?>
                                            <i class="fas fa-clock status-pending"></i>
                                        <?php else: ?>
                                            <i class="fas fa-times-circle status-cancelled"></i>
                                        <?php endif; ?>
                                    </div>
                                    <div class="notif-content">
                                        <div class="notif-title">
                                            <?php echo $n['status'] == 'pending' ? 'New Appointment' : 'Cancellation'; ?>
                                            <span class="notif-time"><?php echo date('M d', strtotime($n['date'])); ?> (<?php echo $time_label; ?>)</span>
                                        </div>
                                        <div class="notif-details">
                                            <strong><?php echo htmlspecialchars($n['first_name'] . ' ' . $n['last_name']); ?></strong>
                                            for <?php echo date('M d', strtotime($n['date'])); ?> (<?php echo $time_label; ?>)
                                        </div>
                                        <div class="notif-meta">
                                            <span class="volume-badge">
                                                <i class="fas fa-weight-hanging"></i> <?php echo number_format($n['volume']); ?> bags
                                            </span>
                                            <span class="status-badge <?php echo $status_class; ?>">
                                                <?php echo ucfirst($n['status']); ?>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="notif-actions">
                                        <label class="notif-check" title="Mark as <?php echo $unread ? 'Read' : 'Unread'; ?>">
                                            <input class="notif-checkbox" type="checkbox" <?php echo $unread ? '' : 'checked'; ?> aria-label="Toggle read status">
                                            <span class="notif-check-ui" aria-hidden="true"></span>
                                        </label>
                                        <span class="notif-delete" role="button" tabindex="0" title="Delete notification" aria-label="Delete notification">
                                            <i class="fas fa-trash"></i>
                                        </span>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="no-notifications">
                                <i class="fas fa-check-circle"></i>
                                <p>No new notifications</p>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="notif-footer">
                        <a href="appointments.php" class="view-all">
                            <i class="fas fa-list"></i> View All Appointments
                        </a>
                    </div>
                </div>
            </div>

            <div class="user-profile">
                <div class="user-avatar">
                    <?php echo htmlspecialchars($initials); ?>
                </div>
                <div class="user-info">
                    <span class="user-name"><?php echo htmlspecialchars($first_name . ' ' . $last_name); ?></span>
                    <span class="user-role">Processor</span>
                </div>
                <div class="user-dropdown" id="userDropdown">
                    <div class="user-dropdown-header">
                        <div class="user-dropdown-avatar">
                            <?php echo htmlspecialchars($initials); ?>
                        </div>
                        <div class="user-dropdown-info">
                            <strong><?php echo htmlspecialchars($first_name . ' ' . $last_name); ?></strong>
                            <small><?php echo htmlspecialchars($user_email); ?></small>
                            <div class="user-role-badge">Processor</div>
                        </div>
                    </div>
                    <div class="user-dropdown-menu">
                        <a href="profile.php" class="dropdown-item">
                            <i class="fas fa-user-cog"></i>
                            <span class="dropdown-item-content">
                                <span class="dropdown-item-title">My Profile</span>
                                <span class="dropdown-item-desc">View and update your account</span>
                            </span>
                            <i class="fas fa-chevron-right dropdown-item-arrow"></i>
                        </a>
                        <a href="settings.php" class="dropdown-item">
                            <i class="fas fa-cog"></i>
                            <span class="dropdown-item-content">
                                <span class="dropdown-item-title">Settings</span>
                                <span class="dropdown-item-desc">Preferences and appearance</span>
                            </span>
                            <i class="fas fa-chevron-right dropdown-item-arrow"></i>
                        </a>
                        <div class="dropdown-divider"></div>
                        <a href="logout.php" class="dropdown-item logout">
                            <i class="fas fa-sign-out-alt"></i> Logout
                        </a>
                    </div>
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
                        <a href="appointments.php?month=<?php echo $prevMonth; ?>&year=<?php echo $prevYear; ?>" class="btn-nav-month"><i class="fas fa-chevron-left"></i></a>
                        <div class="calendar-current">
                            <span class="month-name"><?php echo htmlspecialchars($monthName); ?></span>
                            <span class="year-label"><?php echo $year; ?></span>
                        </div>
                        <a href="appointments.php?month=<?php echo $nextMonth; ?>&year=<?php echo $nextYear; ?>" class="btn-nav-month"><i class="fas fa-chevron-right"></i></a>
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

                        echo '<a href="appointments.php?date=' . $dateStr . '&month=' . $month . '&year=' . $year . '" class="' . $classes . '">';
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
                        <a href="appointments.php?month=<?php echo $month; ?>&year=<?php echo $year; ?>" class="btn-outline-secondary"><i class="fas fa-calendar"></i> Back to Calendar</a>
                    </div>
                </header>

                <?php if (count($appointmentsByDate) === 0): ?>
                    <div class="empty-state">
                        <i class="fas fa-calendar-times"></i>
                        <p>No appointments for this date.</p>
                        <a href="appointments.php?month=<?php echo $month; ?>&year=<?php echo $year; ?>" class="btn-outline">
                            <i class="fas fa-arrow-left"></i> Back to Calendar
                        </a>
                    </div>
                <?php else: ?>
                    <div class="appointments-tiles">
                        <?php foreach ($appointmentsByDate as $appt):
                            $fullName = trim($appt['first_name'] . ' ' . $appt['middle_name'] . ' ' . $appt['last_name'] . ' ' . $appt['suffix']);
                            $slotLabel = strtoupper($appt['time_slot']) === 'PM' ? 'Afternoon' : 'Morning';
                            $isHighlight = isset($highlightId) && $highlightId && (int)$appt['appointment_id'] === $highlightId;

                            $modeRaw = strtolower((string)($appt['mode'] ?? 'appointment'));
                            $modeLabel = ($modeRaw === 'walk-in' || $modeRaw === 'walkin') ? 'Walk-in' : 'Appointment';
                            $modeClass = ($modeLabel === 'Walk-in') ? 'mode-walk-in' : 'mode-appointment';
                            $modeIcon = ($modeLabel === 'Walk-in') ? 'fa-person-walking' : 'fa-calendar-check';

                            $cancelReasonCode = (string)($appt['cancel_reason_code'] ?? '');
                            $cancelReasonDetail = (string)($appt['cancel_reason_detail'] ?? '');
                            $cancelReasonText = trim($cancelReasonCode . ($cancelReasonDetail !== '' ? (': ' . $cancelReasonDetail) : ''));
                            $cancelledAtRaw = (string)($appt['cancel_cancelled_at'] ?? '');
                            $cancelledAtLabel = $cancelledAtRaw !== '' ? date('M d, Y h:i A', strtotime($cancelledAtRaw)) : '';

                            $statusRaw = isset($appt['status']) ? strtolower((string)$appt['status']) : '';
                            $statusLabel = $statusRaw !== '' ? ucfirst($statusRaw) : 'Unknown';
                            $statusClass = 'status-' . preg_replace('/[^a-z0-9\-]/', '', $statusRaw);
                        ?>
                            <div class="appointment-card<?php echo $isHighlight ? ' highlight' : ''; ?>" data-appointment-id="<?php echo (int)$appt['appointment_id']; ?>">
                                <span class="appt-status-badge <?php echo htmlspecialchars($statusClass); ?>">
                                    <?php echo htmlspecialchars($statusLabel); ?>
                                </span>
                                <div class="appointment-main">
                                    <div class="appointment-name"><?php echo htmlspecialchars($fullName); ?></div>
                                    <div class="appointment-ref">Ref: <?php echo htmlspecialchars($appt['reference_number']); ?></div>
                                </div>
                                <div class="appointment-meta-row">
                                    <span class="appointment-date"><i class="fas fa-calendar"></i> <?php echo date('M d, Y', strtotime($appt['date'])); ?></span>
                                    <span class="appointment-slot <?php echo strtoupper($appt['time_slot']) === 'PM' ? 'pm' : 'am'; ?>">
                                        <i class="fas fa-clock"></i> <?php echo $slotLabel; ?>
                                    </span>
                                    <span class="appointment-mode <?php echo htmlspecialchars($modeClass); ?>">
                                        <i class="fas <?php echo htmlspecialchars($modeIcon); ?>"></i> <?php echo htmlspecialchars($modeLabel); ?>
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
                                        data-slot-raw="<?php echo htmlspecialchars(strtoupper((string)$appt['time_slot']) === 'PM' ? 'PM' : 'AM', ENT_QUOTES); ?>"
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
                                        data-mode="<?php echo htmlspecialchars($modeLabel, ENT_QUOTES); ?>"
                                        data-cancel-reason="<?php echo htmlspecialchars($cancelReasonText, ENT_QUOTES); ?>"
                                        data-cancelled-at-label="<?php echo htmlspecialchars($cancelledAtLabel, ENT_QUOTES); ?>"
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
                            <p><strong>Mode:</strong> <span id="modalMode"></span></p>
                            <p><strong>Volume:</strong> <span id="modalVolume"></span> bags</p>
                            <p><strong>Status:</strong> <span id="modalStatus"></span></p>

                            <div id="modalCancellation" style="display:none; margin-top: 0.75rem;">
                                <p><strong>Cancelled On:</strong> <span id="modalCancelledAt"></span></p>
                                <p><strong>Cancellation Reason:</strong> <span id="modalCancelReason"></span></p>
                            </div>
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
                            <label for="receivePrice"><strong>Price (₱)</strong></label>
                            <input type="number" id="receivePrice" min="0" step="0.01" class="form-control" placeholder="e.g. 1250.00" />
                            <small class="help-text">Payment to the farmer for the delivered rice.</small>
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
        window.userId = <?php echo json_encode((int)($user_id ?? 0)); ?>;
        window.branchId = <?php echo json_encode((int)$branch_id); ?>;
    </script>
    <script src="js/loading_ui.js?v=<?php echo urlencode((string)@filemtime(__DIR__ . '/js/loading_ui.js')); ?>"></script>
    <script src="js/refresh_bus.js?v=<?php echo urlencode((string)@filemtime(__DIR__ . '/js/refresh_bus.js')); ?>"></script>
    <script src="js/processor.js?v=<?php echo urlencode((string)@filemtime(__DIR__ . '/js/processor.js')); ?>"></script>
    <script src="js/appointments.js?v=<?php echo urlencode((string)@filemtime(__DIR__ . '/js/appointments.js')); ?>"></script>
</body>
</html>
