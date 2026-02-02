<?php
session_start();
require_once 'php_helper/db_config.php';
require_once __DIR__ . '/php_helper/branding.php';

// Prevent caching of protected pages (helps prevent back-button access after logout)
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
header('Expires: 0');

if (!isset($_SESSION['loggedin'])) {
    header('location: login.php');
    exit;
}

$user_id = (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? 0);
$user_type = (string)($_SESSION['user_type'] ?? '');
$branch_id = (int)($_SESSION['branch_id'] ?? 0);

if ($user_id <= 0) {
    header('location: login.php');
    exit;
}

// Minimal header identity
$first_name = $_SESSION['username'] ?? 'User';
$last_name = '';
$user_email = '';
$branch_name = 'Branch';

try {
    $stmt = $pdo->prepare("SELECT first_name, last_name, email_address, branch_id FROM users WHERE user_id = ? LIMIT 1");
    $stmt->execute([$user_id]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($u) {
        $first_name = $u['first_name'] ?: $first_name;
        $last_name = $u['last_name'] ?: $last_name;
        $user_email = (string)($u['email_address'] ?? '');
        if (!$branch_id && isset($u['branch_id'])) {
            $branch_id = (int)$u['branch_id'];
        }
    }

    if ($branch_id > 0) {
        $b = $pdo->prepare("SELECT branch_name FROM branch WHERE branch_id = ? LIMIT 1");
        $b->execute([$branch_id]);
        $bn = $b->fetch(PDO::FETCH_ASSOC);
        if ($bn && !empty($bn['branch_name'])) $branch_name = $bn['branch_name'];
    }
} catch (Exception $e) {
    // ignore
}

$initials = strtoupper(substr((string)$first_name, 0, 1) . substr((string)$last_name, 0, 1));
$role = $user_type !== '' ? $user_type : 'User';

$dashboardHref = 'processor_dashboard.php';
if (strcasecmp($role, 'Admin') === 0 || strcasecmp($user_type, 'Admin') === 0) {
    $dashboardHref = 'admin_dashboard.php';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(nfa_page_title('Settings'), ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="icon" href="<?php echo htmlspecialchars(NFA_FAVICON, ENT_QUOTES, 'UTF-8'); ?>" type="image/png"/>
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/processor.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="dashboard settings-page">

    <nav class="top-nav">
        <div class="logo">
            <div class="brand-logos">
                <img src="<?php echo htmlspecialchars(NFA_SYSTEM_LOGO, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars(NFA_SYSTEM_NAME, ENT_QUOTES, 'UTF-8'); ?>" class="system-logo">
            </div>
            <div class="logo-text">
                <h1 class="nfa-title"><?php echo htmlspecialchars(NFA_BRAND_NAME, ENT_QUOTES, 'UTF-8'); ?></h1>
                <p class="nfa-subtitle"><span class="page-subtitle">Settings</span></p>
            </div>
        </div>
        <div class="user-actions">
            <div class="user-profile">
                <div class="user-avatar"><?php echo htmlspecialchars($initials); ?></div>
                <div class="user-info">
                    <span class="user-name"><?php echo htmlspecialchars(trim($first_name . ' ' . $last_name)); ?></span>
                    <span class="user-role"><?php echo htmlspecialchars($role); ?></span>
                </div>
                <div class="user-dropdown" id="userDropdown">
                    <div class="user-dropdown-header">
                        <div class="user-dropdown-avatar"><?php echo htmlspecialchars($initials); ?></div>
                        <div class="user-dropdown-info">
                            <strong><?php echo htmlspecialchars(trim($first_name . ' ' . $last_name)); ?></strong>
                            <small><?php echo htmlspecialchars($user_email); ?></small>
                            <div class="user-role-badge"><?php echo htmlspecialchars($role); ?></div>
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
                        <a href="settings.php" class="dropdown-item active">
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
        <div class="profile-hero">
            <div class="profile-hero-left">
                <h1>Preferences</h1>
                <p class="profile-subtitle">Personalize how pages behave and feel. Saved to this browser.</p>
            </div>
            <div class="profile-hero-right">
                <a class="btn-view-details btn-inline-secondary" href="<?php echo htmlspecialchars($dashboardHref); ?>"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
            </div>
        </div>

        <div class="settings-grid">
            <div class="dashboard-card">
                <div class="card-header">
                    <h3><i class="fas fa-gauge-high"></i> Dashboard</h3>
                </div>
                <div class="card-body">
                    <div class="setting-row">
                        <div class="setting-meta">
                            <div class="setting-title">Auto-refresh dashboard</div>
                            <div class="setting-desc">Refreshes stats every minute when idle.</div>
                        </div>
                        <label class="switch">
                            <input type="checkbox" id="setAutoRefresh">
                            <span class="slider"></span>
                        </label>
                    </div>
                </div>
            </div>

            <div class="dashboard-card">
                <div class="card-header">
                    <h3><i class="fas fa-palette"></i> Appearance</h3>
                </div>
                <div class="card-body">
                    <div class="setting-row">
                        <div class="setting-meta">
                            <div class="setting-title">Compact mode</div>
                            <div class="setting-desc">Tighter spacing for smaller screens.</div>
                        </div>
                        <label class="switch">
                            <input type="checkbox" id="setCompact">
                            <span class="slider"></span>
                        </label>
                    </div>

                    <div class="setting-row">
                        <div class="setting-meta">
                            <div class="setting-title">Reduce motion</div>
                            <div class="setting-desc">Minimizes animations and hover lifts.</div>
                        </div>
                        <label class="switch">
                            <input type="checkbox" id="setReduceMotion">
                            <span class="slider"></span>
                        </label>
                    </div>
                </div>
            </div>

            <div class="dashboard-card">
                <div class="card-header">
                    <h3><i class="fas fa-bell"></i> Notifications</h3>
                </div>
                <div class="card-body">
                    <div class="setting-row">
                        <div class="setting-meta">
                            <div class="setting-title">Toast notifications</div>
                            <div class="setting-desc">Use animated toasts for feedback messages.</div>
                        </div>
                        <label class="switch">
                            <input type="checkbox" id="setToasts">
                            <span class="slider"></span>
                        </label>
                    </div>
                </div>
            </div>
        </div>

        <div class="settings-actions">
            <button class="btn-view-details btn-inline-secondary" id="btnResetSettings" type="button"><i class="fas fa-rotate-left"></i> Reset</button>
            <button class="btn-view-details btn-inline-success" id="btnSaveSettings" type="button"><i class="fas fa-check"></i> Save Settings</button>
        </div>
    </div>

    <script src="js/processor.js?v=<?php echo urlencode((string)@filemtime(__DIR__ . '/js/processor.js')); ?>"></script>
    <script src="js/settings.js?v=<?php echo urlencode((string)@filemtime(__DIR__ . '/js/settings.js')); ?>"></script>
</body>
</html>
