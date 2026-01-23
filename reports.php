<?php
session_start();
require_once 'php_helper/db_config.php';

if (!isset($_SESSION["loggedin"]) || ($_SESSION["user_type"] ?? '') !== 'Processor') {
    header("location: login.php");
    exit;
}

$branch_id = (int)($_SESSION["branch_id"] ?? 0);
$user_id = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;

// Fetch basic user info for header
$first_name = $_SESSION['username'] ?? 'Processor';
$last_name = '';
$user_email = '';

if ($user_id) {
    $user_stmt = $pdo->prepare("SELECT first_name, last_name, email_address FROM users WHERE user_id = ?");
    $user_stmt->execute([(int)$user_id]);
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

// Branch details
$branch_stmt = $pdo->prepare("SELECT b.branch_name, r.region_name FROM branch b JOIN regions r ON b.region_id = r.region_id WHERE b.branch_id = ?");
$branch_stmt->execute([$branch_id]);
$branch = $branch_stmt->fetch(PDO::FETCH_ASSOC);
$branch_name = $branch ? $branch['branch_name'] : (string)$branch_id;
$region_name = $branch ? $branch['region_name'] : 'N/A';

// Farmer types for filter dropdown
$type_stmt = $pdo->query("SELECT farmer_type_id, type_name FROM farmer_type ORDER BY type_name");
$farmer_types = $type_stmt ? $type_stmt->fetchAll(PDO::FETCH_ASSOC) : [];

// Default date range presets
$type = strtolower((string)($_GET['type'] ?? ''));
$today = date('Y-m-d');
if ($type === 'daily' || $type === 'week') {
    $default_start = date('Y-m-d', strtotime('-6 days'));
    $default_end = $today;
} elseif ($type === 'month') {
    $default_start = date('Y-m-01');
    $default_end = date('Y-m-t');
} else {
    // default: last 30 days
    $default_start = date('Y-m-d', strtotime('-29 days'));
    $default_end = $today;
}

// Notifications
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
    <title>Reports</title>
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/processor.css">
    <link rel="stylesheet" href="css/reports.css?v=<?php echo urlencode((string)@filemtime(__DIR__ . '/css/reports.css')); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="dashboard">

    <nav class="top-nav">
        <div class="logo">
            <img src="img/nfa-logo.png" alt="NFA" class="nfa-logo">
            <div class="logo-text">
                <h1 class="nfa-title">National Food Authority</h1>
                <p class="nfa-subtitle">Reports</p>
            </div>
        </div>

        <div class="nav-center">
            <div class="nav-links">
                <a href="processor_dashboard.php" class="nav-link">
                    <i class="fas fa-chart-line"></i>
                    <span>Dashboard</span>
                </a>
                <a href="appointments.php" class="nav-link">
                    <i class="fas fa-calendar-check"></i>
                    <span>Appointments</span>
                </a>
                <a href="capacity_management.php" class="nav-link">
                    <i class="fas fa-warehouse"></i>
                    <span>Capacity</span>
                </a>
                <a href="reports.php" class="nav-link active">
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
                                $time_label = strtoupper((string)$n['time_slot']) === 'PM' ? 'Afternoon' : 'Morning';
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
                                            <span class="volume-badge"><i class="fas fa-weight-hanging"></i> <?php echo number_format((float)$n['volume']); ?> bags</span>
                                            <span class="status-badge <?php echo $status_class; ?>"><?php echo ucfirst($n['status']); ?></span>
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
                        <a href="appointments.php" class="view-all"><i class="fas fa-list"></i> View All Appointments</a>
                    </div>
                </div>
            </div>

            <div class="user-profile">
                <div class="user-avatar"><?php echo htmlspecialchars($initials); ?></div>
                <div class="user-info">
                    <span class="user-name"><?php echo htmlspecialchars($first_name . ' ' . $last_name); ?></span>
                    <span class="user-role">Processor</span>
                </div>
                <div class="user-dropdown" id="userDropdown">
                    <div class="user-dropdown-header">
                        <div class="user-dropdown-avatar"><?php echo htmlspecialchars($initials); ?></div>
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
                        <a href="login.php" class="dropdown-item logout"><i class="fas fa-sign-out-alt"></i> Logout</a>
                    </div>
                </div>
            </div>
        </div>
    </nav>

    <div class="container-fluid reports-page">
        <div class="reports-hero">
            <div class="reports-hero-left">
                <h1>Branch Reports</h1>
                <p class="reports-subtitle">Analytics for <strong><?php echo htmlspecialchars($branch_name); ?></strong> • <span><?php echo htmlspecialchars($region_name); ?></span></p>
                <div class="reports-hero-actions">
                    <button class="btn-view-details btn-inline-primary report-mode-btn is-active" id="btnModeAppointments" type="button"><i class="fas fa-table"></i> Appointment Report</button>
                    <button class="btn-view-details btn-inline-secondary report-mode-btn" id="btnModeWarehouse" type="button"><i class="fas fa-warehouse"></i> Warehouse Report</button>
                    <button class="btn-view-details btn-inline-secondary" id="btnPrintReport"><i class="fas fa-print"></i> Print Report</button>
                </div>
            </div>
            <div class="reports-hero-right">
                <div class="reports-updated" id="reportsUpdatedText">Ready</div>
                <div class="reports-tip"><i class="fas fa-lightbulb"></i> Tip: Filters auto-apply as you change them.</div>
            </div>
        </div>

        <div class="reports-grid">
            <aside class="reports-filters">
                <div class="filter-card">
                    <div class="filter-head">
                        <h2><i class="fas fa-sliders"></i> Filters</h2>
                        <button class="btn-mini" id="btnResetFilters" type="button"><i class="fas fa-rotate-left"></i> Reset</button>
                    </div>

                    <div class="filter-row">
                        <label>
                            <span>Date From</span>
                            <input type="date" id="filterStart" value="<?php echo htmlspecialchars($default_start); ?>">
                        </label>
                        <label>
                            <span>Date To</span>
                            <input type="date" id="filterEnd" value="<?php echo htmlspecialchars($default_end); ?>">
                        </label>
                    </div>

                    <div class="filter-row">
                        <label>
                            <span>Time Slot</span>
                            <select id="filterSlot">
                                <option value="">All</option>
                                <option value="AM">AM</option>
                                <option value="PM">PM</option>
                            </select>
                        </label>
                        <label>
                            <span>Farmer Type</span>
                            <select id="filterType">
                                <option value="0">All</option>
                                <?php foreach ($farmer_types as $ft): ?>
                                    <option value="<?php echo (int)$ft['farmer_type_id']; ?>"><?php echo htmlspecialchars($ft['type_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    </div>

                    <div class="filter-block">
                        <div class="filter-block-title">Status</div>
                        <div class="chip-grid" id="statusChips">
                            <label class="chip"><input type="checkbox" value="pending" checked> <span>Pending</span></label>
                            <label class="chip"><input type="checkbox" value="confirmed" checked> <span>Confirmed</span></label>
                            <label class="chip"><input type="checkbox" value="rescheduled" checked> <span>Rescheduled</span></label>
                            <label class="chip"><input type="checkbox" value="completed" checked> <span>Completed</span></label>
                            <label class="chip"><input type="checkbox" value="cancelled"> <span>Cancelled</span></label>
                        </div>
                    </div>

                    <div class="warehouse-only-note muted" id="warehouseOnlyNote" hidden>
                        Warehouse report uses <strong>completed</strong> appointments (delivery date).
                    </div>

                    <div class="filter-actions">
                        <button class="btn-view-details btn-inline-secondary" id="btnQuickMonth" type="button"><i class="fas fa-calendar"></i> This Month</button>
                        <button class="btn-view-details btn-inline-secondary" id="btnQuickWeek" type="button"><i class="fas fa-calendar-week"></i> Last 7 Days</button>
                    </div>
                </div>

                <div class="filter-card subtle" id="appointmentMiniMetrics">
                    <div class="mini-metrics">
                        <div class="mini-metric">
                            <div class="label">Completion Rate</div>
                            <div class="value" id="miniCompletion">0%</div>
                        </div>
                        <div class="mini-metric">
                            <div class="label">Average Volume</div>
                            <div class="value" id="miniAvgVol">0</div>
                        </div>
                    </div>
                </div>
            </aside>

            <main class="reports-content">
                <div id="appointmentReport">
                <section class="kpi-row">
                    <div class="kpi">
                        <div class="kpi-ico total"><i class="fas fa-list-check"></i></div>
                        <div>
                            <div class="kpi-label">Total Appointments</div>
                            <div class="kpi-val" id="kpiTotalCount">0</div>
                            <div class="kpi-sub"><span id="kpiTotalBags">0</span> bag(s)</div>
                        </div>
                    </div>

                    <div class="kpi">
                        <div class="kpi-ico completed"><i class="fas fa-circle-check"></i></div>
                        <div>
                            <div class="kpi-label">Completed Appointments</div>
                            <div class="kpi-val" id="kpiCompletedCount">0</div>
                            <div class="kpi-sub"><span id="kpiCompletedBags">0</span> bag(s)</div>
                        </div>
                    </div>

                    <div class="kpi">
                        <div class="kpi-ico confirmed"><i class="fas fa-circle-check"></i></div>
                        <div>
                            <div class="kpi-label">Confirmed Appointments</div>
                            <div class="kpi-val" id="kpiConfirmedCount">0</div>
                            <div class="kpi-sub"><span id="kpiConfirmedBags">0</span> bag(s)</div>
                        </div>
                    </div>

                    <div class="kpi">
                        <div class="kpi-ico rescheduled"><i class="fas fa-clock-rotate-left"></i></div>
                        <div>
                            <div class="kpi-label">Rescheduled Appointment</div>
                            <div class="kpi-val" id="kpiRescheduledCount">0</div>
                            <div class="kpi-sub"><span id="kpiRescheduledBags">0</span> bag(s)</div>
                        </div>
                    </div>

                    <div class="kpi">
                        <div class="kpi-ico pending"><i class="fas fa-hourglass-half"></i></div>
                        <div>
                            <div class="kpi-label">Pending Appointments</div>
                            <div class="kpi-val" id="kpiPendingCount">0</div>
                            <div class="kpi-sub"><span id="kpiPendingBags">0</span> bag(s)</div>
                        </div>
                    </div>

                    <div class="kpi">
                        <div class="kpi-ico cancelled"><i class="fas fa-ban"></i></div>
                        <div>
                            <div class="kpi-label">Cancelled Appointments</div>
                            <div class="kpi-val" id="kpiCancelledCount">0</div>
                            <div class="kpi-sub"><span id="kpiCancelledBags">0</span> bag(s)</div>
                        </div>
                    </div>
                </section>

                <section class="table-panel">
                    <div class="panel-head">
                        <h2><i class="fas fa-table"></i> Appointments</h2>
                    </div>

                    <div class="table-wrap">
                        <table class="report-table">
                            <thead>
                                <tr>
                                    <th>Reference</th>
                                    <th>Date</th>
                                    <th>Slot</th>
                                    <th>Farmer</th>
                                    <th>Type</th>
                                    <th class="num">Volume</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody id="reportTableBody">
                                <tr><td colspan="7" class="empty">Run a report to load data.</td></tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="pager">
                        <button class="btn-mini" id="btnPrev" type="button"><i class="fas fa-chevron-left"></i> Prev</button>
                        <div class="pager-info" id="pagerInfo">Page 1</div>
                        <button class="btn-mini" id="btnNext" type="button">Next <i class="fas fa-chevron-right"></i></button>
                    </div>
                </section>
                </div>

                <div id="warehouseReport" class="warehouse-report" hidden>
                    <section class="warehouse-panels">
                        <div class="table-panel">
                            <div class="panel-head">
                                <h2><i class="fas fa-chart-pie"></i> Current Warehouse Status</h2>
                            </div>
                            <div class="warehouse-meta">
                                <div class="warehouse-legend">
                                    <span><span class="lg inv"></span> Current Inventory</span>
                                    <span><span class="lg avail"></span> Available Capacity</span>
                                </div>
                                <div class="warehouse-metrics" id="warehouseCapacityMetrics">
                                    <span class="muted">Loading…</span>
                                </div>
                            </div>
                            <div class="warehouse-chart">
                                <canvas id="warehouseStatusChart" height="260"></canvas>
                            </div>
                        </div>

                        <div class="table-panel">
                            <div class="panel-head">
                                <h2><i class="fas fa-chart-line"></i> Warehouse Intake Trend</h2>
                                <div class="period-selector">
                                    <span class="granularity-pill" id="warehouseGranularityLabel">Viewing data by: —</span>
                                </div>
                            </div>
                            <div class="warehouse-chart">
                                <canvas id="warehouseMonthlyChart" height="260"></canvas>
                            </div>
                            <div class="warehouse-footnote muted" id="warehouseTrendNote"></div>
                        </div>
                    </section>
                </div>
            </main>
        </div>
    </div>

    <script>
        window.userId = <?php echo json_encode((int)($user_id ?? 0)); ?>;
        window.branchId = <?php echo json_encode((int)$branch_id); ?>;
        window.reportsPreset = <?php echo json_encode([
            'start' => $default_start,
            'end' => $default_end
        ]); ?>;
    </script>

    <!-- Chart.js is used only for the Warehouse Report view. -->

    <script>
        // Reports should be stable and not auto-reload on cross-tab appointment broadcasts.
        window.NFA_DISABLE_AUTO_APPT_REFRESH_RELOAD = true;
    </script>

    <script src="js/loading_ui.js?v=<?php echo urlencode((string)@filemtime(__DIR__ . '/js/loading_ui.js')); ?>"></script>
    <script src="js/refresh_bus.js?v=<?php echo urlencode((string)@filemtime(__DIR__ . '/js/refresh_bus.js')); ?>"></script>
    <script src="js/processor.js?v=<?php echo urlencode((string)@filemtime(__DIR__ . '/js/processor.js')); ?>"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2"></script>
    <script src="js/reports.js?v=<?php echo urlencode((string)@filemtime(__DIR__ . '/js/reports.js')); ?>"></script>
</body>
</html>
