<?php
session_start();
require_once 'php_helper/db_config.php';

// Prevent caching of protected pages (helps prevent back-button access after logout)
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
header('Expires: 0');

if (!isset($_SESSION['loggedin']) || ($_SESSION['user_type'] ?? '') !== 'Admin') {
    header('location: login.php');
    exit;
}

$user_id = (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? 0);

function col_exists(PDO $pdo, string $table, string $col): bool {
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE ?");
        $stmt->execute([$col]);
        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return false;
    }
}

$first_name = $_SESSION['username'] ?? 'Admin';
$last_name = '';
$user_email = '';
if ($user_id > 0) {
    $stmt = $pdo->prepare('SELECT first_name, last_name, email_address FROM users WHERE user_id = ? LIMIT 1');
    $stmt->execute([$user_id]);
    if ($u = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $first_name = (string)($u['first_name'] ?? $first_name);
        $last_name = (string)($u['last_name'] ?? '');
        $user_email = (string)($u['email_address'] ?? '');
    }
}

$initials = '';
if ($first_name !== '') $initials .= strtoupper(substr($first_name, 0, 1));
if ($last_name !== '') $initials .= strtoupper(substr($last_name, 0, 1));
if ($initials === '' && !empty($_SESSION['username'])) $initials = strtoupper(substr((string)$_SESSION['username'], 0, 1));

$hasNotifDeleted = col_exists($pdo, 'users', 'notif_deleted');
$hasNotifRead = col_exists($pdo, 'users', 'notif_is_read');

$notifSql = "SELECT user_id, first_name, last_name, username, user_type, status";
if (col_exists($pdo, 'users', 'created_at')) {
    $notifSql .= ", created_at";
}
if ($hasNotifRead) {
    $notifSql .= ", notif_is_read";
}
$notifSql .= " FROM users WHERE status = 'Pending'";
if ($hasNotifDeleted) {
    $notifSql .= " AND (notif_deleted IS NULL OR notif_deleted = 0)";
}
$notifSql .= " ORDER BY user_id DESC LIMIT 10";

$notifStmt = $pdo->prepare($notifSql);
$notifStmt->execute();
$notifications = $notifStmt->fetchAll(PDO::FETCH_ASSOC);

$new_count = 0;
foreach ($notifications as $n) {
    $isRead = $hasNotifRead ? (int)($n['notif_is_read'] ?? 0) : 0;
    if ($isRead === 0) $new_count++;
}

// Get activity statistics
$today = date('Y-m-d');
$yesterday = date('Y-m-d', strtotime('-1 day'));
$weekAgo = date('Y-m-d', strtotime('-7 days'));
$monthAgo = date('Y-m-d', strtotime('-30 days'));

$stats = [
    'today' => 0,
    'yesterday' => 0,
    'week' => 0,
    'month' => 0,
    'total' => 0,
];

try {
    $stmt = $pdo->prepare("SELECT COUNT(*)
                           FROM activity_logs l
                           JOIN users u ON l.user_id = u.user_id
                           WHERE u.user_type = 'Processor' AND DATE(l.timestamp) = ?");
    $stmt->execute([$today]);
    $stats['today'] = (int)$stmt->fetchColumn();

    $stmt->execute([$yesterday]);
    $stats['yesterday'] = (int)$stmt->fetchColumn();

    $stmt2 = $pdo->prepare("SELECT COUNT(*)
                            FROM activity_logs l
                            JOIN users u ON l.user_id = u.user_id
                            WHERE u.user_type = 'Processor' AND l.timestamp >= ?");
    $stmt2->execute([$weekAgo . ' 00:00:00']);
    $stats['week'] = (int)$stmt2->fetchColumn();

    $stmt2->execute([$monthAgo . ' 00:00:00']);
    $stats['month'] = (int)$stmt2->fetchColumn();

    $stmt3 = $pdo->query("SELECT COUNT(*)
                          FROM activity_logs l
                          JOIN users u ON l.user_id = u.user_id
                          WHERE u.user_type = 'Processor'");
    $stats['total'] = (int)$stmt3->fetchColumn();
} catch (PDOException $e) {
    // Best-effort stats; page can still function without them
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>NFA Admin - Activity Logs</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="img/nfa-logo.png" type="image/png"/>
    <link rel="stylesheet" href="css/style.css?v=<?php echo urlencode((string)@filemtime(__DIR__ . '/css/style.css')); ?>">
    <link rel="stylesheet" href="css/processor.css?v=<?php echo urlencode((string)@filemtime(__DIR__ . '/css/processor.css')); ?>">
    <link rel="stylesheet" href="css/admin.css?v=<?php echo urlencode((string)@filemtime(__DIR__ . '/css/admin.css')); ?>">
    <link rel="stylesheet" href="css/reports.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="dashboard admin-activity-logs-page">
    <!-- Skip to main content for accessibility -->
    <a href="#main-content" class="skip-to-content">Skip to main content</a>
    
    <nav class="top-nav" role="navigation" aria-label="Main navigation">
        <div class="logo">
            <img src="img/nfa-logo.png" alt="National Food Authority" class="nfa-logo">
            <div class="logo-text">
                <h1 class="nfa-title">National Food Authority</h1>
                <p class="nfa-subtitle">Activity Monitoring</p>
            </div>
        </div>

        <div class="nav-center">
            <div class="nav-links">
                <a href="admin_dashboard.php" class="nav-link">
                    <i class="fas fa-shield-alt"></i>
                    <span>Dashboard</span>
                </a>
                <a href="admin_accounts.php" class="nav-link">
                    <i class="fas fa-user-check"></i>
                    <span>Accounts</span>
                </a>
                <a href="admin_activity_logs.php" class="nav-link active" aria-current="page">
                    <i class="fas fa-clipboard-list"></i>
                    <span>Activity Log</span>
                </a>
                <a href="profile.php" class="nav-link">
                    <i class="fas fa-user-cog"></i>
                    <span>Profile</span>
                </a>
            </div>
        </div>

        <div class="user-actions">
            <div class="notif-wrapper" id="notifWrapper">
                <div class="notif-icon" tabindex="0" role="button" aria-label="Notifications" aria-expanded="false" aria-controls="notifDropdown">
                    <i class="fas fa-bell"></i>
                    <?php if ($new_count > 0): ?>
                        <span class="notif-badge pulse" aria-label="<?php echo $new_count; ?> unread notifications">
                            <?php echo (int)$new_count; ?>
                        </span>
                    <?php endif; ?>
                </div>
                <div class="notif-dropdown" id="notifDropdown" role="region" aria-label="Notifications dropdown">
                    <div class="notif-header">
                        <h4><i class="fas fa-bell"></i> Notifications</h4>
                        <?php if ($new_count > 0): ?>
                            <button class="mark-all-read" id="markAllRead" aria-label="Mark all notifications as read">
                                <i class="fas fa-check-double"></i> Mark All Read
                            </button>
                        <?php endif; ?>
                    </div>
                    <div class="notif-list" id="notifList" role="list">
                        <?php if (count($notifications) > 0): ?>
                            <?php foreach ($notifications as $n):
                                $unread = $hasNotifRead ? ((int)($n['notif_is_read'] ?? 0) === 0) : true;
                                $full = trim(($n['first_name'] ?? '') . ' ' . ($n['last_name'] ?? ''));
                                if ($full === '') $full = (string)($n['username'] ?? 'User');
                                $timeAgo = '';
                                if (isset($n['created_at'])) {
                                    $created = new DateTime($n['created_at']);
                                    $now = new DateTime();
                                    $interval = $created->diff($now);
                                    if ($interval->d > 0) {
                                        $timeAgo = $interval->d . 'd ago';
                                    } elseif ($interval->h > 0) {
                                        $timeAgo = $interval->h . 'h ago';
                                    } elseif ($interval->i > 0) {
                                        $timeAgo = $interval->i . 'm ago';
                                    } else {
                                        $timeAgo = 'Just now';
                                    }
                                }
                            ?>
                                <a class="notif-item <?php echo $unread ? 'unread' : ''; ?>"
                                   href="admin_accounts.php?view=<?php echo (int)$n['user_id']; ?>"
                                   data-user-id="<?php echo (int)$n['user_id']; ?>"
                                   data-is-read="<?php echo $unread ? '0' : '1'; ?>"
                                   role="listitem"
                                   tabindex="0"
                                   aria-label="Notification: New account request from <?php echo htmlspecialchars($full); ?>">
                                    <div class="notif-icon-small">
                                        <i class="fas fa-user-plus status-pending" aria-hidden="true"></i>
                                    </div>
                                    <div class="notif-content">
                                        <div class="notif-title">
                                            New Account Request
                                            <span class="notif-time"><?php echo htmlspecialchars($timeAgo); ?></span>
                                        </div>
                                        <div class="notif-details">
                                            <strong><?php echo htmlspecialchars($full); ?></strong>
                                            <span class="notif-status status-pending" aria-label="Status: Pending">Pending</span>
                                        </div>
                                    </div>
                                    <div class="notif-actions">
                                        <label class="notif-check" title="Mark as <?php echo $unread ? 'Read' : 'Unread'; ?>">
                                            <input class="notif-checkbox" type="checkbox" <?php echo $unread ? '' : 'checked'; ?> 
                                                   aria-label="Toggle read status for notification from <?php echo htmlspecialchars($full); ?>">
                                            <span class="notif-check-ui" aria-hidden="true"></span>
                                        </label>
                                        <span class="notif-delete" role="button" tabindex="0" 
                                              title="Delete notification" aria-label="Delete notification from <?php echo htmlspecialchars($full); ?>">
                                            <i class="fas fa-trash" aria-hidden="true"></i>
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
                        <a href="admin_accounts.php" class="view-all">
                            Go to Accounts <i class="fas fa-arrow-right"></i>
                        </a>
                    </div>
                </div>
            </div>

            <div class="user-profile" tabindex="0" role="button" aria-label="User menu" aria-expanded="false" aria-controls="userDropdown">
                <div class="user-avatar" aria-hidden="true"><?php echo htmlspecialchars($initials); ?></div>
                <div class="user-info">
                    <span class="user-name"><?php echo htmlspecialchars(trim($first_name . ' ' . $last_name)); ?></span>
                    <span class="user-role">Admin</span>
                </div>
                <div class="user-dropdown" id="userDropdown" role="region" aria-label="User dropdown menu">
                    <div class="user-dropdown-header">
                        <div class="user-dropdown-avatar" aria-hidden="true"><?php echo htmlspecialchars($initials); ?></div>
                        <div class="user-dropdown-info">
                            <strong><?php echo htmlspecialchars(trim($first_name . ' ' . $last_name)); ?></strong>
                            <small><?php echo htmlspecialchars($user_email); ?></small>
                            <div class="user-role-badge" aria-label="Admin role">Admin</div>
                        </div>
                    </div>
                    <div class="user-dropdown-menu" role="menu">
                        <a href="profile.php" class="dropdown-item" role="menuitem">
                            <i class="fas fa-user-cog"></i>
                            <span class="dropdown-item-content">
                                <span class="dropdown-item-title">My Profile</span>
                                <span class="dropdown-item-desc">View and update your account</span>
                            </span>
                            <i class="fas fa-chevron-right dropdown-item-arrow" aria-hidden="true"></i>
                        </a>
                        <a href="settings.php" class="dropdown-item" role="menuitem">
                            <i class="fas fa-cog"></i>
                            <span class="dropdown-item-content">
                                <span class="dropdown-item-title">Settings</span>
                                <span class="dropdown-item-desc">Preferences and appearance</span>
                            </span>
                            <i class="fas fa-chevron-right dropdown-item-arrow" aria-hidden="true"></i>
                        </a>
                        <div class="dropdown-divider" role="separator"></div>
                        <a href="logout.php" class="dropdown-item logout" role="menuitem">
                            <i class="fas fa-sign-out-alt"></i> Logout
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </nav>

    <main id="main-content" class="container-fluid" role="main">
        <section class="table-panel">
            <div class="panel-head">
                <div>
                    <h2><i class="fas fa-clipboard-list"></i> Processor Activity Log</h2>
                    <div class="hint"><i class="fas fa-info-circle"></i> Monitor and track processor activities (Farmer activity excluded)</div>
                </div>
            </div>

            <div class="admin-stats-row" aria-label="Activity statistics">
                <span class="stats-badge total" title="Today"><i class="fas fa-calendar-day"></i> Today: <?php echo (int)$stats['today']; ?></span>
                <span class="stats-badge total" title="Yesterday"><i class="fas fa-calendar"></i> Yesterday: <?php echo (int)$stats['yesterday']; ?></span>
                <span class="stats-badge total" title="Last 7 Days"><i class="fas fa-calendar-week"></i> 7d: <?php echo (int)$stats['week']; ?></span>
                <span class="stats-badge total" title="Last 30 Days"><i class="fas fa-calendar-alt"></i> 30d: <?php echo (int)$stats['month']; ?></span>
                <span class="stats-badge total" title="All Time"><i class="fas fa-layer-group"></i> Total: <?php echo (int)$stats['total']; ?></span>
            </div>

            <div class="admin-tabs" role="tablist" aria-label="Activity log tabs">
                <button class="admin-tab active" id="tabSystem" type="button" role="tab" aria-selected="true" aria-controls="panelSystem">
                    <i class="fas fa-clipboard-check"></i> System Activity
                </button>
                <button class="admin-tab" id="tabLoginErrors" type="button" role="tab" aria-selected="false" aria-controls="panelLoginErrors">
                    <i class="fas fa-user-shield"></i> Login Errors
                </button>
            </div>

            <div class="admin-filters">
                <div class="field">
                    <label for="logQ">
                        <i class="fas fa-search"></i> Search Activities
                    </label>
                    <input id="logQ" class="form-control" type="text" 
                           placeholder="Search by user, action, or details..."
                           aria-label="Search activity logs">
                </div>
                <div class="field">
                    <label for="logRegion">
                        <i class="fas fa-globe-asia"></i> Region
                    </label>
                    <select id="logRegion" class="form-control" aria-label="Filter by region">
                        <option value="">All Regions</option>
                    </select>
                </div>
                <div class="field">
                    <label for="logBranch">
                        <i class="fas fa-building"></i> Branch
                    </label>
                    <select id="logBranch" class="form-control" aria-label="Filter by branch" disabled>
                        <option value="">Select Region First</option>
                    </select>
                </div>
                <div class="field">
                    <label for="logFrom">
                        <i class="fas fa-calendar-alt"></i> From Date
                    </label>
                    <input id="logFrom" class="form-control" type="date" 
                           aria-label="Filter from date">
                </div>
                <div class="field">
                    <label for="logTo">
                        <i class="fas fa-calendar-alt"></i> To Date
                    </label>
                    <input id="logTo" class="form-control" type="date" 
                           aria-label="Filter to date">
                </div>
                <div class="field actions">
                    <button class="btn-outline" id="btnLogRefresh" type="button" aria-label="Refresh activity logs">
                        <i class="fas fa-rotate"></i> Refresh
                    </button>
                    <button class="btn-outline" id="btnLogReset" type="button" aria-label="Reset filters">
                        <i class="fas fa-undo"></i> Reset
                    </button>
                    <button class="btn-view-details btn-inline-success" id="btnPrintLogs" type="button" aria-label="Print activity logs">
                        <i class="fas fa-print"></i> Print
                    </button>
                </div>
            </div>

            <div id="logsNotice" class="admin-notice" aria-live="polite"></div>

            <div id="panelSystem" class="tab-panel" role="tabpanel" aria-labelledby="tabSystem">
                <div class="table-wrap">
                    <table class="report-table" aria-label="Activity logs table">
                        <thead>
                            <tr>
                                <th scope="col" style="width: 210px;" class="sortable" data-sort="timestamp">Timestamp</th>
                                <th scope="col" style="width: 240px;" class="sortable" data-sort="processor">Processor</th>
                                <th scope="col" style="width: 260px;" class="sortable" data-sort="location">Region / Branch</th>
                                <th scope="col" class="sortable" data-sort="action">Action</th>
                                <th scope="col" style="width: 140px;" data-sort="type">Type</th>
                                <th scope="col" style="width: 110px;">View</th>
                            </tr>
                        </thead>
                        <tbody id="logsTbody" role="rowgroup">
                            <!-- Logs will be loaded here -->
                        </tbody>
                    </table>
                </div>

                <div id="logsPagination"></div>
            </div>

            <div id="panelLoginErrors" class="tab-panel" role="tabpanel" aria-labelledby="tabLoginErrors" style="display:none;">
                <div class="table-wrap">
                    <table class="report-table" aria-label="Login error attempts table">
                        <thead>
                            <tr>
                                <th scope="col" style="width: 210px;" class="sortable" data-sort="attempt_ts">Timestamp</th>
                                <th scope="col" style="width: 240px;" class="sortable" data-sort="attempt_user">Attempted Username</th>
                                <th scope="col" style="width: 260px;" class="sortable" data-sort="attempt_match">Matched Account</th>
                                <th scope="col" class="sortable" data-sort="attempt_reason">Reason</th>
                                <th scope="col" style="width: 180px;" class="sortable" data-sort="attempt_ip">IP Address</th>
                                <th scope="col" style="width: 110px;">View</th>
                            </tr>
                        </thead>
                        <tbody id="loginAttemptsTbody" role="rowgroup">
                            <!-- Attempts will be loaded here -->
                        </tbody>
                    </table>
                </div>

                <div id="loginAttemptsPagination"></div>
            </div>
        </section>
    </main>

    <div id="logModalBackdrop" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="logModalTitle" style="display:none;">
        <div class="modal-dialog" style="max-width: 820px;">
            <button class="modal-close" id="logModalClose" aria-label="Close modal">
                <i class="fas fa-times"></i>
            </button>
            <div class="modal-header">
                <h2 id="logModalTitle">Activity Details</h2>
                <p id="logModalSub" class="modal-ref"></p>
            </div>
            <div class="modal-body" id="logModalBody" role="document"></div>
            <div class="modal-footer-actions">
                <button class="btn-outline" id="logModalOk" type="button" aria-label="Close modal">
                    <i class="fas fa-times"></i> Close
                </button>
            </div>
        </div>
    </div>

    <div id="attemptModalBackdrop" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="attemptModalTitle" style="display:none;">
        <div class="modal-dialog" style="max-width: 820px;">
            <button class="modal-close" id="attemptModalClose" aria-label="Close modal">
                <i class="fas fa-times"></i>
            </button>
            <div class="modal-header">
                <h2 id="attemptModalTitle">Login Attempt Details</h2>
                <p id="attemptModalSub" class="modal-ref"></p>
            </div>
            <div class="modal-body" id="attemptModalBody" role="document"></div>
            <div class="modal-footer-actions">
                <button class="btn-outline" id="attemptModalOk" type="button" aria-label="Close modal">
                    <i class="fas fa-times"></i> Close
                </button>
            </div>
        </div>
    </div>

    <script src="js/admin.js?v=<?php echo urlencode((string)@filemtime(__DIR__ . '/js/admin.js')); ?>"></script>
    <script src="js/admin_activity_logs.js?v=<?php echo urlencode((string)@filemtime(__DIR__ . '/js/admin_activity_logs.js')); ?>"></script>
</body>
</html>