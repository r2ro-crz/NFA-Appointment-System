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

// Initial capacity snapshot for fast first paint (JS will refresh)
$stmt = $pdo->prepare("SELECT warehouse_capacity, inventory FROM volume_capacity WHERE branch_id = ?");
$stmt->execute([$branch_id]);
$cap = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['warehouse_capacity' => 0, 'inventory' => 0];

$warehouse_capacity = (float)($cap['warehouse_capacity'] ?? 0);
$inventory = (float)($cap['inventory'] ?? 0);
$available = max(0, $warehouse_capacity - $inventory);
$percent = $warehouse_capacity > 0 ? ($inventory / $warehouse_capacity) * 100 : 0;

// Notifications (same behavior as other processor pages)
$notif_stmt = $pdo->prepare("SELECT appointment_id, first_name, last_name, status, date, time_slot, volume, is_read FROM appointments WHERE branch_id = ? AND status IN ('pending', 'cancelled') ORDER BY appointment_id DESC LIMIT 10");
$notif_stmt->execute([$branch_id]);
$notifications = $notif_stmt->fetchAll(PDO::FETCH_ASSOC);
$new_count = count(array_filter($notifications, fn($n) => (empty($n['is_read']) || $n['is_read'] == 0)));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Capacity Management</title>
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/processor.css">
    <link rel="stylesheet" href="css/capacity_management.css?v=<?php echo urlencode((string)@filemtime(__DIR__ . '/css/capacity_management.css')); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="dashboard">

    <div id="capacity-data-store"
        data-branch-name="<?php echo htmlspecialchars((string)$branch_name, ENT_QUOTES, 'UTF-8'); ?>"
        data-region-name="<?php echo htmlspecialchars((string)$region_name, ENT_QUOTES, 'UTF-8'); ?>"
        data-capacity="<?php echo (float)$warehouse_capacity; ?>"
        data-inventory="<?php echo (float)$inventory; ?>"
        data-available="<?php echo (float)$available; ?>"
        data-percent="<?php echo (float)$percent; ?>"
        style="display:none;"></div>

    <nav class="top-nav">
        <div class="logo">
            <img src="img/nfa-logo.png" alt="NFA" class="nfa-logo">
            <div class="logo-text">
                <h1 class="nfa-title">National Food Authority</h1>
                <p class="nfa-subtitle">Capacity Management</p>
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
                <a href="capacity_management.php" class="nav-link active">
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
                                            <span class="volume-badge">
                                                <i class="fas fa-weight-hanging"></i> <?php echo number_format((float)$n['volume']); ?> bags
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
                        <a href="profile.php" class="dropdown-item"><i class="fas fa-user-cog"></i> My Profile</a>
                        <a href="settings.php" class="dropdown-item"><i class="fas fa-cog"></i> Settings</a>
                        <div class="dropdown-divider"></div>
                        <a href="login.php" class="dropdown-item logout"><i class="fas fa-sign-out-alt"></i> Logout</a>
                    </div>
                </div>
            </div>
        </div>
    </nav>

    <div class="container-fluid capacity-page">
        <div class="capacity-hero">
            <div class="capacity-hero-left">
                <h1>Capacity</h1>
                <p class="capacity-subtitle">Live warehouse utilization for <strong><?php echo htmlspecialchars($branch_name); ?></strong> • <span><?php echo htmlspecialchars($region_name); ?></span></p>
                <div class="capacity-hero-actions">
                    <button class="btn-view-details btn-inline-primary" id="btnEditCapacity">
                        <i class="fas fa-sliders"></i> Adjust Capacity / Inventory
                    </button>
                    <button class="btn-view-details btn-inline-secondary" id="btnRefreshCapacity">
                        <i class="fas fa-rotate"></i> Refresh
                    </button>
                </div>
            </div>
            <div class="capacity-hero-right">
                <div class="capacity-health" id="capacityHealthBadge">
                    <span class="dot" aria-hidden="true"></span>
                    <span class="label">Status</span>
                </div>
                <div class="capacity-updated" id="capacityUpdatedText">Updated just now</div>
            </div>
        </div>

        <div class="capacity-kpis" id="capacityKpis">
            <div class="capacity-kpi-card">
                <div class="kpi-icon kpi-total"><i class="fas fa-warehouse"></i></div>
                <div class="kpi-meta">
                    <div class="kpi-label">Warehouse Capacity</div>
                    <div class="kpi-value" id="kpiCapacity">0</div>
                    <div class="kpi-sub">bags</div>
                </div>
            </div>
            <div class="capacity-kpi-card">
                <div class="kpi-icon kpi-inventory"><i class="fas fa-boxes-stacked"></i></div>
                <div class="kpi-meta">
                    <div class="kpi-label">Current Inventory</div>
                    <div class="kpi-value" id="kpiInventory">0</div>
                    <div class="kpi-sub">bags</div>
                </div>
            </div>
            <div class="capacity-kpi-card">
                <div class="kpi-icon kpi-available"><i class="fas fa-chart-pie"></i></div>
                <div class="kpi-meta">
                    <div class="kpi-label">Available Capacity</div>
                    <div class="kpi-value" id="kpiAvailable">0</div>
                    <div class="kpi-sub">bags</div>
                </div>
            </div>
            <div class="capacity-kpi-card">
                <div class="kpi-icon kpi-percent"><i class="fas fa-gauge-high"></i></div>
                <div class="kpi-meta">
                    <div class="kpi-label">Utilization</div>
                    <div class="kpi-value"><span id="kpiPercent">0</span><span class="kpi-unit">%</span></div>
                    <div class="kpi-sub">inventory / capacity</div>
                </div>
            </div>
        </div>

        <div class="capacity-panels">
            <div class="capacity-panel">
                <div class="panel-header">
                    <h2><i class="fas fa-circle-notch"></i> Utilization</h2>
                    <div class="panel-hint">Animated live indicator</div>
                </div>
                <div class="ring-wrap">
                    <div class="ring">
                        <svg viewBox="0 0 120 120" class="ring-svg" aria-label="Capacity utilization">
                            <circle cx="60" cy="60" r="52" class="ring-track"></circle>
                            <circle cx="60" cy="60" r="52" class="ring-progress" id="ringProgress"></circle>
                        </svg>
                        <div class="ring-center">
                            <div class="ring-percent"><span id="ringPercent">0</span>%</div>
                            <div class="ring-label">Used</div>
                        </div>
                    </div>
                    <div class="meter">
                        <div class="meter-top">
                            <span>Inventory</span>
                            <strong><span id="meterInventory">0</span> / <span id="meterCapacity">0</span></strong>
                        </div>
                        <div class="meter-bar" aria-hidden="true">
                            <div class="meter-fill" id="meterFill"></div>
                        </div>
                        <div class="meter-bottom">
                            <span id="meterLeft">0 bags available</span>
                            <span class="meter-note">Auto-updates when deliveries are completed.</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="capacity-panel">
                <div class="panel-header">
                    <h2><i class="fas fa-shield-halved"></i> Controls</h2>
                    <div class="panel-hint">Safe updates with validation</div>
                </div>

                <div class="control-card">
                    <div class="control-row">
                        <div>
                            <div class="control-title">Update warehouse numbers</div>
                            <div class="control-desc">Adjust total capacity or correct current inventory. Inventory is automatically increased when an appointment is marked completed.</div>
                        </div>
                        <button class="btn-view-details btn-inline-primary" id="btnOpenModal">
                            <i class="fas fa-pen-to-square"></i> Update
                        </button>
                    </div>
                </div>

                <div class="control-card subtle">
                    <div class="control-row">
                        <div>
                            <div class="control-title">Tip</div>
                            <div class="control-desc">If you reduce warehouse capacity, make sure it stays greater than or equal to inventory.</div>
                        </div>
                    </div>
                </div>

                <div class="control-card subtle">
                    <div class="control-row">
                        <div>
                            <div class="control-title">Live sync</div>
                            <div class="control-desc">After a successful update, this page broadcasts a refresh to other open processor tabs.</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="modal-overlay" id="capacityModal" style="display:none;">
            <div class="modal-dialog" role="dialog" aria-modal="true" aria-labelledby="capModalTitle">
                <button class="modal-close" type="button" id="capModalClose" aria-label="Close"><i class="fas fa-times"></i></button>
                <div class="modal-header">
                    <h2 id="capModalTitle">Update Capacity</h2>
                    <p class="modal-ref">Branch: <strong id="capModalBranch"><?php echo htmlspecialchars($branch_name); ?></strong></p>
                </div>

                <div class="modal-body">
                    <div class="modal-section">
                        <h3>Warehouse Values</h3>
                        <div class="cap-form-grid">
                            <label class="cap-field">
                                <span>Warehouse Capacity (bags)</span>
                                <input type="number" inputmode="numeric" min="0" step="1" id="capInputCapacity" placeholder="e.g. 3000">
                            </label>
                            <label class="cap-field">
                                <span>Current Inventory (bags)</span>
                                <input type="number" inputmode="numeric" min="0" step="1" id="capInputInventory" placeholder="e.g. 1550">
                            </label>
                            <div class="cap-field readonly">
                                <span>Available Capacity</span>
                                <div class="cap-readonly" id="capComputedAvailable">0</div>
                            </div>
                            <div class="cap-field readonly">
                                <span>Utilization</span>
                                <div class="cap-readonly" id="capComputedPercent">0%</div>
                            </div>
                        </div>
                        <div class="cap-validation" id="capValidation" role="status" aria-live="polite"></div>
                    </div>

                    <div class="modal-section">
                        <button class="btn-view-details btn-inline-primary" id="capSubmit">Save Changes</button>
                        <button class="btn-view-details btn-inline-secondary" id="capCancel">Cancel</button>
                    </div>
                </div>
            </div>
        </div>

    </div>

    <script>
        window.userId = <?php echo json_encode((int)($user_id ?? 0)); ?>;
        window.branchId = <?php echo json_encode((int)$branch_id); ?>;
    </script>

    <script src="js/loading_ui.js?v=<?php echo urlencode((string)@filemtime(__DIR__ . '/js/loading_ui.js')); ?>"></script>
    <script src="js/refresh_bus.js?v=<?php echo urlencode((string)@filemtime(__DIR__ . '/js/refresh_bus.js')); ?>"></script>
    <script src="js/processor.js?v=<?php echo urlencode((string)@filemtime(__DIR__ . '/js/processor.js')); ?>"></script>
    <script src="js/capacity_management.js?v=<?php echo urlencode((string)@filemtime(__DIR__ . '/js/capacity_management.js')); ?>"></script>
</body>
</html>
