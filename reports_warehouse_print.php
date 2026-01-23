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
$token = trim((string)($_GET['token'] ?? ''));

$okDate = fn($d) => (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
if (!$okDate($start_date) || !$okDate($end_date) || $start_date > $end_date) {
    http_response_code(400);
    echo 'Invalid date range.';
    exit;
}

// Warehouse capacity snapshot
$stmtCap = $pdo->prepare('SELECT warehouse_capacity, inventory FROM volume_capacity WHERE branch_id = ?');
$stmtCap->execute([$branch_id]);
$cap = $stmtCap->fetch(PDO::FETCH_ASSOC) ?: ['warehouse_capacity' => 0, 'inventory' => 0];
$warehouse_capacity = (float)($cap['warehouse_capacity'] ?? 0);
$inventory = (float)($cap['inventory'] ?? 0);
$available = max(0, $warehouse_capacity - $inventory);
$percent = $warehouse_capacity > 0 ? ($inventory / $warehouse_capacity) * 100 : 0;

// Completed deliveries within the range (for summary)
$params = [
    ':branch_id' => $branch_id,
    ':start_date' => $start_date,
    ':end_date' => $end_date,
];

$where = "WHERE a.branch_id = :branch_id AND a.status = 'completed' AND a.`date` BETWEEN :start_date AND :end_date";
if ($time_slot === 'AM' || $time_slot === 'PM') {
    $where .= ' AND a.time_slot = :time_slot';
    $params[':time_slot'] = $time_slot;
}
if ($farmer_type_id > 0) {
    $where .= ' AND a.farmer_type_id = :farmer_type_id';
    $params[':farmer_type_id'] = $farmer_type_id;
}

$stmtSum = $pdo->prepare(
    "SELECT COUNT(*) AS completed_count, COALESCE(SUM(a.volume), 0) AS total_volume\n" .
    "FROM appointments a\n" .
    "$where"
);
$stmtSum->execute($params);
$sum = $stmtSum->fetch(PDO::FETCH_ASSOC) ?: [];

$filtersLineParts = [];
$filtersLineParts[] = 'Date: ' . $start_date . ' to ' . $end_date;
if ($time_slot === 'AM' || $time_slot === 'PM') $filtersLineParts[] = 'Slot: ' . $time_slot;
if ($farmer_type_id > 0) $filtersLineParts[] = 'Farmer Type ID: ' . $farmer_type_id;
$filtersLineParts[] = 'Status: COMPLETED';

$fmtInt = fn($n) => number_format((int)$n);
$fmtNum = fn($n) => number_format((float)$n, 0);

?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Warehouse Report</title>
    <link rel="stylesheet" href="css/print_template.css?v=<?php echo urlencode((string)@filemtime(__DIR__ . '/css/print_template.css')); ?>">
    <style>
        /* Warehouse report is intended to fit on a single A4 page. */
        body.nfa-warehouse-print .doc-title{ margin: 0 0 6px; }
        body.nfa-warehouse-print .doc-subtitle{ margin: 6px 0 0; }
        body.nfa-warehouse-print .section{ margin-top: 9px; }
        body.nfa-warehouse-print .section-title{ margin: 0 0 5px; }
        body.nfa-warehouse-print .info-box{ padding: 8px 10px; }
        body.nfa-warehouse-print .filter-chip{ padding: 3px 7px; }
        body.nfa-warehouse-print .kpi-grid{ gap: 6px; }
        body.nfa-warehouse-print .kpi{ padding: 7px 9px; }
        body.nfa-warehouse-print .kpi .v{ font-size: 14px; }

        .chart-grid{ display:grid; grid-template-columns: 1fr; gap: 10px; }
        .chart-frame{ border: 1px solid var(--print-border); border-radius: 10px; padding: 9px; background: #fff; }
        .chart-title{ margin:0 0 8px; font-size: 12px; font-weight: 800; text-transform: uppercase; letter-spacing: .03em; color:#1f2937; }
        .chart-img{ width: 100%; height: auto; display:block; }
        .chart-img--donut{ max-height: 54mm; object-fit: contain; }
        .chart-img--trend{ max-height: 65mm; object-fit: contain; }
        .chart-placeholder{ color: var(--print-muted); font-size: 10.5px; }

        /* Tighten page padding + signature spacing for 1-page output */
        @media print{
            body.nfa-warehouse-print .print-frame .print-body{ padding: 7mm 10mm 6mm; }
            body.nfa-warehouse-print .chart-frame{ page-break-inside: avoid; }
            body.nfa-warehouse-print .sig-row{ margin-top: 10mm; gap: 12mm; }
            body.nfa-warehouse-print .sig-space{ height: 11mm; }
            body.nfa-warehouse-print .sig-name{ font-size: 12px; }
            body.nfa-warehouse-print .sig-position{ font-size: 11px; }
        }
    </style>
</head>
<body class="nfa-print nfa-warehouse-print">
    <table class="print-frame" role="presentation" aria-hidden="true">
        <thead>
            <tr>
                <td>
                    <?php nfa_print_header('Warehouse Report', $branchCtx, ['hide_doc_title' => true]); ?>
                </td>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>
                    <div class="print-body">
                        <h2 class="doc-title">Warehouse Report</h2>

                        <div class="no-print" style="display:flex; gap:10px; justify-content:flex-end; margin-bottom:10px;">
                            <button type="button" onclick="nfaPrintBack()" style="text-decoration:none; border:1px solid #cfd6df; padding:8px 10px; border-radius:8px; color:#111; background:#fff; cursor:pointer;">Back</button>
                            <button type="button" onclick="nfaDownload()" style="border:1px solid #cfd6df; background:#fff; color:#111; padding:8px 10px; border-radius:8px; cursor:pointer;">Download</button>
                            <button type="button" onclick="window.print()" style="border:1px solid #0b6a2b; background:#0b6a2b; color:#fff; padding:8px 10px; border-radius:8px; cursor:pointer;">Print</button>
                        </div>

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
                                <div class="kpi"><div class="k">Inventory</div><div class="v"><?php echo $fmtNum($inventory); ?> bags</div></div>
                                <div class="kpi"><div class="k">Available</div><div class="v"><?php echo $fmtNum($available); ?> bags</div></div>
                                <div class="kpi"><div class="k">Capacity</div><div class="v"><?php echo $fmtNum($warehouse_capacity); ?> bags</div></div>
                                <div class="kpi"><div class="k">Completed Deliveries</div><div class="v"><?php echo $fmtInt($sum['completed_count'] ?? 0); ?></div></div>
                            </div>
                            <div class="doc-subtitle" style="margin-top:8px;">
                                <?php echo nfa_escape('Total volume (completed): ' . $fmtNum($sum['total_volume'] ?? 0) . ' bags • ' . $fmtNum($percent) . '% full'); ?>
                            </div>
                        </div>

                        <div class="section">
                            <p class="section-title">Charts</p>
                            <div class="chart-grid">
                                <div class="chart-frame">
                                    <p class="chart-title">Current Warehouse Status</p>
                                    <img id="imgWarehouseStatus" class="chart-img chart-img--donut" alt="Current Warehouse Status Chart" />
                                    <div id="imgWarehouseStatusFallback" class="chart-placeholder" hidden>Chart unavailable.</div>
                                </div>
                                <div class="chart-frame">
                                    <p class="chart-title">Warehouse Intake Trend</p>
                                    <img id="imgWarehouseTrend" class="chart-img chart-img--trend" alt="Warehouse Intake Trend Chart" />
                                    <div id="imgWarehouseTrendFallback" class="chart-placeholder" hidden>Chart unavailable.</div>
                                    <div id="warehouseTrendNote" class="muted" style="margin-top:8px;"></div>
                                </div>
                            </div>
                        </div>

                        <div class="section">
                            <div class="sig-row">
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

        async function nfaDownload() {
            // Creates a self-contained HTML file (works offline):
            // - inlines print CSS
            // - embeds header logos as data URIs
            // - keeps chart images (data URIs)
            // - removes scripts so it won't revert to "Chart unavailable" offline
            try {
                const filename = 'Warehouse_Report_<?php echo nfa_escape($start_date); ?>_to_<?php echo nfa_escape($end_date); ?>.html';

                // Ensure charts are present (data URIs) before export.
                const imgStatus = document.getElementById('imgWarehouseStatus');
                const imgTrend = document.getElementById('imgWarehouseTrend');
                const statusOk = !!(imgStatus && imgStatus.getAttribute('src') && imgStatus.getAttribute('src').startsWith('data:image'));
                const trendOk = !!(imgTrend && imgTrend.getAttribute('src') && imgTrend.getAttribute('src').startsWith('data:image'));
                if (!statusOk || !trendOk) {
                    if (!statusOk) document.getElementById('imgWarehouseStatusFallback')?.removeAttribute('hidden');
                    if (!trendOk) document.getElementById('imgWarehouseTrendFallback')?.removeAttribute('hidden');
                }

                const clone = document.documentElement.cloneNode(true);

                // Remove scripts (prevents localStorage-based loader from hiding images when opened offline)
                clone.querySelectorAll('script').forEach(s => s.remove());

                // Inline external CSS (print_template.css)
                const link = clone.querySelector('link[rel="stylesheet"][href*="css/print_template.css"]');
                if (link) {
                    try {
                        const href = new URL(link.getAttribute('href') || 'css/print_template.css', window.location.href).toString();
                        const cssText = await fetch(href, { cache: 'no-store' }).then(r => r.ok ? r.text() : '');
                        const style = document.createElement('style');
                        style.textContent = cssText;
                        link.replaceWith(style);
                    } catch (e) {
                        // keep link as-is if fetch fails
                    }
                }

                // Embed all non-data images (logos) as data URIs
                const toDataUrl = async (url) => {
                    const res = await fetch(url, { cache: 'force-cache' });
                    if (!res.ok) return '';
                    const blob = await res.blob();
                    return await new Promise((resolve) => {
                        const reader = new FileReader();
                        reader.onload = () => resolve(String(reader.result || ''));
                        reader.onerror = () => resolve('');
                        reader.readAsDataURL(blob);
                    });
                };

                const imgs = Array.from(clone.querySelectorAll('img'));
                for (const img of imgs) {
                    const src = img.getAttribute('src') || '';
                    if (!src || src.startsWith('data:')) continue;
                    // Chart images are already data URIs in the live DOM; if not, skip.
                    try {
                        const abs = new URL(src, window.location.href).toString();
                        const dataUrl = await toDataUrl(abs);
                        if (dataUrl) img.setAttribute('src', dataUrl);
                    } catch (e) {
                        // ignore
                    }
                }

                // Remove the no-print action row for the downloaded copy
                clone.querySelectorAll('.no-print').forEach(el => el.remove());

                const html = '<!doctype html>' + clone.outerHTML;
                const blob = new Blob([html], { type: 'text/html;charset=utf-8' });
                const a = document.createElement('a');
                a.href = URL.createObjectURL(blob);
                a.download = filename;
                document.body.appendChild(a);
                a.click();
                setTimeout(function () {
                    URL.revokeObjectURL(a.href);
                    a.remove();
                }, 500);
            } catch (e) {
                window.print();
            }
        }

        (function () {
            var token = <?php echo json_encode($token); ?>;
            if (!token) {
                document.getElementById('imgWarehouseStatusFallback').hidden = false;
                document.getElementById('imgWarehouseTrendFallback').hidden = false;
                return;
            }

            var key = 'nfa_warehouse_print_' + token;
            var payload = null;
            try {
                payload = JSON.parse(localStorage.getItem(key) || 'null');
            } catch (e) {
                payload = null;
            }

            function setImg(id, fallbackId, dataUrl) {
                var img = document.getElementById(id);
                var fb = document.getElementById(fallbackId);
                if (!img || !fb) return;
                if (!dataUrl) {
                    fb.hidden = false;
                    img.removeAttribute('src');
                    img.style.display = 'none';
                    return;
                }
                img.src = dataUrl;
                img.style.display = 'block';
                fb.hidden = true;
            }

            setImg('imgWarehouseStatus', 'imgWarehouseStatusFallback', payload && payload.donut);
            setImg('imgWarehouseTrend', 'imgWarehouseTrendFallback', payload && payload.trend);

            var note = document.getElementById('warehouseTrendNote');
            if (note && payload && payload.note) note.textContent = payload.note;

            // Best-effort cleanup to avoid bloating localStorage
            try { localStorage.removeItem(key); } catch (e) {}
        })();
    </script>
</body>
</html>
