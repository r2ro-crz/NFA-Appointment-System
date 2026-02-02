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

function safe_date(string $s): ?string {
    $s = trim($s);
    if ($s === '') return null;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return null;
    $dt = DateTime::createFromFormat('Y-m-d', $s);
    if (!$dt) return null;
    return $dt->format('Y-m-d');
}

$today = new DateTime('today');
$defaultEnd = $today->format('Y-m-d');
$defaultStart = (new DateTime('today'))->modify('-30 days')->format('Y-m-d');

$start = safe_date((string)($_GET['start'] ?? '')) ?? $defaultStart;
$end = safe_date((string)($_GET['end'] ?? '')) ?? $defaultEnd;

if ($start > $end) {
    $tmp = $start;
    $start = $end;
    $end = $tmp;
}

// --- Analytics data (best-effort) ---
$kpis = [
    'total_appointments' => 0,
    'pending' => 0,
    'confirmed' => 0,
    'rescheduled' => 0,
    'completed' => 0,
    'cancelled' => 0,
    'total_volume' => 0.0,
    'completed_volume' => 0.0,
    'total_capacity' => 0.0,
    'total_inventory' => 0.0,
];

$byStatus = [];
$byRegion = [];
$topBranches = [];

try {
    // Capacity totals
    $stmt = $pdo->query('SELECT COALESCE(SUM(warehouse_capacity),0) AS cap, COALESCE(SUM(inventory),0) AS inv FROM volume_capacity');
    $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
    $kpis['total_capacity'] = (float)($row['cap'] ?? 0);
    $kpis['total_inventory'] = (float)($row['inv'] ?? 0);
} catch (PDOException $e) {
    // ignore
}

try {
    // Appointment totals
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM appointments WHERE `date` BETWEEN ? AND ?');
    $stmt->execute([$start, $end]);
    $kpis['total_appointments'] = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare('SELECT COALESCE(SUM(volume),0) FROM appointments WHERE `date` BETWEEN ? AND ?');
    $stmt->execute([$start, $end]);
    $kpis['total_volume'] = (float)$stmt->fetchColumn();

    $stmt = $pdo->prepare('SELECT status, COUNT(*) AS c, COALESCE(SUM(volume),0) AS v FROM appointments WHERE `date` BETWEEN ? AND ? GROUP BY status ORDER BY c DESC');
    $stmt->execute([$start, $end]);
    $byStatus = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $counts = [];
    foreach ($byStatus as $r) {
        $s = strtolower((string)($r['status'] ?? ''));
        $counts[$s] = (int)($r['c'] ?? 0);
        if ($s === 'completed') {
            $kpis['completed_volume'] = (float)($r['v'] ?? 0);
        }
    }
    $kpis['pending'] = $counts['pending'] ?? 0;
    $kpis['confirmed'] = $counts['confirmed'] ?? 0;
    $kpis['rescheduled'] = $counts['rescheduled'] ?? 0;
    $kpis['completed'] = $counts['completed'] ?? 0;
    $kpis['cancelled'] = $counts['cancelled'] ?? 0;

    // Appointment counts by region
    $stmt = $pdo->prepare(
        'SELECT r.region_name, COUNT(*) AS c, COALESCE(SUM(a.volume),0) AS v '
        . 'FROM appointments a '
        . 'JOIN regions r ON r.region_id = a.region_id '
        . 'WHERE a.`date` BETWEEN ? AND ? '
        . 'GROUP BY r.region_id, r.region_name '
        . 'ORDER BY c DESC'
    );
    $stmt->execute([$start, $end]);
    $byRegion = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Top branches by volume
    $stmt = $pdo->prepare(
        'SELECT b.branch_name, r.region_name, COUNT(*) AS c, COALESCE(SUM(a.volume),0) AS v '
        . 'FROM appointments a '
        . 'JOIN branch b ON b.branch_id = a.branch_id '
        . 'JOIN regions r ON r.region_id = b.region_id '
        . 'WHERE a.`date` BETWEEN ? AND ? '
        . 'GROUP BY b.branch_id, b.branch_name, r.region_name '
        . 'ORDER BY v DESC, c DESC '
        . 'LIMIT 10'
    );
    $stmt->execute([$start, $end]);
    $topBranches = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) {
    // ignore; page still renders capacity totals
}

function fmt_int($n): string {
    return number_format((int)$n);
}
function fmt_num($n): string {
    return number_format((float)$n, 0);
}
function fmt_num2($n): string {
    return number_format((float)$n, 2);
}

$utilPct = ($kpis['total_capacity'] > 0) ? ($kpis['total_inventory'] / $kpis['total_capacity']) * 100 : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(nfa_page_title('Analytics'), ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="icon" href="<?php echo htmlspecialchars(NFA_FAVICON, ENT_QUOTES, 'UTF-8'); ?>" type="image/png"/>
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/processor.css">
    <link rel="stylesheet" href="css/admin.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .a-wrap{ margin-top: 1.25rem; }
        .a-toolbar{ display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; }
        .a-toolbar form{ display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap; }
        .a-toolbar label{ font-weight: 900; font-size: 12px; color: rgba(0,0,0,0.65); }
        .a-toolbar input[type="date"]{ padding: 10px 12px; border-radius: 12px; border:1px solid rgba(0,0,0,0.12); background:#fff; }
        .a-cards{ display:grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; margin-top: 12px; }
        .a-card{ background:#fff; border:1px solid rgba(0,0,0,0.08); border-radius: 16px; padding: 14px; }
        .a-card .k{ color: rgba(0,0,0,0.62); font-size: 12px; }
        .a-card .v{ font-size: 22px; font-weight: 900; margin-top: 6px; }
        .a-grid{ display:grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-top: 12px; }
        .a-panel{ background:#fff; border:1px solid rgba(0,0,0,0.08); border-radius: 16px; overflow:hidden; }
        .a-panel .head{ padding: 12px 14px; border-bottom:1px solid rgba(0,0,0,0.06); font-weight: 900; display:flex; align-items:center; justify-content:space-between; }
        .a-panel .body{ padding: 0; }
        .a-table{ width:100%; border-collapse: collapse; }
        .a-table th,.a-table td{ padding: 10px 12px; border-bottom:1px solid rgba(0,0,0,0.06); text-align:left; }
        .a-table th{ font-size: 12px; font-weight: 900; color: rgba(0,0,0,0.65); background: rgba(15,23,42,0.04); }
        .bar{ height: 10px; border-radius: 999px; background: rgba(15,23,42,0.08); overflow:hidden; }
        .bar > div{ height:100%; background: linear-gradient(135deg, var(--nfa-green) 0%, var(--nfa-green-light) 100%); width:0%; }
        .muted{ color: rgba(0,0,0,0.62); font-size: 12px; }
        @media (max-width: 1100px){ .a-cards{ grid-template-columns: repeat(2, minmax(0, 1fr)); } .a-grid{ grid-template-columns: 1fr; } }
        @media (max-width: 640px){ .a-cards{ grid-template-columns: 1fr; } }
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
            <p class="nfa-subtitle"><span class="page-subtitle">Analytics</span></p>
        </div>
    </div>

    <div class="nav-center">
        <div class="nav-links">
            <a href="admin_dashboard.php" class="nav-link"><i class="fas fa-shield-alt"></i><span>Dashboard</span></a>
            <a href="admin_accounts.php" class="nav-link"><i class="fas fa-user-check"></i><span>Accounts</span></a>
            <a href="admin_activity_logs.php" class="nav-link"><i class="fas fa-clipboard-list"></i><span>Activity Log</span></a>
            <a href="admin_capacity_overview.php" class="nav-link"><i class="fas fa-warehouse"></i><span>Capacity</span></a>
            <a href="admin_master_data.php" class="nav-link"><i class="fas fa-database"></i><span>Master Data</span></a>
            <a href="admin_analytics.php" class="nav-link active" aria-current="page"><i class="fas fa-chart-pie"></i><span>Analytics</span></a>
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
            <h1><i class="fas fa-chart-pie"></i> High-Level Analytics</h1>
            <p>Organization-wide KPIs and trends.</p>
        </div>
        <div class="header-actions">
            <a class="btn-outline" href="admin_dashboard.php"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
        </div>
    </div>

    <div class="a-wrap">
        <div class="a-toolbar">
            <div class="muted">Range: <strong><?php echo htmlspecialchars($start); ?></strong> to <strong><?php echo htmlspecialchars($end); ?></strong></div>
            <form method="get" action="admin_analytics.php">
                <div>
                    <label for="start">Start</label><br>
                    <input id="start" name="start" type="date" value="<?php echo htmlspecialchars($start); ?>" />
                </div>
                <div>
                    <label for="end">End</label><br>
                    <input id="end" name="end" type="date" value="<?php echo htmlspecialchars($end); ?>" />
                </div>
                <button class="btn-outline" type="submit"><i class="fas fa-filter"></i> Apply</button>
            </form>
        </div>

        <div class="a-cards">
            <div class="a-card"><div class="k">Total Appointments</div><div class="v"><?php echo fmt_int($kpis['total_appointments']); ?></div></div>
            <div class="a-card"><div class="k">Pending</div><div class="v"><?php echo fmt_int($kpis['pending']); ?></div></div>
            <div class="a-card"><div class="k">Completed</div><div class="v"><?php echo fmt_int($kpis['completed']); ?></div></div>
            <div class="a-card"><div class="k">Cancelled</div><div class="v"><?php echo fmt_int($kpis['cancelled']); ?></div></div>
            <div class="a-card"><div class="k">Submitted Volume</div><div class="v"><?php echo fmt_num2($kpis['total_volume']); ?></div></div>
            <div class="a-card"><div class="k">Completed Volume</div><div class="v"><?php echo fmt_num2($kpis['completed_volume']); ?></div></div>
            <div class="a-card"><div class="k">Total Capacity</div><div class="v"><?php echo fmt_num($kpis['total_capacity']); ?></div></div>
            <div class="a-card"><div class="k">Overall Utilization</div><div class="v"><?php echo number_format($utilPct, 1); ?>%</div></div>
        </div>

        <div class="a-grid">
            <section class="a-panel" aria-label="Appointments by status">
                <div class="head"><span><i class="fas fa-layer-group"></i> Appointments by Status</span></div>
                <div class="body">
                    <table class="a-table">
                        <thead>
                            <tr>
                                <th>Status</th>
                                <th style="width:120px;">Count</th>
                                <th style="width:180px;">Volume</th>
                                <th style="width:240px;">Share</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($byStatus) === 0): ?>
                                <tr><td colspan="4" class="muted">No appointment data available for this range.</td></tr>
                            <?php else:
                                $max = 0;
                                foreach ($byStatus as $r) $max = max($max, (int)($r['c'] ?? 0));
                                foreach ($byStatus as $r):
                                    $c = (int)($r['c'] ?? 0);
                                    $w = ($max > 0) ? ($c / $max) * 100 : 0;
                            ?>
                                <tr>
                                    <td><?php echo htmlspecialchars((string)($r['status'] ?? '')); ?></td>
                                    <td><?php echo fmt_int($c); ?></td>
                                    <td><?php echo fmt_num2((float)($r['v'] ?? 0)); ?></td>
                                    <td>
                                        <div class="bar"><div style="width:<?php echo number_format($w, 2); ?>%"></div></div>
                                    </td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="a-panel" aria-label="Appointments by region">
                <div class="head"><span><i class="fas fa-map"></i> Appointments by Region</span></div>
                <div class="body">
                    <table class="a-table">
                        <thead>
                            <tr>
                                <th>Region</th>
                                <th style="width:120px;">Count</th>
                                <th style="width:180px;">Volume</th>
                                <th style="width:240px;">Share</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($byRegion) === 0): ?>
                                <tr><td colspan="4" class="muted">No regional data available for this range.</td></tr>
                            <?php else:
                                $max = 0;
                                foreach ($byRegion as $r) $max = max($max, (int)($r['c'] ?? 0));
                                foreach ($byRegion as $r):
                                    $c = (int)($r['c'] ?? 0);
                                    $w = ($max > 0) ? ($c / $max) * 100 : 0;
                            ?>
                                <tr>
                                    <td><?php echo htmlspecialchars((string)($r['region_name'] ?? '')); ?></td>
                                    <td><?php echo fmt_int($c); ?></td>
                                    <td><?php echo fmt_num2((float)($r['v'] ?? 0)); ?></td>
                                    <td>
                                        <div class="bar"><div style="width:<?php echo number_format($w, 2); ?>%"></div></div>
                                    </td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="a-panel" aria-label="Top branches">
                <div class="head"><span><i class="fas fa-trophy"></i> Top Branches (by Volume)</span></div>
                <div class="body">
                    <table class="a-table">
                        <thead>
                            <tr>
                                <th>Branch</th>
                                <th style="width:160px;">Region</th>
                                <th style="width:110px;">Count</th>
                                <th style="width:180px;">Volume</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($topBranches) === 0): ?>
                                <tr><td colspan="4" class="muted">No branch data available for this range.</td></tr>
                            <?php else: foreach ($topBranches as $r): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars((string)($r['branch_name'] ?? '')); ?></td>
                                    <td><?php echo htmlspecialchars((string)($r['region_name'] ?? '')); ?></td>
                                    <td><?php echo fmt_int((int)($r['c'] ?? 0)); ?></td>
                                    <td><?php echo fmt_num2((float)($r['v'] ?? 0)); ?></td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="a-panel" aria-label="Capacity summary">
                <div class="head"><span><i class="fas fa-warehouse"></i> Capacity Summary</span></div>
                <div class="body">
                    <table class="a-table">
                        <tbody>
                            <tr><th>Total Capacity</th><td><?php echo fmt_num2($kpis['total_capacity']); ?></td></tr>
                            <tr><th>Total Inventory</th><td><?php echo fmt_num2($kpis['total_inventory']); ?></td></tr>
                            <tr><th>Available</th><td><?php echo fmt_num2(max(0, $kpis['total_capacity'] - $kpis['total_inventory'])); ?></td></tr>
                            <tr><th>Utilization</th><td><?php echo number_format($utilPct, 1); ?>%</td></tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </div>
</main>

<script src="js/admin.js"></script>
</body>
</html>
