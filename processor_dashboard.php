<?php
session_start();
require_once 'php_helper/db_config.php';
require_once __DIR__ . '/php_helper/branding.php';

// Prevent caching of protected pages (helps prevent back-button dashboard access after logout)
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
header('Expires: 0');

if (!isset($_SESSION["loggedin"]) || $_SESSION["user_type"] !== 'Processor') {
    header("location: login.php");
    exit;
}

$branch_id = $_SESSION["branch_id"];
$user_id = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;

// Get user details
if ($user_id) {
    // users table stores email as email_address
    $user_stmt = $pdo->prepare("SELECT first_name, last_name, email_address FROM users WHERE user_id = ?");
    $user_stmt->execute([$user_id]);
    $user = $user_stmt->fetch(PDO::FETCH_ASSOC);
    $first_name = $user ? $user['first_name'] : $_SESSION['username'];
    $last_name = $user ? $user['last_name'] : '';
    $user_email = $user ? $user['email_address'] : '';
} else {
    $first_name = $_SESSION['username'];
    $last_name = '';
    $user_email = '';
}

// Generate initials for avatar (e.g., "JD")
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

// Get branch details (branch table includes address + website_link)
$branch_stmt = $pdo->prepare("SELECT b.branch_name, b.address, b.website_link, r.region_name FROM branch b JOIN regions r ON b.region_id = r.region_id WHERE b.branch_id = ?");
$branch_stmt->execute([$branch_id]);
$branch = $branch_stmt->fetch(PDO::FETCH_ASSOC);
$branch_name = $branch ? $branch['branch_name'] : $branch_id;
$branch_region = $branch ? $branch['region_name'] : 'N/A';
$branch_address = $branch && !empty($branch['address']) ? $branch['address'] : 'N/A';
$branch_website = $branch && !empty($branch['website_link']) ? $branch['website_link'] : 'N/A';

$branch_website_href = '';
$branch_website_is_external = false;
$branch_website_icon = 'fa-globe-asia';
if ($branch_website !== 'N/A') {
    $branch_website_trim = trim((string)$branch_website);

    if ($branch_website_trim === '') {
        $branch_website = 'N/A';
    } elseif (preg_match('/^https?:\/\//i', $branch_website_trim)) {
        $branch_website_href = $branch_website_trim;
        $branch_website_is_external = true;
        $branch_website_icon = 'fa-globe-asia';
    } elseif (preg_match('/^www\./i', $branch_website_trim)) {
        $branch_website_href = 'https://' . $branch_website_trim;
        $branch_website_is_external = true;
        $branch_website_icon = 'fa-globe-asia';
    } elseif (strpos($branch_website_trim, '@') !== false) {
        // Treat as email address
        $branch_website_href = 'mailto:' . $branch_website_trim;
        $branch_website_is_external = false;
        $branch_website_icon = 'fa-envelope';
    } else {
        // Treat as a bare domain/host/path
        $branch_website_href = 'https://' . $branch_website_trim;
        $branch_website_is_external = true;
        $branch_website_icon = 'fa-globe-asia';
    }
}

// Get warehouse capacity
$stmt = $pdo->prepare("SELECT warehouse_capacity, inventory FROM volume_capacity WHERE branch_id = ?");
$stmt->execute([$branch_id]);
$cap = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['warehouse_capacity' => 0, 'inventory' => 0];
$available_capacity = max(0, $cap['warehouse_capacity'] - $cap['inventory']);
$capacity_percentage = $cap['warehouse_capacity'] > 0 ? ($cap['inventory'] / $cap['warehouse_capacity']) * 100 : 0;

// Get notifications (appointments table has no created_at; use date/time_slot)
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
$new_count = count(array_filter($notifications, fn($n) => $n['status'] == 'pending' && (empty($n['is_read']) || $n['is_read'] == 0)));

// Earliest pending appointment (for quick navigation)
$earliest_pending_id = null;
try {
    $pending_nav_stmt = $pdo->prepare("SELECT appointment_id
        FROM appointments
        WHERE branch_id = ? AND LOWER(status) = 'pending'
        ORDER BY date ASC, FIELD(time_slot, 'AM', 'PM') ASC, appointment_id ASC
        LIMIT 1");
    $pending_nav_stmt->execute([$branch_id]);
    $earliest_pending_id = $pending_nav_stmt->fetchColumn();
    if ($earliest_pending_id !== null) {
        $earliest_pending_id = (int)$earliest_pending_id;
        if ($earliest_pending_id <= 0) $earliest_pending_id = null;
    }
} catch (PDOException $e) {
    $earliest_pending_id = null;
}

$pendingQuickHref = $earliest_pending_id ? ('appointments.php?view=' . $earliest_pending_id) : 'appointments.php';

// KPI metrics
$today = date('Y-m-d');

// All-time appointment stats (accurate per-status counts)
$overall_stmt = $pdo->prepare("SELECT
    COUNT(*) as total,
    SUM(CASE WHEN LOWER(status) = 'pending' THEN 1 ELSE 0 END) as pending,
    SUM(CASE WHEN LOWER(status) = 'confirmed' THEN 1 ELSE 0 END) as confirmed,
    SUM(CASE WHEN LOWER(status) = 'rescheduled' THEN 1 ELSE 0 END) as rescheduled,
    SUM(CASE WHEN LOWER(status) = 'completed' THEN 1 ELSE 0 END) as completed,
    SUM(CASE WHEN LOWER(status) IN ('cancelled','canceled') THEN 1 ELSE 0 END) as cancelled
    FROM appointments
    WHERE branch_id = ?");
$overall_stmt->execute([$branch_id]);
$overall_stats = $overall_stmt->fetch(PDO::FETCH_ASSOC) ?: [
    'total' => 0,
    'pending' => 0,
    'confirmed' => 0,
    'rescheduled' => 0,
    'completed' => 0,
    'cancelled' => 0,
];

// Expected to arrive: confirmed appointments scheduled for today and beyond
// Show this KPI as bags (volume) since it's delivery-focused.
$expected_stmt = $pdo->prepare("SELECT
        COUNT(*) as cnt,
        COALESCE(SUM(volume), 0) as bags
    FROM appointments
    WHERE branch_id = ? AND LOWER(status) = 'confirmed' AND date >= ?");
$expected_stmt->execute([$branch_id, $today]);
$expected_row = $expected_stmt->fetch(PDO::FETCH_ASSOC) ?: ['cnt' => 0, 'bags' => 0];
$expected_to_arrive_count = (int)($expected_row['cnt'] ?? 0);
$expected_to_arrive_bags = (int)($expected_row['bags'] ?? 0);

// Get recent appointments (last 7 days)
$week_ago = date('Y-m-d', strtotime('-7 days'));
$weekly_stmt = $pdo->prepare("SELECT DATE(date) as day, COUNT(*) as count, 
    SUM(volume) as total_volume FROM appointments 
    WHERE branch_id = ? AND date >= ? AND LOWER(status) NOT IN ('cancelled','canceled')
    GROUP BY DATE(date) ORDER BY day");
$weekly_stmt->execute([$branch_id, $week_ago]);
$weekly_data = $weekly_stmt->fetchAll(PDO::FETCH_ASSOC);

// Prepare weekly data for chart
$week_days = [];
$week_counts = [];
$week_volumes = [];

for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $week_days[] = date('D', strtotime($date));
    
    $found = false;
    foreach ($weekly_data as $data) {
        if ($data['day'] == $date) {
            $week_counts[] = (int)$data['count'];
            $week_volumes[] = (int)$data['total_volume'];
            $found = true;
            break;
        }
    }
    
    if (!$found) {
        $week_counts[] = 0;
        $week_volumes[] = 0;
    }
}


?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?php echo htmlspecialchars(nfa_page_title('Processor Dashboard'), ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="icon" href="<?php echo htmlspecialchars(NFA_FAVICON, ENT_QUOTES, 'UTF-8'); ?>" type="image/png"/>
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/processor.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="dashboard">
    <!-- Hidden data store for JavaScript -->
    <div id="chart-data-store" 
         data-capacity="<?php echo (float)$cap['warehouse_capacity']; ?>" 
         data-inventory="<?php echo (float)$cap['inventory']; ?>" 
         data-available="<?php echo (float)$available_capacity; ?>"
         data-percentage="<?php echo round($capacity_percentage, 1); ?>"
         data-week-days='<?php echo json_encode($week_days); ?>'
         data-week-counts='<?php echo json_encode($week_counts); ?>'
         data-week-volumes='<?php echo json_encode($week_volumes); ?>'
         style="display:none;"></div>

    <!-- Top Navigation -->
    <nav class="top-nav">
        <div class="logo">
            <div class="brand-logos">
                <img src="<?php echo htmlspecialchars(NFA_SYSTEM_LOGO, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars(NFA_SYSTEM_NAME, ENT_QUOTES, 'UTF-8'); ?>" class="system-logo">
            </div>
            <div class="logo-text">
                <h1 class="nfa-title"><?php echo htmlspecialchars(NFA_BRAND_NAME, ENT_QUOTES, 'UTF-8'); ?></h1>
                <p class="nfa-subtitle"><span class="page-subtitle">Processor Dashboard</span></p>
            </div>
        </div>
        
        <div class="nav-center">
            <div class="nav-links">
                <a href="processor_dashboard.php" class="nav-link active">
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
                            <?php foreach ($notifications as $i => $n): 
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
                        <a href="support_inbox.php" class="dropdown-item">
                            <i class="fas fa-headset"></i>
                            <span class="dropdown-item-content">
                                <span class="dropdown-item-title">Support Inbox</span>
                                <span class="dropdown-item-desc">Farmer live chats</span>
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

    <!-- Main Content -->
    <div class="container-fluid">
        <!-- Welcome Header -->
        <div class="welcome-header">
            <div class="welcome-text">
                <h1>Welcome back, <?php echo htmlspecialchars($first_name); ?>!</h1>
                <p>Here's what's happening at your branch today</p>
            </div>
            <div class="branch-info">
                <div class="branch-card">
                    <div class="branch-icon">
                        <i class="fas fa-warehouse"></i>
                    </div>
                    <div class="branch-details">
                        <div class="branch-title">
                            <h3><?php echo htmlspecialchars($branch_name); ?></h3>
                            <div class="branch-subtitle"><?php echo htmlspecialchars($branch_region); ?></div>
                        </div>
                        <p><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($branch_address); ?></p>
                        <p>
                            <i class="fas <?php echo htmlspecialchars($branch_website_icon); ?>"></i>
                            <?php if (!empty($branch_website_href)): ?>
                                <a class="branch-link" href="<?php echo htmlspecialchars($branch_website_href); ?>" <?php echo $branch_website_is_external ? 'target="_blank" rel="noopener noreferrer"' : ''; ?>>
                                    <?php echo htmlspecialchars($branch_website); ?>
                                </a>
                            <?php else: ?>
                                <?php echo htmlspecialchars($branch_website); ?>
                            <?php endif; ?>
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Stats Overview -->
        <div class="stats-overview">
            <div class="stat-card">
                <div class="stat-header">
                    <i class="fas fa-boxes"></i>
                    <h4>Warehouse Capacity</h4>
                </div>
                <div class="stat-content">
                    <div class="stat-value"><?php echo number_format($cap['warehouse_capacity']); ?></div>
                    <div class="stat-label">Total Capacity (bags)</div>
                </div>
                <div class="stat-trend">
                    <i class="fas fa-arrows-alt-h"></i>
                    <span>Maximum storage</span>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-header">
                    <i class="fas fa-pallet"></i>
                    <h4>Current Inventory</h4>
                </div>
                <div class="stat-content">
                    <div class="stat-value"><?php echo number_format($cap['inventory']); ?></div>
                    <div class="stat-label">Bags in Stock</div>
                </div>
                <div class="stat-trend">
                    <div class="progress-bar-small">
                        <div class="progress-fill" style="width: <?php echo $capacity_percentage; ?>%"></div>
                    </div>
                    <span><?php echo round($capacity_percentage, 1); ?>% full</span>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-header">
                    <i class="fas fa-truck-loading"></i>
                    <h4>Available Capacity</h4>
                </div>
                <div class="stat-content">
                    <div class="stat-value"><?php echo number_format($available_capacity); ?></div>
                    <div class="stat-label">Bags Available</div>
                </div>
                <div class="stat-trend positive">
                    <i class="fas fa-arrow-up"></i>
                    <span>Ready for delivery</span>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-header">
                    <i class="fas fa-calendar-day"></i>
                    <h4>Expected to Arrive</h4>
                </div>
                <div class="stat-content">
                    <div class="stat-value"><?php echo number_format($expected_to_arrive_bags); ?></div>
                    <div class="stat-label">
                        <span class="status-dot confirmed"></span> Bags to be delivered
                    </div>
                </div>
                <div class="stat-trend">
                    <a href="appointments.php?date=<?php echo urlencode(date('Y-m-d')); ?>&month=<?php echo (int)date('n'); ?>&year=<?php echo (int)date('Y'); ?>" class="view-details">
                        <i class="fas fa-external-link-alt"></i> View Today
                    </a>
                </div>
            </div>
        </div>

        <!-- Status KPIs (All time) -->
        <div class="stats-overview stats-overview-status">
            <div class="stat-card stat-card-compact status-total">
                <div class="stat-header">
                    <i class="fas fa-list-check"></i>
                    <h4>Total Appointments</h4>
                </div>
                <div class="stat-content">
                    <div class="stat-value"><?php echo (int)($overall_stats['total'] ?? 0); ?></div>
                </div>
            </div>

            <div class="stat-card stat-card-compact status-completed">
                <div class="stat-header">
                    <i class="fas fa-circle-check"></i>
                    <h4>Completed</h4>
                </div>
                <div class="stat-content">
                    <div class="stat-value"><?php echo (int)($overall_stats['completed'] ?? 0); ?></div>
                </div>
            </div>

            <div class="stat-card stat-card-compact status-confirmed">
                <div class="stat-header">
                    <i class="fas fa-circle-check"></i>
                    <h4>Confirmed</h4>
                </div>
                <div class="stat-content">
                    <div class="stat-value"><?php echo (int)($overall_stats['confirmed'] ?? 0); ?></div>
                </div>
            </div>

            <div class="stat-card stat-card-compact status-rescheduled">
                <div class="stat-header">
                    <i class="fas fa-clock-rotate-left"></i>
                    <h4>Rescheduled</h4>
                </div>
                <div class="stat-content">
                    <div class="stat-value"><?php echo (int)($overall_stats['rescheduled'] ?? 0); ?></div>
                </div>
            </div>

            <div class="stat-card stat-card-compact status-pending">
                <div class="stat-header">
                    <i class="fas fa-hourglass-half"></i>
                    <h4>Pending</h4>
                </div>
                <div class="stat-content">
                    <div class="stat-value"><?php echo (int)($overall_stats['pending'] ?? 0); ?></div>
                </div>
            </div>

            <div class="stat-card stat-card-compact status-cancelled">
                <div class="stat-header">
                    <i class="fas fa-ban"></i>
                    <h4>Cancelled</h4>
                </div>
                <div class="stat-content">
                    <div class="stat-value"><?php echo (int)($overall_stats['cancelled'] ?? 0); ?></div>
                </div>
            </div>
        </div>

        <!-- Main Dashboard Content -->
        <div class="dashboard-grid">
            <!-- Left Column: Charts -->
            <div class="dashboard-left">
                <!-- Capacity Visualization -->
                <div class="dashboard-card">
                    <div class="card-header">
                        <h3><i class="fas fa-chart-pie"></i> Warehouse Status</h3>
                        <div class="card-actions">
                            <button class="chart-toggle active" data-chart="pie">
                                <i class="fas fa-chart-pie"></i>
                            </button>
                            <button class="chart-toggle" data-chart="donut">
                                <i class="fas fa-donate"></i>
                            </button>
                            <button class="chart-toggle" data-chart="gauge">
                                <i class="fas fa-tachometer-alt"></i>
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="chart-container" id="capacity-chart-container">
                            <canvas id="capacityChart" height="250"></canvas>
                        </div>
                        <div class="chart-legend">
                            <div class="legend-item">
                                <span class="legend-color inventory"></span>
                                <span class="legend-label">Current Inventory</span>
                                <span class="legend-value"><?php echo number_format($cap['inventory']); ?> bags</span>
                            </div>
                            <div class="legend-item">
                                <span class="legend-color available"></span>
                                <span class="legend-label">Available Capacity</span>
                                <span class="legend-value"><?php echo number_format($available_capacity); ?> bags</span>
                            </div>
                            <div class="legend-item">
                                <span class="legend-color total"></span>
                                <span class="legend-label">Total Capacity</span>
                                <span class="legend-value"><?php echo number_format($cap['warehouse_capacity']); ?> bags</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Weekly Activity -->
                <div class="dashboard-card">
                    <div class="card-header">
                        <h3><i class="fas fa-chart-line"></i> Weekly Activity</h3>
                        <div class="period-selector">
                            <select id="periodSelect">
                                <option value="7">Last 7 Days</option>
                                <option value="14">Last 14 Days</option>
                                <option value="30">Last 30 Days</option>
                            </select>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="activityChart" height="200"></canvas>
                        </div>
                        <div class="chart-summary">
                            <div class="summary-item">
                                <span class="summary-label">Total Appointments</span>
                                <span class="summary-value" id="weeklyTotalAppointments"><?php echo array_sum($week_counts); ?></span>
                            </div>
                            <div class="summary-item">
                                <span class="summary-label">Total Volume</span>
                                <span class="summary-value" id="weeklyTotalVolume"><?php echo number_format(array_sum($week_volumes)); ?> bags</span>
                            </div>
                            <div class="summary-item">
                                <span class="summary-label">Avg. per Day</span>
                                <span class="summary-value" id="weeklyAvgPerDay"><?php echo round(array_sum($week_counts) / 7, 1); ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right Column: Appointments & Farmers -->
            <div class="dashboard-right">
                <!-- Today's Schedule -->
                <div class="dashboard-card">
                    <div class="card-header">
                        <h3><i class="fas fa-calendar-day"></i> Today's Schedule</h3>
                        <span class="date-badge"><?php echo date('F j, Y'); ?></span>
                    </div>
                    <div class="card-body">
                        <?php
                        $today_appointments_stmt = $pdo->prepare("SELECT a.appointment_id, a.first_name, a.last_name, a.time_slot, a.volume, a.status
                            FROM appointments a 
                            WHERE a.branch_id = ? AND a.date = ? AND a.status != 'cancelled'
                            ORDER BY FIELD(a.time_slot, 'AM', 'PM'), a.appointment_id");
                        $today_appointments_stmt->execute([$branch_id, $today]);
                        $today_appointments = $today_appointments_stmt->fetchAll(PDO::FETCH_ASSOC);
                        ?>
                        
                        <?php if (count($today_appointments) > 0): ?>
                            <div class="schedule-list">
                                <?php foreach ($today_appointments as $appt): ?>
                                    <div class="schedule-item">
                                        <div class="schedule-time">
                                            <div class="time-slot-badge <?php echo $appt['time_slot'] == 'AM' ? 'morning' : 'afternoon'; ?>">
                                                <?php echo $appt['time_slot'] == 'AM' ? 'AM' : 'PM'; ?>
                                            </div>
                                            <div class="time-display">
                                                <?php echo $appt['time_slot'] == 'AM' ? '8:00 AM' : '1:00 PM'; ?>
                                            </div>
                                        </div>
                                        <div class="schedule-details">
                                            <div class="farmer-name">
                                                <?php echo htmlspecialchars($appt['first_name'] . ' ' . $appt['last_name']); ?>
                                            </div>
                                            <div class="appointment-meta">
                                                <span class="volume-indicator">
                                                    <i class="fas fa-weight-hanging"></i> <?php echo number_format($appt['volume']); ?> bags
                                                </span>
                                                <span class="status-indicator <?php echo $appt['status']; ?>">
                                                    <?php echo ucfirst($appt['status']); ?>
                                                </span>
                                            </div>
                                        </div>
                                        <div class="schedule-actions">
                                            <button class="action-btn small" onclick="viewAppointment(<?php echo $appt['appointment_id']; ?>)">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <?php if ($appt['status'] == 'pending'): ?>
                                                <button class="action-btn small confirm" onclick="confirmAppointment(<?php echo $appt['appointment_id']; ?>)">
                                                    <i class="fas fa-check"></i>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-calendar-times"></i>
                                <p>No appointments scheduled for today</p>
                                <a href="appointments.php" class="btn-outline">
                                    <i class="fas fa-plus"></i> View All Appointments
                                </a>
                            </div>
                        <?php endif; ?>
                        
                        <div class="card-footer">
                            <a href="appointments.php" class="view-all-link">
                                <i class="fas fa-list"></i> View All Appointments
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="dashboard-card">
                    <div class="card-header">
                        <h3><i class="fas fa-bolt"></i> Quick Actions</h3>
                    </div>
                    <div class="card-body">
                        <div class="quick-actions-grid">
                            <a href="<?php echo htmlspecialchars($pendingQuickHref); ?>" class="quick-action pending" title="Go to earliest pending appointment">
                                <i class="fas fa-clock"></i>
                                <span>Pending Approvals</span>
                                <?php if ($new_count > 0): ?>
                                    <span class="badge"><?php echo $new_count; ?></span>
                                <?php endif; ?>
                            </a>
                            <a href="capacity_management.php" class="quick-action capacity">
                                <i class="fas fa-warehouse"></i>
                                <span>Update Capacity</span>
                            </a>
                            <a href="reports.php?type=daily" class="quick-action reports">
                                <i class="fas fa-file-alt"></i>
                                <span>Daily Report</span>
                            </a>
                            <a href="walk_in.php" class="quick-action add" id="walkInQuickAction" title="Open Walk-in Process">
                                <i class="fas fa-person-walking"></i>
                                <span>Walk-in Process</span>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- System Status -->
        <div class="system-status">
            <div class="status-card">
                <div class="status-header">
                    <i class="fas fa-server"></i>
                    <h4>System Status</h4>
                </div>
                <div class="status-list">
                    <div class="status-item online">
                        <i class="fas fa-circle"></i>
                        <span>Database Connection</span>
                        <span class="status-text">Online</span>
                    </div>
                    <div class="status-item online">
                        <i class="fas fa-circle"></i>
                        <span>PalayPortal</span>
                        <span class="status-text">Active</span>
                    </div>
                    <div class="status-item online">
                        <i class="fas fa-circle"></i>
                        <span>Capacity Management</span>
                        <span class="status-text">Ready</span>
                    </div>
                    <div class="status-item online">
                        <i class="fas fa-circle"></i>
                        <span>Report Generation</span>
                        <span class="status-text">Available</span>
                    </div>
                </div>
            </div>
            
            <div class="last-update">
                <p><i class="fas fa-sync-alt"></i> Last updated: <?php echo date('h:i:s A'); ?></p>
                <button class="refresh-btn" onclick="location.reload()">
                    <i class="fas fa-redo"></i> Refresh Dashboard
                </button>
            </div>
        </div>
    </div>

    <!-- JavaScript Libraries -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.2.0"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-gauge@0.3.0/dist/chartjs-gauge.min.js"></script>
    
    <!-- Global data for JS -->
    <script>
        window.userId = <?php echo json_encode((int)($user_id ?? 0)); ?>;
        window.branchId = <?php echo json_encode((int)$branch_id); ?>;
    </script>

    <!-- JavaScript -->
    <script src="js/loading_ui.js?v=<?php echo urlencode((string)@filemtime(__DIR__ . '/js/loading_ui.js')); ?>"></script>
    <script src="js/refresh_bus.js?v=<?php echo urlencode((string)@filemtime(__DIR__ . '/js/refresh_bus.js')); ?>"></script>
    <script src="js/processor.js?v=<?php echo urlencode((string)@filemtime(__DIR__ . '/js/processor.js')); ?>"></script>
    <script src="js/auto_refresh.js?v=<?php echo urlencode((string)@filemtime(__DIR__ . '/js/auto_refresh.js')); ?>"></script>
    <script>
        window.NFAAutoRefresh && window.NFAAutoRefresh.start({ scope: 'processor', intervalMs: 15000, idleMs: 8000 });
    </script>
</body>
</html>