<?php
session_start();
require_once 'php_helper/db_config.php';
require_once 'php_helper/print_template.php';

if (!isset($_SESSION['loggedin']) || ($_SESSION['user_type'] ?? '') !== 'Processor') {
    header('location: login.php');
    exit;
}

$branch_id = (int)($_SESSION['branch_id'] ?? 0);
if ($branch_id <= 0) {
    http_response_code(400);
    echo 'Missing branch context.';
    exit;
}

$user_id = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;
$branchCtx = nfa_branch_context($pdo, $branch_id);
$download = (int)($_GET['download'] ?? 0) === 1;

$buildName = function (array $u): string {
    $first = trim((string)($u['first_name'] ?? ''));
    $middle = trim((string)($u['middle_name'] ?? ''));
    $last = trim((string)($u['last_name'] ?? ''));
    $suffix = trim((string)($u['suffix'] ?? ''));

    $parts = [];
    if ($first !== '') $parts[] = $first;
    if ($middle !== '') $parts[] = $middle;
    if ($last !== '') $parts[] = $last;

    $name = trim(implode(' ', $parts));
    if ($suffix !== '') $name .= ' ' . $suffix;
    return trim($name);
};

$preparedBy = ['name' => '', 'position' => ''];
if ($user_id) {
    $stmt = $pdo->prepare("SELECT first_name, middle_name, last_name, suffix, position FROM users WHERE user_id = ? LIMIT 1");
    $stmt->execute([(int)$user_id]);
    if ($u = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $preparedBy['name'] = $buildName($u);
        $preparedBy['position'] = trim((string)($u['position'] ?? ''));
    }
}

$approvedBy = ['name' => '', 'position' => ''];
// Prefer an approved Admin/Administrator account. If missing, fall back to a likely branch manager.
$stmt = $pdo->prepare(
    "SELECT first_name, middle_name, last_name, suffix, position\n" .
    "FROM users\n" .
    "WHERE status = 'Approved' AND user_type IN ('Admin','Administrator')\n" .
    "ORDER BY user_id ASC\n" .
    "LIMIT 1"
);
$stmt->execute();
if ($u = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $approvedBy['name'] = $buildName($u);
    $approvedBy['position'] = trim((string)($u['position'] ?? ''));
} else {
    $stmt = $pdo->prepare(
        "SELECT first_name, middle_name, last_name, suffix, position\n" .
        "FROM users\n" .
        "WHERE status = 'Approved' AND branch_id = ? AND position LIKE '%Manager%'\n" .
        "ORDER BY user_id ASC\n" .
        "LIMIT 1"
    );
    $stmt->execute([$branch_id]);
    if ($u = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $approvedBy['name'] = $buildName($u);
        $approvedBy['position'] = trim((string)($u['position'] ?? ''));
    }
}

$start_date = trim((string)($_GET['start_date'] ?? ''));
$end_date = trim((string)($_GET['end_date'] ?? ''));
$time_slot = strtoupper(trim((string)($_GET['time_slot'] ?? '')));
$farmer_type_id = (int)($_GET['farmer_type_id'] ?? 0);

$okDate = fn($d) => (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
if (!$okDate($start_date) || !$okDate($end_date) || $start_date > $end_date) {
    http_response_code(400);
    echo 'Invalid date range.';
    exit;
}

$allowedStatuses = ['pending', 'confirmed', 'rescheduled', 'completed', 'cancelled'];
$statusesRaw = strtolower(trim((string)($_GET['statuses'] ?? '')));
$statuses = [];
if ($statusesRaw !== '') {
    foreach (explode(',', $statusesRaw) as $s) {
        $s = strtolower(trim($s));
        if ($s !== '' && in_array($s, $allowedStatuses, true)) {
            $statuses[] = $s;
        }
    }
    $statuses = array_values(array_unique($statuses));
}

$params = [
    ':branch_id' => $branch_id,
    ':start_date' => $start_date,
    ':end_date' => $end_date,
];

$where = 'WHERE a.branch_id = :branch_id AND a.`date` BETWEEN :start_date AND :end_date';
if ($time_slot === 'AM' || $time_slot === 'PM') {
    $where .= ' AND a.time_slot = :time_slot';
    $params[':time_slot'] = $time_slot;
}
if ($farmer_type_id > 0) {
    $where .= ' AND a.farmer_type_id = :farmer_type_id';
    $params[':farmer_type_id'] = $farmer_type_id;
}
if (count($statuses) > 0) {
    $in = [];
    foreach ($statuses as $i => $st) {
        $k = ':st' . $i;
        $in[] = $k;
        $params[$k] = $st;
    }
    $where .= ' AND a.status IN (' . implode(',', $in) . ')';
}

$stmtSummary = $pdo->prepare(
    "SELECT \n" .
    "  COUNT(*) AS total_appointments,\n" .
    "  COALESCE(SUM(a.volume), 0) AS total_volume,\n" .
    "  COALESCE(AVG(a.volume), 0) AS avg_volume,\n" .
    "  SUM(CASE WHEN a.status = 'completed' THEN 1 ELSE 0 END) AS completed_count,\n" .
    "  SUM(CASE WHEN a.status IN ('confirmed','rescheduled') THEN 1 ELSE 0 END) AS confirmed_count,\n" .
    "  SUM(CASE WHEN a.status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled_count\n" .
    "FROM appointments a\n" .
    "$where"
);
$stmtSummary->execute($params);
$summary = $stmtSummary->fetch(PDO::FETCH_ASSOC) ?: [];

$stmtStatus = $pdo->prepare(
    "SELECT a.status, COUNT(*) AS count\n" .
    "FROM appointments a\n" .
    "$where\n" .
    "GROUP BY a.status"
);
$stmtStatus->execute($params);
$statusRows = $stmtStatus->fetchAll(PDO::FETCH_ASSOC);

$maxRows = 800;
$stmtRows = $pdo->prepare(
    "SELECT a.reference_number, a.`date`, a.time_slot, a.first_name, a.last_name, a.volume, a.status, f.type_name\n" .
    "FROM appointments a\n" .
    "LEFT JOIN farmer_type f ON a.farmer_type_id = f.farmer_type_id\n" .
    "$where\n" .
    "ORDER BY a.`date` DESC, FIELD(a.time_slot, 'AM', 'PM'), a.appointment_id DESC\n" .
    "LIMIT $maxRows"
);
$stmtRows->execute($params);
$rows = $stmtRows->fetchAll(PDO::FETCH_ASSOC);

$total = (int)($summary['total_appointments'] ?? 0);
$truncated = ($total > $maxRows);

$filtersLineParts = [];
$filtersLineParts[] = 'Date: ' . $start_date . ' to ' . $end_date;
if ($time_slot === 'AM' || $time_slot === 'PM') $filtersLineParts[] = 'Slot: ' . $time_slot;
if ($farmer_type_id > 0) $filtersLineParts[] = 'Farmer Type ID: ' . $farmer_type_id;
if (count($statuses) > 0) $filtersLineParts[] = 'Status: ' . strtoupper(implode(', ', $statuses));

$fmtInt = fn($n) => number_format((int)$n);
$fmtNum = fn($n) => number_format((float)$n, 0);

if ($download) {
    $filename = 'Branch_Reports_' . $start_date . '_to_' . $end_date . '.html';
    header('Content-Type: text/html; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('X-Content-Type-Options: nosniff');
}

?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Branch Reports</title>
    <?php if ($download):
        $css = @file_get_contents(__DIR__ . '/css/print_template.css');
    ?>
        <style><?php echo $css !== false ? $css : ''; ?></style>
    <?php else: ?>
        <link rel="stylesheet" href="css/print_template.css?v=<?php echo urlencode((string)@filemtime(__DIR__ . '/css/print_template.css')); ?>">
    <?php endif; ?>
</head>
<body class="nfa-print">
    <table class="print-frame" role="presentation" aria-hidden="true">
        <thead>
            <tr>
                <td>
                    <?php nfa_print_header('Branch Reports', $branchCtx, ['hide_doc_title' => true, 'embed_assets' => $download]); ?>
                </td>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>
                    <div class="print-body">
                        <h2 class="doc-title">Branch Reports</h2>

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
                                    <?php foreach ($filtersLineParts as $part): ?>
                                        <span class="filter-chip"><?php echo nfa_escape($part); ?></span>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>

                        <div class="section">
                            <p class="section-title">Summary</p>
                            <div class="kpi-grid">
                                <div class="kpi"><div class="k">Total Appointments</div><div class="v"><?php echo $fmtInt($summary['total_appointments'] ?? 0); ?></div></div>
                                <div class="kpi"><div class="k">Total Volume</div><div class="v"><?php echo $fmtNum($summary['total_volume'] ?? 0); ?> bags</div></div>
                                <div class="kpi"><div class="k">Average Volume</div><div class="v"><?php echo $fmtNum($summary['avg_volume'] ?? 0); ?> bags</div></div>
                                <div class="kpi"><div class="k">Completed</div><div class="v"><?php echo $fmtInt($summary['completed_count'] ?? 0); ?></div></div>
                            </div>
                        </div>

                        <div class="section">
                            <p class="section-title">Status Breakdown</p>
                            <table class="print-table">
                                <thead><tr><th>Status</th><th>Count</th></tr></thead>
                                <tbody>
                                <?php if (!$statusRows): ?>
                                    <tr><td colspan="2" class="muted">No data.</td></tr>
                                <?php else: foreach ($statusRows as $r): ?>
                                    <tr>
                                        <td><?php echo nfa_escape(ucfirst((string)($r['status'] ?? ''))); ?></td>
                                        <td><?php echo $fmtInt($r['count'] ?? 0); ?></td>
                                    </tr>
                                <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="section">
                            <p class="section-title">Appointment List<?php echo $truncated ? ' (Truncated)' : ''; ?></p>
                            <?php if ($truncated): ?>
                                <p class="muted" style="margin:0 0 8px;">Showing first <?php echo (int)$maxRows; ?> rows out of <?php echo $fmtInt($total); ?>. Narrow the filter range to print a smaller list.</p>
                            <?php endif; ?>

                            <table class="print-table">
                                <thead>
                                    <tr>
                                        <th>Reference No</th>
                                        <th>Date</th>
                                        <th>Slot</th>
                                        <th>Farmer</th>
                                        <th>Type</th>
                                        <th>Volume</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php if (!$rows): ?>
                                    <tr><td colspan="7" class="muted">No appointments found for the selected filters.</td></tr>
                                <?php else: foreach ($rows as $row): ?>
                                    <tr>
                                        <td><?php echo nfa_escape($row['reference_number'] ?? ''); ?></td>
                                        <td><?php echo nfa_escape($row['date'] ?? ''); ?></td>
                                        <td><?php echo nfa_escape(strtoupper((string)($row['time_slot'] ?? ''))); ?></td>
                                        <td><?php echo nfa_escape(trim((string)($row['first_name'] ?? '') . ' ' . (string)($row['last_name'] ?? ''))); ?></td>
                                        <td><?php echo nfa_escape($row['type_name'] ?? ''); ?></td>
                                        <td><?php echo $fmtNum($row['volume'] ?? 0); ?></td>
                                        <td><?php echo nfa_escape(ucfirst((string)($row['status'] ?? ''))); ?></td>
                                    </tr>
                                <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="section">
                            <div class="sig-row sig-row--deep">
                                <div class="sig-block">
                                    <div class="sig-caption">Prepared by:</div>
                                    <div class="sig-space"></div>
                                    <div class="sig-name"><?php echo nfa_escape($preparedBy['name'] !== '' ? $preparedBy['name'] : ''); ?></div>
                                    <div class="sig-line"></div>
                                    <div class="sig-position"><?php echo nfa_escape($preparedBy['position'] !== '' ? $preparedBy['position'] : ''); ?></div>
                                </div>
                                <div class="sig-block">
                                    <div class="sig-caption">Noted by / Approved by:</div>
                                    <div class="sig-space"></div>
                                    <div class="sig-name"><?php echo nfa_escape($approvedBy['name'] !== '' ? $approvedBy['name'] : ''); ?></div>
                                    <div class="sig-line"></div>
                                    <div class="sig-position"><?php echo nfa_escape($approvedBy['position'] !== '' ? $approvedBy['position'] : ''); ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </td>
            </tr>
        </tbody>
        <tfoot>
            <tr>
                <td>
                    <?php nfa_print_footer($branchCtx); ?>
                </td>
            </tr>
        </tfoot>
    </table>

    <?php if (!$download): ?>
        <script>
            function nfaPrintBack() {
                try {
                    window.close();
                    setTimeout(function () {
                        if (!document.hidden) {
                            window.location.href = 'reports.php';
                        }
                    }, 120);
                } catch (e) {
                    window.location.href = 'reports.php';
                }
            }

            function nfaDownload() {
                try {
                    const url = new URL(window.location.href);
                    url.searchParams.set('download', '1');
                    window.location.href = url.toString();
                } catch (e) {
                    window.location.href = 'reports_print.php?download=1';
                }
            }
        </script>
    <?php endif; ?>
</body>
</html>
