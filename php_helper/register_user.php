<?php
session_start();
require_once __DIR__ . '/db_config.php';

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
if ($userType === 'Admin') {
    $regionId = null;
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
    $stmt->execute([
        ':first_name' => $firstName,
        ':middle_name' => $middleName !== '' ? $middleName : null,
        ':last_name' => $lastName,
        ':suffix' => $suffix !== '' ? $suffix : null,
        ':employee_id' => $employeeId,
        ':email_address' => $email,
        ':contact_number' => $contact,
        ':gender' => $gender,
        ':username' => $username,
        ':password_hash' => $hash,
        ':user_type' => $userType,
        ':region_id' => $regionId,
        ':branch_id' => $branchId,
        ':status' => 'Pending'
    ]);

    header('Location: ../login.php?registered=1');
    exit;
} catch (PDOException $e) {
    redirect_with_error('Registration failed due to a server error. Please try again later.', $old);
}
