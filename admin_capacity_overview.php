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

// Regions for filter
$regions = [];
try {
    $stmt = $pdo->query("SELECT region_id, region_name FROM regions ORDER BY region_name");
    $regions = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
} catch (Exception $e) {
    $regions = [];
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(nfa_page_title('Capacity Overview'), ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="icon" href="<?php echo htmlspecialchars(NFA_FAVICON, ENT_QUOTES, 'UTF-8'); ?>" type="image/png"/>
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/processor.css">
    <link rel="stylesheet" href="css/admin.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .admin-cap-wrap{ margin-top: 1.25rem; }
        .admin-cap-toolbar{ display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; }
        .admin-cap-toolbar .filters{ display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
        .admin-cap-toolbar select{ padding: 10px 12px; border-radius: 12px; border: 1px solid rgba(0,0,0,0.12); background:#fff; min-width: 260px; }
        .admin-cap-cards{ display:grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; margin-top: 12px; }
        .admin-cap-card{ background:#fff; border:1px solid rgba(0,0,0,0.08); border-radius: 16px; padding: 14px 14px; }
        .admin-cap-card .k{ color: rgba(0,0,0,0.62); font-size: 12px; }
        .admin-cap-card .v{ font-size: 22px; font-weight: 900; margin-top: 6px; }
        .admin-cap-table{ background:#fff; border:1px solid rgba(0,0,0,0.08); border-radius: 16px; overflow:hidden; margin-top: 12px; }
        .admin-cap-table table{ width:100%; border-collapse: collapse; }
        .admin-cap-table th, .admin-cap-table td{ padding: 10px 12px; border-bottom: 1px solid rgba(0,0,0,0.06); text-align:left; }
        .admin-cap-table th{ background: rgba(15,23,42,0.04); font-weight: 900; font-size: 12px; color: rgba(0,0,0,0.68); }
        .badge-pct{ display:inline-flex; align-items:center; padding: 4px 10px; border-radius:999px; font-weight:900; font-size:12px; }
        .badge-good{ background: rgba(0,122,51,0.12); color:#0f6b35; border:1px solid rgba(0,122,51,0.22); }
        .badge-warn{ background: rgba(243,156,18,0.14); color:#8a5a06; border:1px solid rgba(243,156,18,0.28); }
        .badge-bad{ background: rgba(231,76,60,0.14); color:#9f2e24; border:1px solid rgba(231,76,60,0.28); }

        .admin-cap-history{ margin-top: 14px; background:#fff; border:1px solid rgba(0,0,0,0.08); border-radius: 16px; overflow:hidden; }
        .admin-cap-history .head{ padding: 12px 14px; border-bottom:1px solid rgba(0,0,0,0.06); display:flex; align-items:center; justify-content:space-between; gap:10px; }
        .admin-cap-history .list{ max-height: 420px; overflow:auto; }
        .admin-cap-history .row{ padding: 10px 14px; border-bottom:1px solid rgba(0,0,0,0.06); }
        .admin-cap-history .row:last-child{ border-bottom:none; }
        .admin-cap-history .t{ font-weight:900; }
        .admin-cap-history .s{ color: rgba(0,0,0,0.65); font-size: 12px; margin-top: 3px; }
        @media (max-width: 1100px){ .admin-cap-cards{ grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        @media (max-width: 640px){ .admin-cap-cards{ grid-template-columns: 1fr; } .admin-cap-toolbar select{ min-width: 100%; } }
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
            <p class="nfa-subtitle"><span class="page-subtitle">Capacity Overview</span></p>
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
            <a href="admin_activity_logs.php" class="nav-link">
                <i class="fas fa-clipboard-list"></i>
                <span>Activity Log</span>
            </a>
            <a href="admin_capacity_overview.php" class="nav-link active" aria-current="page">
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
            <h1><i class="fas fa-warehouse"></i> Cross-Branch Capacity</h1>
            <p>Monitor warehouse capacity and inventory across all branches.</p>
        </div>
        <div class="header-actions">
            <a class="btn-outline" href="admin_dashboard.php"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
        </div>
    </div>

    <div class="admin-cap-wrap">
        <div class="admin-cap-toolbar">
            <div class="filters">
                <label class="muted" for="capRegionSel" style="font-weight:800;">Region</label>
                <select id="capRegionSel">
                    <option value="0">All Regions</option>
                    <?php foreach ($regions as $r): ?>
                        <option value="<?php echo (int)$r['region_id']; ?>"><?php echo htmlspecialchars((string)$r['region_name'], ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="btn-outline" type="button" id="capRefreshBtn"><i class="fas fa-rotate"></i> Refresh</button>
            </div>
        </div>

        <div class="admin-cap-cards">
            <div class="admin-cap-card"><div class="k">Branches</div><div class="v" id="kpiBranches">0</div></div>
            <div class="admin-cap-card"><div class="k">Total Capacity</div><div class="v" id="kpiCap">0</div></div>
            <div class="admin-cap-card"><div class="k">Total Inventory</div><div class="v" id="kpiInv">0</div></div>
            <div class="admin-cap-card"><div class="k">Overall Utilization</div><div class="v" id="kpiPct">0%</div></div>
        </div>

        <div class="admin-cap-table" aria-label="Branch capacity table">
            <table>
                <thead>
                    <tr>
                        <th>Region</th>
                        <th>Branch</th>
                        <th>Capacity</th>
                        <th>Inventory</th>
                        <th>Available</th>
                        <th>Utilization</th>
                    </tr>
                </thead>
                <tbody id="capTableBody">
                    <tr><td colspan="6" class="muted">Loading…</td></tr>
                </tbody>
            </table>
        </div>

        <div class="admin-cap-history" aria-label="Capacity change history">
            <div class="head">
                <div style="font-weight:900;"><i class="fas fa-clock-rotate-left"></i> Change History</div>
                <div style="display:flex; gap:10px; align-items:center;">
                    <label class="muted" for="capLogBranch" style="font-weight:800;">Branch ID</label>
                    <input id="capLogBranch" type="number" min="0" step="1" style="padding:10px 12px;border-radius:12px;border:1px solid rgba(0,0,0,0.12);width:140px;" placeholder="0 = all" />
                    <button class="btn-outline" type="button" id="capLogsRefresh"><i class="fas fa-rotate"></i></button>
                </div>
            </div>
            <div class="list" id="capLogsList">
                <div class="row"><div class="muted">Loading…</div></div>
            </div>
        </div>
    </div>
</main>

<script src="js/admin.js"></script>
<script>
(function(){
    const qs = (s, r=document) => r.querySelector(s);
    const fmt = (n) => {
        const num = Number(n);
        if (!Number.isFinite(num)) return '0';
        return num.toLocaleString(undefined, { maximumFractionDigits: 0 });
    };
    const pctBadge = (pct) => {
        const p = Number(pct);
        const safe = Number.isFinite(p) ? p : 0;
        const cls = safe >= 90 ? 'badge-bad' : (safe >= 75 ? 'badge-warn' : 'badge-good');
        return `<span class="badge-pct ${cls}">${safe.toFixed(1)}%</span>`;
    };

    async function getJson(url){
        const res = await fetch(url, { cache: 'no-store', headers: { 'Accept': 'application/json' } });
        const json = await res.json().catch(() => null);
        if (!res.ok) throw new Error(json?.error || 'Request failed');
        return json;
    }

    async function loadCapacities(){
        const regionId = parseInt(qs('#capRegionSel')?.value || '0', 10) || 0;
        const u = new URL('php_helper/api.php', window.location.href);
        u.searchParams.set('action', 'adminListBranchCapacities');
        if (regionId > 0) u.searchParams.set('region_id', String(regionId));
        const resp = await getJson(u.toString());
        const list = Array.isArray(resp.data) ? resp.data : [];

        let totalCap = 0, totalInv = 0;
        list.forEach(r => { totalCap += Number(r.warehouse_capacity||0); totalInv += Number(r.inventory||0); });
        const pct = totalCap > 0 ? (totalInv/totalCap)*100 : 0;

        qs('#kpiBranches').textContent = String(list.length);
        qs('#kpiCap').textContent = fmt(totalCap);
        qs('#kpiInv').textContent = fmt(totalInv);
        qs('#kpiPct').textContent = `${pct.toFixed(1)}%`;

        const body = qs('#capTableBody');
        if (!body) return;
        if (!list.length){
            body.innerHTML = `<tr><td colspan="6" class="muted">No branches found.</td></tr>`;
            return;
        }
        body.innerHTML = list.map(r => {
            return `<tr>
                <td>${String(r.region_name||'')}</td>
                <td>${String(r.branch_name||'')} <span class="muted">(#${r.branch_id})</span></td>
                <td>${fmt(r.warehouse_capacity)}</td>
                <td>${fmt(r.inventory)}</td>
                <td>${fmt(r.available)}</td>
                <td>${pctBadge(r.percent)}</td>
            </tr>`;
        }).join('');
    }

    async function loadLogs(){
        const branchId = parseInt(qs('#capLogBranch')?.value || '0', 10) || 0;
        const u = new URL('php_helper/api.php', window.location.href);
        u.searchParams.set('action', 'adminListCapacityChangeLogs');
        u.searchParams.set('limit', '200');
        if (branchId > 0) u.searchParams.set('branch_id', String(branchId));
        const resp = await getJson(u.toString());
        const list = Array.isArray(resp.data) ? resp.data : [];
        const host = qs('#capLogsList');
        if (!host) return;
        if (!list.length){
            host.innerHTML = `<div class="row"><div class="muted">No capacity changes logged yet.</div></div>`;
            return;
        }
        host.innerHTML = list.map(x => {
            const when = String(x.changed_at || '');
            const branch = `${String(x.branch_name||'')} (#${x.branch_id})`;
            const region = String(x.region_name||'');
            const who = `${String(x.changed_by||'Unknown')} (${String(x.changed_by_role||'')||'User'})`;
            const delta = `Cap ${fmt(x.old_warehouse_capacity)} → ${fmt(x.new_warehouse_capacity)} | Inv ${fmt(x.old_inventory)} → ${fmt(x.new_inventory)}`;
            const reason = (x.reason || '').trim();
            return `<div class="row">
                <div class="t">${region} • ${branch}</div>
                <div class="s">${when} • ${who} • ${delta}${reason ? ` • Reason: ${reason}` : ''}</div>
            </div>`;
        }).join('');
    }

    qs('#capRefreshBtn')?.addEventListener('click', () => loadCapacities().catch(e => alert(e.message || 'Failed')));
    qs('#capRegionSel')?.addEventListener('change', () => loadCapacities().catch(() => {}));
    qs('#capLogsRefresh')?.addEventListener('click', () => loadLogs().catch(() => {}));

    document.addEventListener('DOMContentLoaded', () => {
        loadCapacities().catch(() => {});
        loadLogs().catch(() => {});
    });
})();
</script>
</body>
</html>
