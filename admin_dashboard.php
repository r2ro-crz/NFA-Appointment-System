<?php
session_start();
require_once 'php_helper/db_config.php';
require_once __DIR__ . '/php_helper/branding.php';

// Prevent caching of protected pages (helps prevent back-button dashboard access after logout)
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

// Admin user info
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

// Notifications: pending accounts
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

// KPI tiles
$pendingCount = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE status = 'Pending'")->fetchColumn();

$pendingToday = 0;
try {
    if (col_exists($pdo, 'users', 'created_at')) {
        $today = date('Y-m-d');
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE status = 'Pending' AND DATE(created_at) = ?");
        $stmt->execute([$today]);
        $pendingToday = (int)$stmt->fetchColumn();
    }
} catch (PDOException $e) {
    $pendingToday = 0;
}

$hasIsActive = col_exists($pdo, 'users', 'is_active');
$activeProcessorsSql = "SELECT COUNT(*) FROM users WHERE user_type = 'Processor' AND status = 'Approved'";
if ($hasIsActive) {
    $activeProcessorsSql .= " AND COALESCE(is_active, 1) = 1";
}
$activeProcessors = (int)$pdo->query($activeProcessorsSql)->fetchColumn();

// Recent activity (Processor only) — guard optional columns for schema compatibility
$recentLogs = [];
try {
    $hasLogDetails = col_exists($pdo, 'activity_logs', 'details');
    $select = "SELECT l.timestamp, l.action" . ($hasLogDetails ? ", l.details" : "") . ", u.username, u.first_name, u.last_name";
    $stmt = $pdo->prepare($select . "
        FROM activity_logs l
        JOIN users u ON l.user_id = u.user_id
        WHERE u.user_type = 'Processor'
        ORDER BY l.timestamp DESC, l.log_id DESC
        LIMIT 10");
    $stmt->execute();
    $recentLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $recentLogs = [];
}

// Get today's activity count (Processor only)
$todayActivity = 0;
try {
    $today = date('Y-m-d');
    $stmt = $pdo->prepare("SELECT COUNT(*)
        FROM activity_logs l
        JOIN users u ON l.user_id = u.user_id
        WHERE u.user_type = 'Processor' AND DATE(l.timestamp) = ?");
    $stmt->execute([$today]);
    $todayActivity = (int)$stmt->fetchColumn();
} catch (PDOException $e) {
    $todayActivity = 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?php echo htmlspecialchars(nfa_page_title('Admin Dashboard'), ENT_QUOTES, 'UTF-8'); ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="<?php echo htmlspecialchars(NFA_FAVICON, ENT_QUOTES, 'UTF-8'); ?>" type="image/png"/>
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/processor.css">
    <link rel="stylesheet" href="css/admin.css">
    <link rel="stylesheet" href="css/reports.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Skip to main content link (accessibility) */
        .skip-to-content {
            position: absolute;
            top: -40px;
            left: 0;
            background: var(--nfa-green);
            color: white;
            padding: 0.75rem 1.5rem;
            text-decoration: none;
            font-weight: 600;
            border-radius: 0 0 var(--radius-lg) var(--radius-lg);
            z-index: 1100;
            transition: top 0.2s ease;
        }
        .skip-to-content:focus { top: 0; }
    </style>
</head>
<body class="dashboard">
    <!-- Skip to main content for accessibility -->
    <a href="#main-content" class="skip-to-content">Skip to main content</a>

    <nav class="top-nav" role="navigation" aria-label="Main navigation">
        <div class="logo">
            <div class="brand-logos">
                <img src="<?php echo htmlspecialchars(NFA_SYSTEM_LOGO, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars(NFA_SYSTEM_NAME, ENT_QUOTES, 'UTF-8'); ?>" class="system-logo">
            </div>
            <div class="logo-text">
                <h1 class="nfa-title"><?php echo htmlspecialchars(NFA_BRAND_NAME, ENT_QUOTES, 'UTF-8'); ?></h1>
                <p class="nfa-subtitle"><span class="page-subtitle">Admin Dashboard</span></p>
            </div>
        </div>

        <div class="nav-center">
            <div class="nav-links">
                <a href="admin_dashboard.php" class="nav-link active" aria-current="page">
                    <i class="fas fa-shield-alt"></i>
                    <span>Dashboard</span>
                </a>
                <a href="admin_accounts.php" class="nav-link">
                    <i class="fas fa-user-check"></i>
                    <span>Accounts</span>
                </a>
                <a href="admin_activity_logs.php" class="nav-link">
                    <i class="fas fa-clipboard-list"></i>
                    <span>Activity Log</span>
                </a>
                <a href="admin_capacity_overview.php" class="nav-link">
                    <i class="fas fa-warehouse"></i>
                    <span>Capacity</span>
                </a>
                <a href="admin_master_data.php" class="nav-link">
                    <i class="fas fa-database"></i>
                    <span>Master Data</span>
                </a>
                <a href="admin_analytics.php" class="nav-link">
                    <i class="fas fa-chart-pie"></i>
                    <span>Analytics</span>
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
                        <a href="support_inbox.php" class="dropdown-item" role="menuitem">
                            <i class="fas fa-headset"></i>
                            <span class="dropdown-item-content">
                                <span class="dropdown-item-title">Support Inbox</span>
                                <span class="dropdown-item-desc">Processor support requests</span>
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
        <div class="welcome-header" style="margin-top: 1.25rem;">
            <div class="welcome-text">
                <h1>Welcome back, <?php echo htmlspecialchars($first_name); ?>!</h1>
                <p>Manage staff accounts and monitor processor activity.</p>
            </div>
            <div class="branch-info">
                <div class="branch-card">
                    <div class="branch-icon">
                        <i class="fas fa-shield-alt"></i>
                    </div>
                    <div class="branch-details">
                        <div class="branch-title">
                            <h3>Admin Center</h3>
                            <div class="branch-subtitle"><?php echo (int)$todayActivity; ?> activities today</div>
                        </div>
                        <p><i class="fas fa-clock"></i> Updated: <?php echo htmlspecialchars(date('M d, Y h:i A')); ?></p>
                        <p><i class="fas fa-user-clock"></i> Pending requests: <?php echo (int)$pendingCount; ?><?php if ((int)$pendingToday > 0): ?> <span class="branch-pill">+<?php echo (int)$pendingToday; ?> today</span><?php endif; ?></p>
                        <p><i class="fas fa-users"></i> Active processors: <?php echo (int)$activeProcessors; ?></p>
                    </div>
                </div>
            </div>
        </div>

        <div class="stats-overview" style="margin-top: 1.25rem;">
            <div class="stat-card">
                <div class="stat-header">
                    <i class="fas fa-user-clock"></i>
                    <h4>Pending Requests</h4>
                </div>
                <div class="stat-content">
                    <div class="stat-value"><?php echo (int)$pendingCount; ?></div>
                    <div class="stat-label">Accounts awaiting approval</div>
                </div>
                <div class="stat-trend">
                    <a class="view-details" href="admin_accounts.php?status=Pending"><i class="fas fa-arrow-right"></i> Review</a>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-header">
                    <i class="fas fa-users"></i>
                    <h4>Active Processors</h4>
                </div>
                <div class="stat-content">
                    <div class="stat-value"><?php echo (int)$activeProcessors; ?></div>
                    <div class="stat-label">Approved and active</div>
                </div>
                <div class="stat-trend">
                    <a class="view-details" href="admin_accounts.php?status=Approved"><i class="fas fa-list"></i> View approved</a>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-header">
                    <i class="fas fa-chart-line"></i>
                    <h4>Activities Today</h4>
                </div>
                <div class="stat-content">
                    <div class="stat-value"><?php echo (int)$todayActivity; ?></div>
                    <div class="stat-label">Processor actions logged today</div>
                </div>
                <div class="stat-trend">
                    <a class="view-details" href="admin_activity_logs.php"><i class="fas fa-arrow-right"></i> View logs</a>
                </div>
            </div>
        </div>

        <section class="table-panel" style="margin-top: 1.25rem;">
            <div class="panel-head" style="display:flex; align-items:center; justify-content:space-between; gap: 1rem;">
                <h2><i class="fas fa-clipboard-list"></i> Recent Processor Activity</h2>
                <div class="admin-table-actions">
                    <label class="admin-table-search" aria-label="Search recent processor activity">
                        <i class="fas fa-magnifying-glass" aria-hidden="true"></i>
                        <input id="recentActivitySearch" type="text" placeholder="Search… (processor, action, type)" autocomplete="off">
                    </label>
                    <button class="btn-outline" type="button" onclick="window.location.reload()"><i class="fas fa-rotate"></i> Refresh</button>
                    <a class="btn-outline" href="admin_activity_logs.php"><i class="fas fa-arrow-right"></i> View all</a>
                </div>
            </div>
            <div class="table-wrap">
                <table class="report-table" id="recentActivityTable" aria-label="Processor activity log">
                    <thead>
                        <tr>
                            <th style="width: 200px;">Timestamp</th>
                            <th style="width: 220px;">Processor</th>
                            <th>Action</th>
                            <th style="width: 120px;">Type</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($recentLogs) > 0): ?>
                            <?php foreach ($recentLogs as $r):
                                $who = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
                                if ($who === '') $who = (string)($r['username'] ?? '');
                                $action = (string)($r['action'] ?? '');
                                $details = (string)($r['details'] ?? '');

                                $typeLabel = 'Info';
                                $typeClass = 'status-info';
                                if (stripos($action, 'created') !== false || stripos($action, 'added') !== false) {
                                    $typeLabel = 'Create';
                                    $typeClass = 'status-success';
                                } elseif (stripos($action, 'updated') !== false || stripos($action, 'modified') !== false) {
                                    $typeLabel = 'Update';
                                    $typeClass = 'status-warning';
                                } elseif (stripos($action, 'deleted') !== false || stripos($action, 'removed') !== false) {
                                    $typeLabel = 'Delete';
                                    $typeClass = 'status-danger';
                                }
                            ?>
                                <tr>
                                    <td><?php echo htmlspecialchars((string)($r['timestamp'] ?? '')); ?></td>
                                    <td><?php echo htmlspecialchars($who); ?></td>
                                    <td>
                                        <?php echo htmlspecialchars($action); ?>
                                        <?php if ($details !== ''): ?>
                                            <div class="muted" style="margin-top: .25rem;">
                                                <?php echo htmlspecialchars($details); ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="account-chip <?php echo htmlspecialchars($typeClass); ?>"><?php echo htmlspecialchars($typeLabel); ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                            <tr class="recent-activity-no-results" style="display:none;"><td colspan="4" class="empty">No matching results.</td></tr>
                        <?php else: ?>
                            <tr><td colspan="4" class="empty">No processor activity logged yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>

    <script src="js/admin.js"></script>
    <script src="js/auto_refresh.js?v=<?php echo urlencode((string)@filemtime(__DIR__ . '/js/auto_refresh.js')); ?>"></script>
    <script>
        window.NFAAutoRefresh && window.NFAAutoRefresh.start({ scope: 'admin', intervalMs: 20000, idleMs: 8000 });
    </script>
</body>
</html>