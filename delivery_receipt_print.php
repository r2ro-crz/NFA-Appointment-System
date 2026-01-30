<?php
session_start();
require_once 'php_helper/db_config.php';
require_once 'php_helper/print_template.php';

if (!isset($_SESSION["loggedin"]) || ($_SESSION["user_type"] ?? '') !== 'Processor') {
    header('location: login.php');
    exit;
}

$appointmentId = (int)($_GET['appointment_id'] ?? 0);
$autoPrint = (int)($_GET['auto'] ?? 0) === 1;

if ($appointmentId <= 0) {
    http_response_code(400);
    echo 'Missing appointment_id.';
    exit;
}

// Processor identity for signatory
$user_id = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;
$processorName = $_SESSION['username'] ?? 'Processor';
$processorTitle = 'Processor';

try {
    if ($user_id) {
        $stmtUser = $pdo->prepare('SELECT first_name, middle_name, last_name, suffix, user_type FROM users WHERE user_id = ? LIMIT 1');
        $stmtUser->execute([(int)$user_id]);
        if ($u = $stmtUser->fetch(PDO::FETCH_ASSOC)) {
            $n = trim(implode(' ', array_filter([
                (string)($u['first_name'] ?? ''),
                (string)($u['middle_name'] ?? ''),
                (string)($u['last_name'] ?? ''),
                (string)($u['suffix'] ?? ''),
            ])));
            if ($n !== '') $processorName = $n;
            $ut = trim((string)($u['user_type'] ?? ''));
            if ($ut !== '') {
                // Map enum roles to nicer on-paper titles
                if (strcasecmp($ut, 'Admin') === 0) {
                    $processorTitle = 'Administrator';
                } elseif (strcasecmp($ut, 'Processor') === 0) {
                    $processorTitle = 'Receiving Officer';
                } else {
                    $processorTitle = $ut;
                }
            }
        }
    }
} catch (Exception $e) {
    // best-effort
}

// Appointment + branch context
$stmt = $pdo->prepare(
    "SELECT\n" .
    "  a.appointment_id, a.reference_number, a.status, a.date_submitted, a.date, a.time_slot, a.volume, a.price,\n" .
    "  a.farmer_id, a.first_name, a.middle_name, a.last_name, a.suffix, a.email, a.contact_number,\n" .
    "  b.branch_id, b.branch_name, b.address, b.contact_number AS branch_contact, b.website_link,\n" .
    "  r.region_name\n" .
    "FROM appointments a\n" .
    "JOIN branch b ON b.branch_id = a.branch_id\n" .
    "JOIN regions r ON r.region_id = a.region_id\n" .
    "WHERE a.appointment_id = ?\n" .
    "LIMIT 1"
);
$stmt->execute([$appointmentId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    http_response_code(404);
    echo 'Appointment not found.';
    exit;
}

$branchCtx = [
    'branch_id' => $row['branch_id'] ?? null,
    'branch_name' => $row['branch_name'] ?? '',
    'region_name' => $row['region_name'] ?? '',
    'address' => $row['address'] ?? '',
    'contact_number' => $row['branch_contact'] ?? '',
    'website_link' => $row['website_link'] ?? '',
];

$farmerName = trim(implode(' ', array_filter([
    (string)($row['first_name'] ?? ''),
    (string)($row['middle_name'] ?? ''),
    (string)($row['last_name'] ?? ''),
    (string)($row['suffix'] ?? ''),
])));

$slot = strtoupper(trim((string)($row['time_slot'] ?? '')));
$sessionWindow = $slot === 'PM' ? '1:00 PM – 5:00 PM' : '8:00 AM – 12:00 NN';
$fmtNum = fn($n) => number_format((float)$n, 0);
$fmtMoney = function ($n) {
    if ($n === null || $n === '') return '—';
    return number_format((float)$n, 2);
};

// Fetch last-known audit events (best-effort)
$submittedAt = $row['date_submitted'] ?? null;
$rescheduledAt = null;
$confirmedAt = null;
$completedAt = null;

try {
    $t = $pdo->query("SHOW TABLES LIKE 'rescheduled_appointments'");
    if ($t && $t->fetchColumn()) {
        $s = $pdo->prepare('SELECT rescheduled_at FROM rescheduled_appointments WHERE appointment_id = ? ORDER BY reschedule_id DESC LIMIT 1');
        $s->execute([$appointmentId]);
        $rescheduledAt = $s->fetchColumn() ?: null;
    }
} catch (Exception $e) {}

try {
    $t = $pdo->query("SHOW TABLES LIKE 'confirmed_appointments'");
    if ($t && $t->fetchColumn()) {
        $s = $pdo->prepare('SELECT confirmed_at FROM confirmed_appointments WHERE appointment_id = ? ORDER BY confirmation_id DESC LIMIT 1');
        $s->execute([$appointmentId]);
        $confirmedAt = $s->fetchColumn() ?: null;
    }
} catch (Exception $e) {}

try {
    $t = $pdo->query("SHOW TABLES LIKE 'completed_appointments'");
    if ($t && $t->fetchColumn()) {
        $s = $pdo->prepare('SELECT completed_at FROM completed_appointments WHERE appointment_id = ? ORDER BY completion_id DESC LIMIT 1');
        $s->execute([$appointmentId]);
        $completedAt = $s->fetchColumn() ?: null;
    }
} catch (Exception $e) {}

$formatTs = function ($ts) {
    if (!$ts) return '—';
    $t = strtotime((string)$ts);
    if (!$t) return (string)$ts;
    return date('F j, Y g:i A', $t);
};

?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Delivery Receipt</title>
    <link rel="stylesheet" href="css/print_template.css?v=<?php echo urlencode((string)@filemtime(__DIR__ . '/css/print_template.css')); ?>">
</head>
<body class="nfa-print">
    <div class="print-page">
        <?php nfa_print_header('Delivery Receipt', $branchCtx, ['generated' => date('Y-m-d H:i')]); ?>

        <div class="print-body">
            <div class="no-print" style="display:flex; gap:10px; justify-content:flex-end; margin-bottom:10px;">
                <button type="button" id="backBtn" style="border:1px solid #cfd6df; background:#fff; color:#111; padding:8px 10px; border-radius:8px; cursor:pointer;">Back</button>
                <button onclick="window.print()" style="border:1px solid #0b6a2b; background:#0b6a2b; color:#fff; padding:8px 10px; border-radius:8px; cursor:pointer;">Print</button>
            </div>

            <div class="section">
                <p class="section-title">Receipt Summary</p>
                <table class="print-table">
                    <tbody>
                        <tr><th style="width:35%">Reference No.</th><td><?php echo nfa_escape($row['reference_number'] ?? ''); ?></td></tr>
                        <tr><th>Status</th><td><strong>Completed</strong> — Delivery recorded successfully.</td></tr>
                        <tr><th>Appointment Schedule</th><td><?php echo nfa_escape($row['date'] ?? ''); ?> • <?php echo nfa_escape($slot . ' (' . $sessionWindow . ')'); ?></td></tr>
                        <tr><th>Volume Delivered</th><td><?php echo $fmtNum($row['volume'] ?? 0); ?> bag(s)</td></tr>
                        <tr><th>Price (Payment)</th><td>₱ <?php echo nfa_escape($fmtMoney($row['price'] ?? null)); ?></td></tr>
                    </tbody>
                </table>
                <p class="muted" style="margin:8px 0 0;">
                    This receipt documents the completed delivery and its processing checkpoints.
                </p>
            </div>

            <div class="section">
                <p class="section-title">Transaction Journey</p>
                <table class="print-table">
                    <tbody>
                        <tr><th style="width:35%">Submitted</th><td><?php echo nfa_escape($formatTs($submittedAt)); ?></td></tr>
                        <tr><th>Rescheduled (if any)</th><td><?php echo nfa_escape($formatTs($rescheduledAt)); ?></td></tr>
                        <tr><th>Confirmed</th><td><?php echo nfa_escape($formatTs($confirmedAt)); ?></td></tr>
                        <tr><th>Completed</th><td><?php echo nfa_escape($formatTs($completedAt)); ?></td></tr>
                    </tbody>
                </table>
                <p class="muted" style="margin:8px 0 0;">
                    Note: Some older records may not have full timestamps if audit logging was enabled later.
                </p>
            </div>

            <div class="section">
                <p class="section-title">Parties</p>
                <table class="print-table">
                    <tbody>
                        <tr><th style="width:35%">Farmer</th><td><?php echo nfa_escape($farmerName !== '' ? $farmerName : '—'); ?> (<?php echo nfa_escape($row['farmer_id'] ?? '—'); ?>)</td></tr>
                        <tr><th>Processor</th><td><?php echo nfa_escape($processorName); ?> (<?php echo nfa_escape($processorTitle); ?>)</td></tr>
                        <tr><th>Branch</th><td><?php echo nfa_escape($row['branch_name'] ?? ''); ?> • <?php echo nfa_escape($row['region_name'] ?? ''); ?></td></tr>
                    </tbody>
                </table>
            </div>

            <div class="section">
                <div class="sig-row sig-row--deep">
                    <div class="sig-block">
                        <div class="sig-name"><?php echo nfa_escape($farmerName !== '' ? $farmerName : '—'); ?></div>
                        <div class="sig-line"></div>
                        <div class="sig-position">Farmer</div>
                    </div>
                    <div class="sig-block">
                        <div class="sig-name"><?php echo nfa_escape($processorName); ?></div>
                        <div class="sig-line"></div>
                        <div class="sig-position"><?php echo nfa_escape($processorTitle); ?></div>
                    </div>
                </div>
            </div>
        </div>

        <?php nfa_print_footer($branchCtx); ?>
    </div>

    <?php if ($autoPrint): ?>
    <script>
        window.addEventListener('load', () => {
            setTimeout(() => { try { window.print(); } catch (e) {} }, 250);
        });
    </script>
    <?php endif; ?>

    <script>
        (function () {
            const backBtn = document.getElementById('backBtn');
            if (!backBtn) return;

            const fallbackUrl = 'appointments.php';

            backBtn.addEventListener('click', () => {
                try {
                    if (window.opener && !window.opener.closed) {
                        try { window.opener.focus(); } catch (e) {}
                    }
                } catch (e) {}

                try { window.close(); } catch (e) {}

                setTimeout(() => {
                    try {
                        if (history.length > 1) {
                            history.back();
                        } else {
                            window.location.href = fallbackUrl;
                        }
                    } catch (e) {
                        window.location.href = fallbackUrl;
                    }
                }, 120);
            });
        })();
    </script>
</body>
</html>
