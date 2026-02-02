<?php
session_start();
require_once 'php_helper/db_config.php';
require_once 'php_helper/print_template.php';

function nfa_col_exists(PDO $pdo, string $table, string $col): bool {
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE ?");
        $stmt->execute([$col]);
        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return false;
    }
}

if (!isset($_SESSION['loggedin']) || ($_SESSION['user_type'] ?? '') !== 'Admin') {
    header('location: login.php');
    exit;
}

$admin_id = (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? 0);
$adminName = trim((string)($_SESSION['username'] ?? 'Admin'));
$adminPosition = 'Admin';
try {
    if ($admin_id > 0) {
        $hasMiddle = nfa_col_exists($pdo, 'users', 'middle_name');
        $hasSuffix = nfa_col_exists($pdo, 'users', 'suffix');
        $hasPosition = nfa_col_exists($pdo, 'users', 'position');

        $cols = ['first_name', 'last_name'];
        if ($hasMiddle) $cols[] = 'middle_name';
        if ($hasSuffix) $cols[] = 'suffix';
        if ($hasPosition) $cols[] = 'position';

        $stmt = $pdo->prepare('SELECT ' . implode(', ', $cols) . ' FROM users WHERE user_id = ? LIMIT 1');
        $stmt->execute([$admin_id]);
        if ($u = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $nameParts = [];
            $first = trim((string)($u['first_name'] ?? ''));
            $middle = trim((string)($u['middle_name'] ?? ''));
            $last = trim((string)($u['last_name'] ?? ''));
            $suffix = trim((string)($u['suffix'] ?? ''));

            if ($first !== '') $nameParts[] = $first;
            if ($middle !== '') $nameParts[] = $middle;
            if ($last !== '') $nameParts[] = $last;

            $n = trim(implode(' ', $nameParts));
            if ($suffix !== '') $n = trim($n . ' ' . $suffix);
            if ($n !== '') $adminName = $n;

            $pos = trim((string)($u['position'] ?? ''));
            if ($pos !== '') $adminPosition = $pos;
        }
    }
} catch (Throwable $e) {
    // best-effort
}

$download = (int)($_GET['download'] ?? 0) === 1;

$q = trim((string)($_GET['q'] ?? ''));
$from = trim((string)($_GET['from'] ?? ''));
$to = trim((string)($_GET['to'] ?? ''));
$region_id = (int)($_GET['region_id'] ?? 0);
$branch_id = (int)($_GET['branch_id'] ?? 0);

$okDate = fn($d) => $d === '' || (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
if (!$okDate($from) || !$okDate($to)) {
    http_response_code(400);
    echo 'Invalid date filter.';
    exit;
}

$branchCtx = null;
if ($branch_id > 0) {
    $branchCtx = nfa_branch_context($pdo, $branch_id);
}

$regionName = '';
$branchName = '';
try {
    if ($region_id > 0) {
        $stmt = $pdo->prepare('SELECT region_name FROM regions WHERE region_id = ? LIMIT 1');
        $stmt->execute([$region_id]);
        $regionName = (string)($stmt->fetchColumn() ?: '');
    }
    if ($branch_id > 0) {
        $stmt = $pdo->prepare('SELECT branch_name FROM branch WHERE branch_id = ? LIMIT 1');
        $stmt->execute([$branch_id]);
        $branchName = (string)($stmt->fetchColumn() ?: '');
    }
} catch (Throwable $e) {
    // ignore
}

$where = ["u.user_type = 'Processor'"];
$params = [];

if ($from !== '') {
    $where[] = 'l.timestamp >= ?';
    $params[] = $from . ' 00:00:00';
}
if ($to !== '') {
    $where[] = 'l.timestamp <= ?';
    $params[] = $to . ' 23:59:59';
}
if ($region_id > 0) {
    $where[] = 'u.region_id = ?';
    $params[] = $region_id;
}
if ($branch_id > 0) {
    $where[] = 'u.branch_id = ?';
    $params[] = $branch_id;
}

$hasDetails = false;
$hasIp = false;
$hasEmployeeId = false;
try {
    $hasDetails = nfa_col_exists($pdo, 'activity_logs', 'details');
    $hasIp = nfa_col_exists($pdo, 'activity_logs', 'ip_address');
    $hasEmployeeId = nfa_col_exists($pdo, 'users', 'employee_id');
} catch (Throwable $e) {
    // ignore
}

if ($q !== '') {
    $like = '%' . $q . '%';
    if ($hasDetails) {
        $where[] = '(l.action LIKE ? OR u.username LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? OR l.details LIKE ?)';
        array_push($params, $like, $like, $like, $like, $like);
    } else {
        $where[] = '(l.action LIKE ? OR u.username LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ?)';
        array_push($params, $like, $like, $like, $like);
    }
}

$select = [
    'l.log_id',
    'l.action',
    'l.timestamp',
    'u.username',
    'u.first_name',
    'u.last_name',
    'u.region_id',
    'r.region_name',
    'u.branch_id',
    'b.branch_name',
];
if ($hasEmployeeId) $select[] = 'u.employee_id';
if ($hasDetails) $select[] = 'l.details';
if ($hasIp) $select[] = 'l.ip_address';

$maxRows = 800;
$sql = "SELECT " . implode(', ', $select) . "\n"
    . "FROM activity_logs l\n"
    . "JOIN users u ON l.user_id = u.user_id\n"
    . "LEFT JOIN regions r ON u.region_id = r.region_id\n"
    . "LEFT JOIN branch b ON u.branch_id = b.branch_id\n"
    . "WHERE " . implode(' AND ', $where) . "\n"
    . "ORDER BY l.timestamp DESC, l.log_id DESC\n"
    . "LIMIT $maxRows";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Total count for truncation hint
$total = null;
try {
    $sqlCount = "SELECT COUNT(*)\n"
        . "FROM activity_logs l\n"
        . "JOIN users u ON l.user_id = u.user_id\n"
        . "WHERE " . implode(' AND ', $where);
    $stmtCount = $pdo->prepare($sqlCount);
    $stmtCount->execute($params);
    $total = (int)$stmtCount->fetchColumn();
} catch (Throwable $e) {
    $total = null;
}

$truncated = ($total !== null && $total > $maxRows);

$filters = [];
if ($from !== '' || $to !== '') {
    $filters[] = 'Date: ' . ($from !== '' ? $from : 'Any') . ' to ' . ($to !== '' ? $to : 'Any');
}
if ($region_id > 0) {
    $filters[] = 'Region: ' . ($regionName !== '' ? $regionName : ('#' . $region_id));
}
if ($branch_id > 0) {
    $filters[] = 'Branch: ' . ($branchName !== '' ? $branchName : ('#' . $branch_id));
}
if ($q !== '') {
    $filters[] = 'Search: ' . $q;
}
if (!$filters) {
    $filters[] = 'No filters applied';
}

if ($download) {
    $filename = 'Activity_Logs_' . date('Y-m-d') . '.html';
    header('Content-Type: text/html; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('X-Content-Type-Options: nosniff');
}

function nfa_sentence_case(string $s): string {
    $t = trim($s);
    if ($t === '') return '';
    return strtoupper(substr($t, 0, 1)) . substr($t, 1);
}

function nfa_action_type(string $action): string {
    $act = strtolower(trim($action));
    if (strpos($act, 'login') !== false || strpos($act, 'logout') !== false) return 'Login';
    if (strpos($act, 'create') !== false || strpos($act, 'add') !== false || strpos($act, 'new') !== false) return 'Create';
    if (strpos($act, 'update') !== false || strpos($act, 'edit') !== false || strpos($act, 'modify') !== false) return 'Update';
    if (strpos($act, 'delete') !== false || strpos($act, 'remove') !== false || strpos($act, 'archive') !== false) return 'Delete';
    return 'System';
}

?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Activity Logs Print</title>
    <link rel="icon" href="img/PalayPortal_logo.png" type="image/png"/>
    <?php if ($download):
        $css = @file_get_contents(__DIR__ . '/css/print_template.css');
    ?>
        <style><?php echo $css !== false ? $css : ''; ?></style>
    <?php else: ?>
        <link rel="stylesheet" href="css/print_template.css?v=<?php echo urlencode((string)@filemtime(__DIR__ . '/css/print_template.css')); ?>">
    <?php endif; ?>

    <style>
        /* Page-specific table fixes: keep columns readable on A4 */
        .nfa-print .print-table th,
        .nfa-print .print-table td {
            word-break: normal;      /* override print_template.css break-word */
            overflow-wrap: anywhere; /* allow wrapping without letter-stacking */
        }

        .nfa-print .print-table th.col-type,
        .nfa-print .print-table td.col-type {
            white-space: nowrap;
            overflow-wrap: normal;
        }

        .nfa-print .print-table th.col-action,
        .nfa-print .print-table td.col-action {
            white-space: normal;
            line-height: 1.3;
        }
    </style>
</head>
<body class="nfa-print">
    <table class="print-frame" role="presentation" aria-hidden="true">
        <thead>
            <tr>
                <td>
                    <?php nfa_print_header('Activity Logs', $branchCtx, ['hide_doc_title' => true, 'embed_assets' => $download]); ?>
                </td>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>
                    <div class="print-body">
                        <h2 class="doc-title">Processor Activity Logs</h2>

                        <?php if (!$download): ?>
                            <div class="no-print" style="display:flex; gap:10px; justify-content:flex-end; margin-bottom:10px;">
                                <button type="button" onclick="nfaPrintBack()" style="text-decoration:none; border:1px solid #cfd6df; padding:8px 10px; border-radius:8px; color:#111; background:#fff; cursor:pointer;">Back</button>
                                <button type="button" onclick="nfaDownload()" style="border:1px solid #cfd6df; background:#fff; color:#111; padding:8px 10px; border-radius:8px; cursor:pointer;">Download</button>
                                <button type="button" onclick="window.print()" style="border:1px solid #0b6a2b; background:#0b6a2b; color:#fff; padding:8px 10px; border-radius:8px; cursor:pointer;">Print</button>
                            </div>
                        <?php endif; ?>

                        <div class="section">
                            <div class="info-box">
                                <div class="filter-chips">
                                    <?php foreach ($filters as $part): ?>
                                        <span class="filter-chip"><?php echo nfa_escape($part); ?></span>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>

                        <div class="section">
                            <p class="section-title">Log List<?php echo $truncated ? ' (Truncated)' : ''; ?></p>
                            <?php if ($truncated): ?>
                                <p class="muted" style="margin:0 0 8px;">Showing first <?php echo (int)$maxRows; ?> rows<?php echo $total !== null ? ' out of ' . number_format($total) : ''; ?>. Narrow your filters to print a smaller list.</p>
                            <?php endif; ?>

                            <table class="print-table">
                                <colgroup>
                                    <col style="width: 14%;">
                                    <col style="width: 18%;">
                                    <col style="width: 18%;">
                                    <col style="width: 34%;">
                                    <col style="width: 8%;">
                                    <col style="width: 8%;">
                                </colgroup>
                                <thead>
                                    <tr>
                                        <th>Timestamp</th>
                                        <th>Processor</th>
                                        <th>Region / Branch</th>
                                        <th class="col-action">Action</th>
                                        <th class="col-type">Type</th>
                                        <th>Details</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!$rows): ?>
                                        <tr><td colspan="6" class="muted">No logs match your filters.</td></tr>
                                    <?php else: foreach ($rows as $r):
                                        $who = trim((string)($r['first_name'] ?? '') . ' ' . (string)($r['last_name'] ?? ''));
                                        if ($who === '') $who = (string)($r['username'] ?? 'User');
                                        $whereLine = trim((string)($r['region_name'] ?? '') . ' • ' . (string)($r['branch_name'] ?? ''));
                                        $whereLine = trim(trim($whereLine, '• '));
                                        if ($whereLine === '') $whereLine = '—';
                                        $type = nfa_action_type((string)($r['action'] ?? ''));
                                        $details = $hasDetails ? (string)($r['details'] ?? '') : '';
                                    ?>
                                        <tr>
                                            <td><?php echo nfa_escape((string)($r['timestamp'] ?? '')); ?></td>
                                            <td>
                                                <?php echo nfa_escape($who); ?>
                                                <?php if ($hasEmployeeId && !empty($r['employee_id'])): ?>
                                                    <br><span class="muted">Employee ID: <?php echo nfa_escape((string)$r['employee_id']); ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo nfa_escape($whereLine); ?></td>
                                            <td class="col-action"><?php echo nfa_escape((string)($r['action'] ?? '—')); ?></td>
                                            <td class="col-type"><?php echo nfa_escape($type); ?></td>
                                            <td><?php echo nfa_escape($details !== '' ? $details : '—'); ?></td>
                                        </tr>
                                    <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="section">
                            <div class="sig-row" style="grid-template-columns: 1fr; gap: 0;">
                                <div class="sig-block" style="max-width: 360px;">
                                    <div class="sig-caption">Prepared by:</div>
                                    <div class="sig-space"></div>
                                    <div class="sig-line"></div>
                                    <div class="sig-name"><?php echo nfa_escape($adminName); ?></div>
                                    <div class="sig-position"><?php echo nfa_escape($adminPosition); ?></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <?php nfa_print_footer($branchCtx); ?>
                </td>
            </tr>
        </tbody>
    </table>

    <script>
        function nfaBuildUrl(download) {
            const params = new URLSearchParams(window.location.search);
            if (download) params.set('download', '1');
            else params.delete('download');
            return window.location.pathname + '?' + params.toString();
        }

        function nfaDownload() {
            window.location.href = nfaBuildUrl(true);
        }

        function nfaPrintBack() {
            try {
                if (window.opener && !window.opener.closed) {
                    window.opener.focus();
                }
            } catch (e) {}

            try { window.close(); } catch (e) {}

            setTimeout(() => {
                try {
                    if (history.length > 1) history.back();
                    else window.location.href = 'admin_activity_logs.php';
                } catch (e) {
                    window.location.href = 'admin_activity_logs.php';
                }
            }, 120);
        }
    </script>
</body>
</html>
