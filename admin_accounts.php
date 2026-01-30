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

$defaultStatus = isset($_GET['status']) ? trim((string)$_GET['status']) : 'All';
$allowedStatuses = ['Pending', 'Approved', 'Rejected', 'All'];
if (!in_array($defaultStatus, $allowedStatuses, true)) {
    $defaultStatus = 'All';
}

// Get statistics for the header
$hasIsActive = col_exists($pdo, 'users', 'is_active');
$stats = [
    'pending' => (int)$pdo->query("SELECT COUNT(*) FROM users WHERE user_type = 'Processor' AND status = 'Pending'")->fetchColumn(),
    // Approved = active approved (deactivated are tracked via is_active)
    'approved' => (int)$pdo->query(
        "SELECT COUNT(*) FROM users WHERE user_type = 'Processor' AND status = 'Approved'" .
        ($hasIsActive ? " AND COALESCE(is_active, 1) = 1" : "")
    )->fetchColumn(),
    'rejected' => (int)$pdo->query("SELECT COUNT(*) FROM users WHERE user_type = 'Processor' AND status = 'Rejected'")->fetchColumn(),
    'total' => (int)$pdo->query("SELECT COUNT(*) FROM users WHERE user_type = 'Processor'")->fetchColumn()
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>NFA Admin - Account Management</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="img/nfa-logo.png" type="image/png"/>
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/processor.css">
    <link rel="stylesheet" href="css/admin.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="dashboard admin-accounts-page">
    <!-- Skip to main content for accessibility -->
    <a href="#main-content" class="skip-to-content">Skip to main content</a>
    
    <nav class="top-nav" role="navigation" aria-label="Main navigation">
        <div class="logo">
            <img src="img/nfa-logo.png" alt="National Food Authority" class="nfa-logo">
            <div class="logo-text">
                <h1 class="nfa-title">National Food Authority</h1>
                <p class="nfa-subtitle">Account Management</p>
            </div>
        </div>

        <div class="nav-center">
            <div class="nav-links">
                <a href="admin_dashboard.php" class="nav-link">
                    <i class="fas fa-shield-alt"></i>
                    <span>Dashboard</span>
                </a>
                <a href="admin_accounts.php" class="nav-link active" aria-current="page">
                    <i class="fas fa-user-check"></i>
                    <span>Accounts</span>
                </a>
                <a href="admin_activity_logs.php" class="nav-link">
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
                            View all accounts <i class="fas fa-arrow-right"></i>
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
        <section class="appointments-by-date">
            <header class="appointments-header">
                <div>
                    <h1>Account Management</h1>
                    <p>Approve/reject accounts, manage user status, and reassign processor region/branch assignments.</p>
                    <div class="admin-stats-row" aria-label="Account statistics">
                        <span class="stats-badge pending" title="Pending Approval" aria-label="Pending accounts">
                            <i class="fas fa-clock"></i> Pending: <?php echo $stats['pending']; ?>
                        </span>
                        <span class="stats-badge approved" title="Approved Accounts" aria-label="Approved accounts">
                            <i class="fas fa-check-circle"></i> Approved: <?php echo $stats['approved']; ?>
                        </span>
                        <span class="stats-badge rejected" title="Rejected Accounts" aria-label="Rejected accounts">
                            <i class="fas fa-times-circle"></i> Rejected: <?php echo $stats['rejected']; ?>
                        </span>
                        <span class="stats-badge total" title="Total Accounts" aria-label="Total accounts">
                            <i class="fas fa-users"></i> Total: <?php echo $stats['total']; ?>
                        </span>
                    </div>
                </div>
                <div class="appointments-actions">
                    <button class="btn-outline" id="btnRefresh" type="button" aria-label="Refresh account list">
                        <i class="fas fa-rotate"></i> Refresh
                    </button>
                </div>
            </header>

            <div class="admin-filters">
                <div class="field">
                    <label for="filterStatus">
                        <i class="fas fa-filter"></i> Status
                    </label>
                    <select id="filterStatus" class="form-control" aria-label="Filter by account status">
                        <option value="Pending">Pending Approval</option>
                        <option value="Approved">Approved Accounts</option>
                        <option value="Rejected">Rejected Accounts</option>
                        <option value="All">All Accounts</option>
                    </select>
                </div>
                <div class="field">
                    <label for="filterQ">
                        <i class="fas fa-search"></i> Search Accounts
                    </label>
                    <input id="filterQ" class="form-control" type="text" 
                              placeholder="Search by name (first/middle/last)"
                           aria-label="Search accounts">
                </div>
                <div class="field">
                    <label for="filterInactive">
                        <input id="filterInactive" type="checkbox" aria-label="Include deactivated accounts">
                        <span>Show Deactivated Accounts</span>
                    </label>
                </div>
                <div class="field">
                    <button class="btn-outline" id="btnResetFilters" type="button" aria-label="Reset all filters">
                        <i class="fas fa-undo"></i> Reset Filters
                    </button>
                </div>
            </div>

            <div id="accountsNotice" class="notice" aria-live="polite"></div>

            <div class="appointments-tiles" id="accountsTiles" role="list" aria-label="Account list">
                <!-- Accounts will be loaded here -->
            </div>
        </section>
    </main>

    <!-- Account Details Modal -->
    <div id="accountModalBackdrop" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="accountModalTitle" aria-describedby="accountModalSub" style="display:none;">
        <div class="modal-dialog">
            <button class="modal-close" id="accountModalClose" aria-label="Close modal">
                <i class="fas fa-times"></i>
            </button>
            <div class="modal-header">
                <h2 id="accountModalTitle">Account Details</h2>
                <p id="accountModalSub" class="modal-ref"></p>
            </div>
            <div class="modal-body" id="accountDetailsKv" role="document">
                <!-- Details will be loaded here -->
            </div>
        </div>
    </div>

    <!-- Reassign Modal -->
    <div id="reassignModalBackdrop" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="reassignModalTitle" style="display:none;">
        <div class="modal-dialog" style="max-width: 720px;">
            <button class="modal-close" id="reassignModalClose" aria-label="Close reassign modal">
                <i class="fas fa-times"></i>
            </button>
            <div class="modal-header">
                <h2 id="reassignModalTitle">Reassign Region & Branch</h2>
                <p class="modal-ref">Only processors can be assigned to a region/branch. Admin accounts will be cleared.</p>
            </div>
            <div class="modal-body" style="grid-template-columns: 1fr 1fr;">
                <!-- Current assignment info will be inserted here -->
                <div class="modal-section">
                    <h3><i class="fas fa-globe-asia"></i> Region</h3>
                    <select id="reassignRegion" class="form-control" aria-label="Select region for reassignment">
                        <option value="">Select Region</option>
                    </select>
                    <p style="margin-top: 0.5rem; font-size: 0.875rem; color: var(--medium-gray);">
                        <i class="fas fa-info-circle"></i> Select the region where this processor will be assigned.
                    </p>
                </div>
                <div class="modal-section">
                    <h3><i class="fas fa-building"></i> Branch</h3>
                    <select id="reassignBranch" class="form-control" aria-label="Select branch for reassignment" disabled>
                        <option value="">Select Region First</option>
                    </select>
                    <p style="margin-top: 0.5rem; font-size: 0.875rem; color: var(--medium-gray);">
                        <i class="fas fa-info-circle"></i> Branches will load after selecting a region.
                    </p>
                </div>
            </div>
            <div class="modal-footer-actions">
                <button class="btn-outline" id="reassignCancel" type="button" aria-label="Cancel reassignment">
                    <i class="fas fa-times"></i> Cancel
                </button>
                <button class="btn-view-details btn-inline-primary" id="reassignSubmit" type="button" aria-label="Save reassignment">
                    <i class="fas fa-save"></i> Save Assignment
                </button>
            </div>
        </div>
    </div>

    <!-- Confirm Action Modal -->
    <div id="confirmModalBackdrop" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="confirmModalTitle" style="display:none;">
        <div class="modal-dialog" style="max-width: 560px;">
            <button class="modal-close" id="confirmModalClose" aria-label="Close confirmation">
                <i class="fas fa-times"></i>
            </button>
            <div class="modal-header">
                <h2 id="confirmModalTitle">Confirm</h2>
                <p id="confirmModalMsg" class="modal-ref"></p>
            </div>
            <div class="modal-footer-actions">
                <button class="btn-outline" id="confirmModalCancel" type="button">
                    <i class="fas fa-times"></i> Cancel
                </button>
                <button class="btn-view-details btn-inline-success" id="confirmModalOk" type="button">
                    Confirm
                </button>
            </div>
        </div>
    </div>

    <script>
        // Set default status from URL parameter
        window.NFA_ADMIN_DEFAULT_STATUS = <?php echo json_encode($defaultStatus); ?>;
    </script>
    <script src="js/loading_ui.js"></script>
    <script src="js/admin.js"></script>
    <script src="js/admin_accounts.js"></script>
</body>
</html>