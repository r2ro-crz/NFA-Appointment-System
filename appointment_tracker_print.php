<?php
require_once 'php_helper/db_config.php';
require_once 'php_helper/print_template.php';

$reference_number = strtoupper(preg_replace('/\s+/', '', (string)($_GET['ref'] ?? ($_GET['reference_number'] ?? ''))));
$farmer_id = trim((string)($_GET['farmer_id'] ?? ''));
$email = trim((string)($_GET['email'] ?? ''));

if ($reference_number === '' || !preg_match('/^NFA\d{8}[A-Z0-9]{6}$/', $reference_number)) {
    http_response_code(400);
    echo 'Invalid reference format.';
    exit;
}

if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo 'Invalid email format.';
    exit;
}

$where = ['a.reference_number = :ref'];
$params = [':ref' => $reference_number];

if ($farmer_id !== '') {
    $where[] = 'a.farmer_id = :farmer_id';
    $params[':farmer_id'] = $farmer_id;
}

if ($email !== '') {
    $where[] = 'LOWER(a.email) = LOWER(:email)';
    $params[':email'] = $email;
}

$sql =
    "SELECT\n" .
    "  a.reference_number, a.status, a.`date`, a.time_slot, a.volume,\n" .
    "  a.farmer_id, a.first_name, a.middle_name, a.last_name, a.suffix,\n" .
    "  a.email, a.contact_number,\n" .
    "  b.branch_id, b.branch_name, b.address, b.contact_number AS branch_contact, b.website_link,\n" .
    "  r.region_name\n" .
    "FROM appointments a\n" .
    "JOIN branch b ON b.branch_id = a.branch_id\n" .
    "JOIN regions r ON r.region_id = a.region_id\n" .
    "WHERE " . implode(' AND ', $where) . "\n" .
    "LIMIT 1";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
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

$status = strtolower(trim((string)($row['status'] ?? '')));
$statusLabel = 'Unknown';
switch ($status) {
    case 'pending':
        $statusLabel = 'Pending';
        break;
    case 'confirmed':
        $statusLabel = 'Confirmed';
        break;
    case 'rescheduled':
        $statusLabel = 'Rescheduled';
        break;
    case 'completed':
        $statusLabel = 'Completed';
        break;
    case 'cancelled':
    case 'canceled':
        $statusLabel = 'Cancelled';
        break;
}

$slot = strtoupper(trim((string)($row['time_slot'] ?? '')));
$slotLabel = $slot === 'PM'
    ? 'PM (Afternoon 1:00 PM – 5:00 PM)'
    : 'AM (Morning 8:00 AM – 12:00 NN)';

$farmerName = trim(implode(' ', array_filter([
    (string)($row['first_name'] ?? ''),
    (string)($row['middle_name'] ?? ''),
    (string)($row['last_name'] ?? ''),
    (string)($row['suffix'] ?? ''),
])));

$fmtNum = fn($n) => number_format((float)$n, 0);

?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Appointment Tracker Print</title>
    <link rel="stylesheet" href="css/print_template.css?v=<?php echo urlencode((string)@filemtime(__DIR__ . '/css/print_template.css')); ?>">
</head>
<body class="nfa-print">
    <div class="print-page">
        <?php nfa_print_header('Appointment Status Tracker', $branchCtx, ['generated' => date('Y-m-d H:i')]); ?>

        <div class="print-body">
            <div class="no-print" style="display:flex; gap:10px; justify-content:flex-end; margin-bottom:10px;">
                <a href="appointment_tracker.php?ref=<?php echo urlencode($reference_number); ?>" style="text-decoration:none; border:1px solid #cfd6df; padding:8px 10px; border-radius:8px; color:#111;">Back</a>
                <button onclick="window.print()" style="border:1px solid #0b6a2b; background:#0b6a2b; color:#fff; padding:8px 10px; border-radius:8px; cursor:pointer;">Print</button>
            </div>

            <div class="section">
                <p class="section-title">Appointment Details</p>
                <table class="print-table">
                    <tbody>
                        <tr><th style="width:35%">Reference No.</th><td><?php echo nfa_escape($row['reference_number'] ?? ''); ?></td></tr>
                        <tr><th>Status</th><td><?php echo nfa_escape($statusLabel); ?></td></tr>
                        <tr><th>Date</th><td><?php echo nfa_escape($row['date'] ?? ''); ?></td></tr>
                        <tr><th>Time Slot</th><td><?php echo nfa_escape($slotLabel); ?></td></tr>
                        <tr><th>Volume</th><td><?php echo $fmtNum($row['volume'] ?? 0); ?> bag(s)</td></tr>
                    </tbody>
                </table>
            </div>

            <div class="section">
                <p class="section-title">Farmer Information</p>
                <table class="print-table">
                    <tbody>
                        <tr><th style="width:35%">Name</th><td><?php echo nfa_escape($farmerName !== '' ? $farmerName : '—'); ?></td></tr>
                        <tr><th>Farmer ID</th><td><?php echo nfa_escape($row['farmer_id'] ?? '—'); ?></td></tr>
                        <tr><th>Email</th><td><?php echo nfa_escape($row['email'] ?? '—'); ?></td></tr>
                        <tr><th>Contact No.</th><td><?php echo nfa_escape($row['contact_number'] ?? '—'); ?></td></tr>
                    </tbody>
                </table>
            </div>

            <div class="section">
                <p class="section-title">Branch Location</p>
                <table class="print-table">
                    <tbody>
                        <tr><th style="width:35%">Branch</th><td><?php echo nfa_escape($row['branch_name'] ?? ''); ?></td></tr>
                        <tr><th>Region</th><td><?php echo nfa_escape($row['region_name'] ?? ''); ?></td></tr>
                        <tr><th>Address</th><td><?php echo nfa_escape($row['address'] ?? ''); ?></td></tr>
                    </tbody>
                </table>
                <p class="muted" style="margin:8px 0 0;">
                    Keep this printout for your records. For corrections or questions, contact your branch.
                </p>
            </div>
        </div>

        <?php nfa_print_footer($branchCtx); ?>
    </div>
</body>
</html>
