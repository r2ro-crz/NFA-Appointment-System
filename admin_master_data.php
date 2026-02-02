<?php
session_start();
require_once 'php_helper/db_config.php';
require_once __DIR__ . '/php_helper/branding.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

if (!isset($_SESSION['loggedin']) || ($_SESSION['user_type'] ?? '') !== 'Admin') {
    header('location: login.php');
    exit;
}

$user_id = (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? 0);

$first_name = $_SESSION['username'] ?? 'Admin';
$last_name = '';
$user_email = '';
try {
    if ($user_id > 0) {
        $stmt = $pdo->prepare('SELECT first_name, last_name, email_address FROM users WHERE user_id = ? LIMIT 1');
        $stmt->execute([$user_id]);
        if ($u = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $first_name = (string)($u['first_name'] ?? $first_name);
            $last_name = (string)($u['last_name'] ?? '');
            $user_email = (string)($u['email_address'] ?? '');
        }
    }
} catch (Exception $e) {
    // ignore
}

$initials = '';
if ($first_name !== '') $initials .= strtoupper(substr($first_name, 0, 1));
if ($last_name !== '') $initials .= strtoupper(substr($last_name, 0, 1));
if ($initials === '' && !empty($_SESSION['username'])) $initials = strtoupper(substr((string)$_SESSION['username'], 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(nfa_page_title('Master Data'), ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="icon" href="<?php echo htmlspecialchars(NFA_FAVICON, ENT_QUOTES, 'UTF-8'); ?>" type="image/png"/>
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/processor.css">
    <link rel="stylesheet" href="css/admin.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .md-wrap{ margin-top: 1.25rem; }
        .md-grid{ display:grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 14px; }
        .md-card{ background:#fff; border:1px solid rgba(0,0,0,0.08); border-radius: 16px; overflow:hidden; }
        .md-head{ padding: 14px 14px; border-bottom:1px solid rgba(0,0,0,0.06); display:flex; align-items:center; justify-content:space-between; gap:10px; }
        .md-head h3{ margin:0; font-size: 16px; font-weight: 900; }
        .md-body{ padding: 14px; }
        .md-row{ display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
        .md-row input{ padding: 10px 12px; border-radius: 12px; border: 1px solid rgba(0,0,0,0.12); min-width: 280px; max-width: 100%; }
        .md-row select{ padding: 10px 12px; border-radius: 12px; border: 1px solid rgba(0,0,0,0.12); background:#fff; min-width: 280px; max-width: 100%; }
        .md-table{ width:100%; border-collapse: collapse; margin-top: 12px; }
        .md-table th,.md-table td{ padding: 10px 10px; border-bottom: 1px solid rgba(0,0,0,0.06); text-align:left; }
        .md-table th{ font-size: 12px; color: rgba(0,0,0,0.65); font-weight: 900; background: rgba(15,23,42,0.04); }
        .md-actions{ display:flex; gap:8px; align-items:center; justify-content:flex-end; }
        .md-muted{ color: rgba(0,0,0,0.62); font-size: 12px; }
        .md-pill{ display:inline-flex; align-items:center; gap:6px; padding: 5px 10px; border-radius: 999px; border:1px solid rgba(0,0,0,0.12); background: rgba(15,23,42,0.02); font-weight: 900; font-size: 12px; }
        .md-error{ color:#9f2e24; font-weight: 800; }
        .md-ok{ color:#0f6b35; font-weight: 800; }
        @media (max-width: 1200px){ .md-grid{ grid-template-columns: 1fr 1fr; } }
        @media (max-width: 980px){ .md-grid{ grid-template-columns: 1fr; } }
    </style>
</head>
<body class="dashboard">

<nav class="top-nav" role="navigation" aria-label="Main navigation">
    <div class="logo">
        <div class="brand-logos">
            <img src="<?php echo htmlspecialchars(NFA_SYSTEM_LOGO, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars(NFA_SYSTEM_NAME, ENT_QUOTES, 'UTF-8'); ?>" class="system-logo">
        </div>
        <div class="logo-text">
            <h1 class="nfa-title"><?php echo htmlspecialchars(NFA_BRAND_NAME, ENT_QUOTES, 'UTF-8'); ?></h1>
            <p class="nfa-subtitle"><span class="page-subtitle">Master Data</span></p>
        </div>
    </div>

    <div class="nav-center">
        <div class="nav-links">
            <a href="admin_dashboard.php" class="nav-link"><i class="fas fa-shield-alt"></i><span>Dashboard</span></a>
            <a href="admin_accounts.php" class="nav-link"><i class="fas fa-user-check"></i><span>Accounts</span></a>
            <a href="admin_activity_logs.php" class="nav-link"><i class="fas fa-clipboard-list"></i><span>Activity Log</span></a>
            <a href="admin_capacity_overview.php" class="nav-link"><i class="fas fa-warehouse"></i><span>Capacity</span></a>
            <a href="admin_master_data.php" class="nav-link active" aria-current="page"><i class="fas fa-database"></i><span>Master Data</span></a>
            <a href="admin_analytics.php" class="nav-link"><i class="fas fa-chart-pie"></i><span>Analytics</span></a>
        </div>
    </div>

    <div class="user-actions">
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
                    <a href="logout.php" class="dropdown-item logout" role="menuitem"><i class="fas fa-sign-out-alt"></i> Logout</a>
                </div>
            </div>
        </div>
    </div>
</nav>

<main id="main-content" class="container-fluid" role="main">
    <div class="welcome-header" style="margin-top: 1.25rem;">
        <div class="welcome-text">
            <h1><i class="fas fa-database"></i> System-Wide Master Data</h1>
            <p>Manage reference lists used across the system (safe edits only).</p>
        </div>
        <div class="header-actions">
            <a class="btn-outline" href="admin_dashboard.php"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
        </div>
    </div>

    <div class="md-wrap">
        <div class="md-grid">
            <section class="md-card" aria-label="Regions master data">
                <div class="md-head">
                    <h3><i class="fas fa-map"></i> Regions</h3>
                    <span class="md-pill" id="regionsCount">0</span>
                </div>
                <div class="md-body">
                    <div class="md-row">
                        <input id="newRegionName" type="text" maxlength="255" placeholder="New region name" />
                        <input id="newRegionBranchName" type="text" maxlength="255" placeholder="Initial branch name (required)" />
                        <button class="btn-primary" id="addRegionBtn" type="button"><i class="fas fa-plus"></i> Add</button>
                        <span class="md-muted" id="regionsStatus"></span>
                    </div>

                    <table class="md-table" aria-label="Regions table">
                        <thead>
                            <tr>
                                <th style="width:100px;">ID</th>
                                <th>Name</th>
                                <th style="width:220px; text-align:right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="regionsTbody">
                            <tr><td colspan="3" class="md-muted">Loading…</td></tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="md-card" aria-label="Farmer types master data">
                <div class="md-head">
                    <h3><i class="fas fa-user-tag"></i> Farmer Types</h3>
                    <span class="md-pill" id="typesCount">0</span>
                </div>
                <div class="md-body">
                    <div class="md-row">
                        <input id="newTypeName" type="text" maxlength="50" placeholder="Add new farmer type" />
                        <button class="btn-primary" id="addTypeBtn" type="button"><i class="fas fa-plus"></i> Add</button>
                        <span class="md-muted" id="typesStatus"></span>
                    </div>

                    <table class="md-table" aria-label="Farmer types table">
                        <thead>
                            <tr>
                                <th style="width:100px;">ID</th>
                                <th>Name</th>
                                <th style="width:220px; text-align:right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="typesTbody">
                            <tr><td colspan="3" class="md-muted">Loading…</td></tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="md-card" aria-label="Branches master data">
                <div class="md-head">
                    <h3><i class="fas fa-code-branch"></i> Branches</h3>
                    <span class="md-pill" id="branchesCount">0</span>
                </div>
                <div class="md-body">
                    <div class="md-row">
                        <select id="branchRegionSelect" aria-label="Select region for new branch">
                            <option value="0">Select region…</option>
                        </select>
                        <input id="newBranchName" type="text" maxlength="255" placeholder="New branch name" />
                        <button class="btn-primary" id="addBranchBtn" type="button"><i class="fas fa-plus"></i> Add</button>
                        <span class="md-muted" id="branchesStatus"></span>
                    </div>

                    <div class="md-row" style="margin-top: 10px;">
                        <select id="branchRegionFilter" aria-label="Filter branches by region">
                            <option value="0">All regions</option>
                        </select>
                        <button class="btn-outline" id="refreshBranchesBtn" type="button"><i class="fas fa-rotate"></i> Refresh</button>
                        <span class="md-muted">Tip: filter by region for faster editing.</span>
                    </div>

                    <table class="md-table" aria-label="Branches table">
                        <thead>
                            <tr>
                                <th style="width:90px;">ID</th>
                                <th style="width:160px;">Region</th>
                                <th>Name</th>
                                <th style="width:220px; text-align:right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="branchesTbody">
                            <tr><td colspan="4" class="md-muted">Loading…</td></tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </div>
</main>

<script src="js/admin.js"></script>
<script src="js/admin_master_data.js"></script>
</body>
</html>
