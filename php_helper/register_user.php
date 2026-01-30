<?php
session_start();
require_once __DIR__ . '/db_config.php';

require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function nfa_get_smtp_config(): array {
    return [
        'host' => 'smtp.gmail.com',
        'user' => 'anonymous.00112211@gmail.com',
        'pass' => 'xwucrpggtanqrvwp',
        'port' => 587,
        'from_email' => 'no-reply@nfa.gov.ph',
        'from_name' => 'NFA Appointment System'
    ];
}

function nfa_get_portal_root_url(): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

    $script = $_SERVER['SCRIPT_NAME'] ?? '/php_helper/register_user.php';
    $dir = str_replace('\\', '/', dirname($script));
    $rootPath = rtrim(dirname($dir), '/');
    if ($rootPath === '' || $rootPath === '.') $rootPath = '';
    return $scheme . '://' . $host . $rootPath;
}

function nfa_send_html_email_best_effort(string $toEmail, string $subject, string $htmlBody): void {
    try {
        $toEmail = trim($toEmail);
        if ($toEmail === '' || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        $cfg = nfa_get_smtp_config();
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $cfg['host'];
        $mail->SMTPAuth = true;
        $mail->Username = $cfg['user'];
        $mail->Password = $cfg['pass'];
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = (int)$cfg['port'];

        $mail->setFrom($cfg['from_email'], $cfg['from_name']);
        $mail->addAddress($toEmail);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $htmlBody;
        $mail->AltBody = strip_tags(preg_replace('/<br\s*\/?>/i', "\n", $htmlBody));

        $mail->send();
    } catch (Exception $e) {
        error_log('Admin notification email failed: ' . $e->getMessage());
    }
}

function nfa_column_exists(PDO $pdo, string $table, string $column): bool {
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE ?");
        $stmt->execute([$column]);
        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return false;
    }
}

function nfa_notify_admins_new_registration(PDO $pdo, int $newUserId, array $payload): void {
    try {
        $where = ["user_type = 'Admin'", "status = 'Approved'", "email_address IS NOT NULL", "email_address <> ''"];
        if (nfa_column_exists($pdo, 'users', 'is_active')) {
            $where[] = 'COALESCE(is_active, 1) = 1';
        }

        $stmt = $pdo->query(
            'SELECT email_address, first_name, last_name FROM users WHERE ' . implode(' AND ', $where) . ' ORDER BY user_id ASC'
        );
        $admins = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$admins) return;

        $root = nfa_get_portal_root_url();
        $accountsUrl = $root . '/admin_accounts.php?view=' . rawurlencode((string)$newUserId);

        $fullName = trim(implode(' ', array_filter([
            (string)($payload['first_name'] ?? ''),
            (string)($payload['middle_name'] ?? ''),
            (string)($payload['last_name'] ?? ''),
            (string)($payload['suffix'] ?? ''),
        ])));

        $safe = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

        $regionBranch = '';
        if (($payload['user_type'] ?? '') === 'Processor') {
            $regionBranch = trim((string)($payload['region_name'] ?? ''));
            $branchName = trim((string)($payload['branch_name'] ?? ''));
            if ($branchName !== '') {
                $regionBranch = ($regionBranch !== '' ? ($regionBranch . ' • ') : '') . $branchName;
            }
        }

        $subject = 'New Account Request Pending Approval';
        $body = "
            <div style=\"font-family:Arial,Helvetica,sans-serif;color:#111;line-height:1.45\">
                <h2 style=\"margin:0 0 10px 0\">New staff account request</h2>
                <p style=\"margin:0 0 12px 0\">A new account was registered and is awaiting approval.</p>
                <div style=\"border:1px solid #e5e7eb;border-radius:12px;padding:14px;background:#fafafa\">
                    <p style=\"margin:0 0 8px 0\"><strong>Name:</strong> {$safe($fullName)}</p>
                    <p style=\"margin:0 0 8px 0\"><strong>Requested Role:</strong> {$safe($payload['user_type'] ?? '—')}</p>
                    <p style=\"margin:0 0 8px 0\"><strong>Employee ID:</strong> {$safe($payload['employee_id'] ?? '—')}</p>
                    <p style=\"margin:0 0 8px 0\"><strong>Email:</strong> {$safe($payload['email_address'] ?? '—')}</p>
                    <p style=\"margin:0 0 8px 0\"><strong>Contact No.:</strong> {$safe($payload['contact_number'] ?? '—')}</p>
                    <p style=\"margin:0\"><strong>Region / Branch:</strong> {$safe($regionBranch !== '' ? $regionBranch : '—')}</p>
                </div>
                <p style=\"margin:14px 0 0 0\">Review this request here:</p>
                <p style=\"margin:6px 0 0 0\"><a href=\"{$safe($accountsUrl)}\" target=\"_blank\" rel=\"noopener noreferrer\">{$safe($accountsUrl)}</a></p>
                <p style=\"margin:14px 0 0 0;color:#6b7280;font-size:12px\">This is an automated notification from the NFA Appointment System.</p>
            </div>
        ";

        foreach ($admins as $a) {
            $to = (string)($a['email_address'] ?? '');
            nfa_send_html_email_best_effort($to, $subject, $body);
        }
    } catch (Exception $e) {
        error_log('Admin notification (registration) failed: ' . $e->getMessage());
    }
}

function redirect_with_error(string $message, array $old = []): void {
    $_SESSION['flash'] = [
        'register_error' => $message,
        'register_old' => $old
    ];
    header('Location: ../register.php');
    exit;
}

function validate_new_password(string $password): array {
    $errors = [];
    if (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    }
    if (!preg_match('/[A-Z]/', $password)) {
        $errors[] = 'Password must include at least 1 uppercase letter.';
    }
    if (!preg_match('/[a-z]/', $password)) {
        $errors[] = 'Password must include at least 1 lowercase letter.';
    }
    if (!preg_match('/\d/', $password)) {
        $errors[] = 'Password must include at least 1 number.';
    }
    if (!preg_match('/[^A-Za-z0-9]/', $password)) {
        $errors[] = 'Password must include at least 1 special character.';
    }
    return $errors;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../register.php');
    exit;
}

$firstName = trim((string)($_POST['first_name'] ?? ''));
$middleName = trim((string)($_POST['middle_name'] ?? ''));
$lastName = trim((string)($_POST['last_name'] ?? ''));
$suffix = trim((string)($_POST['suffix'] ?? ''));
$employeeId = trim((string)($_POST['employee_id'] ?? ''));
$email = trim((string)($_POST['email_address'] ?? ''));
$contact = trim((string)($_POST['contact_number'] ?? ''));
$gender = trim((string)($_POST['gender'] ?? ''));
$username = trim((string)($_POST['username'] ?? ''));
$userType = trim((string)($_POST['user_type'] ?? ''));
$regionIdRaw = trim((string)($_POST['region_id'] ?? ''));
$branchIdRaw = trim((string)($_POST['branch_id'] ?? ''));

$password = (string)($_POST['password'] ?? '');
$confirm = (string)($_POST['confirm_password'] ?? '');

$old = [
    'first_name' => $firstName,
    'middle_name' => $middleName,
    'last_name' => $lastName,
    'suffix' => $suffix,
    'employee_id' => $employeeId,
    'email_address' => $email,
    'contact_number' => $contact,
    'gender' => $gender,
    'username' => $username,
    'user_type' => $userType,
    'region_id' => $regionIdRaw,
    'branch_id' => $branchIdRaw
];

if ($firstName === '' || $lastName === '' || $employeeId === '' || $email === '' || $contact === '' || $gender === '' || $username === '' || $userType === '' || $password === '' || $confirm === '') {
    redirect_with_error('Please fill in all required fields.', $old);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    redirect_with_error('Please enter a valid email address.', $old);
}

if (preg_match('/\s/', $username)) {
    redirect_with_error('Username must not contain spaces.', $old);
}

$contactDigits = preg_replace('/\D+/', '', $contact);
if (strlen($contactDigits) < 10) {
    redirect_with_error('Please enter a valid contact number.', $old);
}

if ($password !== $confirm) {
    redirect_with_error('Passwords do not match.', $old);
}

$pwErrors = validate_new_password($password);
if (!empty($pwErrors)) {
    redirect_with_error(implode("\n", $pwErrors), $old);
}

$allowedUserTypes = ['Admin', 'Processor'];
if (!in_array($userType, $allowedUserTypes, true)) {
    redirect_with_error('Invalid user type.', $old);
}

$regionId = null;
if ($regionIdRaw !== '') {
    $regionId = (int)$regionIdRaw;
    if ($regionId <= 0) {
        redirect_with_error('Invalid region selection.', $old);
    }
}

$branchId = null;
if ($branchIdRaw !== '') {
    $branchId = (int)$branchIdRaw;
    if ($branchId <= 0) {
        redirect_with_error('Invalid branch selection.', $old);
    }
}

// Admin accounts do not require a branch.
// Some schemas define region_id as NOT NULL; use 0 as the system default.
if ($userType === 'Admin') {
    $regionId = 0;
    $branchId = null;
}

if ($userType === 'Processor' && !$regionId) {
    redirect_with_error('Region is required for Processor accounts.', $old);
}

if ($userType === 'Processor' && !$branchId) {
    redirect_with_error('Branch is required for Processor accounts.', $old);
}

// Ensure the selected branch belongs to the selected region (Processor)
if ($userType === 'Processor' && $regionId && $branchId) {
    $stmt = $pdo->prepare('SELECT region_id FROM branch WHERE branch_id = ? LIMIT 1');
    $stmt->execute([$branchId]);
    $branchRegionId = $stmt->fetchColumn();
    if (!$branchRegionId) {
        redirect_with_error('Invalid branch selection.', $old);
    }
    if ((int)$branchRegionId !== (int)$regionId) {
        redirect_with_error('Selected branch does not belong to the selected region.', $old);
    }
}

try {
    // Uniqueness checks
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username = ?');
    $stmt->execute([$username]);
    if ((int)$stmt->fetchColumn() > 0) {
        redirect_with_error('Username is already taken.', $old);
    }

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE email_address = ?');
    $stmt->execute([$email]);
    if ((int)$stmt->fetchColumn() > 0) {
        redirect_with_error('Email address is already registered.', $old);
    }

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE employee_id = ?');
    $stmt->execute([$employeeId]);
    if ((int)$stmt->fetchColumn() > 0) {
        redirect_with_error('Employee ID is already registered.', $old);
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);

        $sql = 'INSERT INTO users (first_name, middle_name, last_name, suffix, employee_id, email_address, contact_number, gender, username, password_hash, user_type, region_id, branch_id, status)
            VALUES (:first_name, :middle_name, :last_name, :suffix, :employee_id, :email_address, :contact_number, :gender, :username, :password_hash, :user_type, :region_id, :branch_id, :status)';

    $stmt = $pdo->prepare($sql);
    // Some schemas define middle_name/suffix as NOT NULL; use empty string instead of NULL.
    $stmt->execute([
        ':first_name' => $firstName,
        ':middle_name' => $middleName !== '' ? $middleName : '',
        ':last_name' => $lastName,
        ':suffix' => $suffix !== '' ? $suffix : '',
        ':employee_id' => $employeeId,
        ':email_address' => $email,
        ':contact_number' => $contact,
        ':gender' => $gender,
        ':username' => $username,
        ':password_hash' => $hash,
        ':user_type' => $userType,
        ':region_id' => (int)($regionId ?? 0),
        ':branch_id' => $branchId,
        ':status' => 'Pending'
    ]);

    $newUserId = (int)$pdo->lastInsertId();

    // Best-effort admin email notification
    $payload = $old;
    $payload['user_type'] = $userType;

    if ($userType === 'Processor' && $regionId && $branchId) {
        try {
            $stmtLoc = $pdo->prepare(
                'SELECT r.region_name, b.branch_name FROM regions r JOIN branch b ON b.branch_id = ? WHERE r.region_id = ? LIMIT 1'
            );
            $stmtLoc->execute([$branchId, $regionId]);
            if ($loc = $stmtLoc->fetch(PDO::FETCH_ASSOC)) {
                $payload['region_name'] = $loc['region_name'] ?? '';
                $payload['branch_name'] = $loc['branch_name'] ?? '';
            }
        } catch (PDOException $e) {
            // ignore
        }
    }

    nfa_notify_admins_new_registration($pdo, $newUserId, $payload);

    header('Location: ../login.php?registered=1');
    exit;
} catch (PDOException $e) {
    error_log('Registration failed: ' . $e->getMessage());
    redirect_with_error('Registration failed due to a server error. Please try again later.', $old);
}
