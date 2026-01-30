<?php
session_start();

// Include database configuration file
require_once 'db_config.php'; 

require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function is_ajax_request(): bool {
    $requestedWith = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    return strtolower($requestedWith) === 'xmlhttprequest' || str_contains(strtolower($accept), 'application/json');
}

function json_response(array $payload, int $statusCode = 200): void {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload);
    exit;
}

function clear_pending_2fa(): void {
    unset($_SESSION['pending_2fa']);
}

function clear_pending_pw_reset(): void {
    unset($_SESSION['pending_pw_reset']);
}

function send_password_reset_otp_email(string $toEmail, string $toName, string $otp): void {
    $mail = new PHPMailer(true);

    $mail->isSMTP();
    $mail->Host       = 'smtp.gmail.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = 'anonymous.00112211@gmail.com';
    $mail->Password   = 'xwucrpggtanqrvwp';
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = 587;

    $mail->setFrom('no-reply@nfa.gov.ph', 'NFA Appointment System');
    $mail->addAddress($toEmail, $toName);

    $mail->isHTML(true);
    $mail->Subject = 'NFA Password Reset Code';
    $mail->Body = "
        <h3>Password Reset Request</h3>
        <p>We received a request to reset your password for the NFA Appointment System.</p>
        <p>Your 6-digit verification code is:</p>
        <div style=\"font-size: 28px; font-weight: 800; letter-spacing: 6px; padding: 10px 0;\">{$otp}</div>
        <p>This code will expire in <strong>10 minutes</strong>.</p>
        <p>If you did not request a password reset, you can ignore this email.</p>
    ";

    $mail->send();
}

function send_password_changed_email(string $toEmail, string $toName): void {
    $mail = new PHPMailer(true);

    $mail->isSMTP();
    $mail->Host       = 'smtp.gmail.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = 'anonymous.00112211@gmail.com';
    $mail->Password   = 'xwucrpggtanqrvwp';
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = 587;

    $mail->setFrom('no-reply@nfa.gov.ph', 'NFA Appointment System');
    $mail->addAddress($toEmail, $toName);

    $mail->isHTML(true);
    $mail->Subject = 'Your NFA Password Was Changed';
    $mail->Body = "
        <h3>Password Updated</h3>
        <p>Hello {$toName},</p>
        <p>This is a confirmation that your password for the NFA Appointment System was changed.</p>
        <p>If you did not make this change, please contact IT support immediately.</p>
    ";

    $mail->send();
}

function log_activity_best_effort(PDO $pdo, int $userId, string $action): void {
    try {
        if ($userId <= 0) return;
        $action = trim($action);
        if ($action === '') return;
        $stmt = $pdo->prepare('INSERT INTO activity_logs (user_id, action) VALUES (?, ?)');
        $stmt->execute([$userId, $action]);
    } catch (PDOException $e) {
        // best-effort
    }
}

function col_exists(PDO $pdo, string $table, string $col): bool {
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE ?");
        $stmt->execute([$col]);
        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return false;
    }
}

function nfa_get_client_ip(): string {
    $candidates = [
        $_SERVER['HTTP_CLIENT_IP'] ?? '',
        $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '',
        $_SERVER['REMOTE_ADDR'] ?? '',
    ];
    foreach ($candidates as $ip) {
        $ip = trim((string)$ip);
        if ($ip === '') continue;
        // X-Forwarded-For can contain multiple IPs
        if (str_contains($ip, ',')) {
            $ip = trim(explode(',', $ip)[0] ?? '');
        }
        if ($ip !== '') return $ip;
    }
    return '';
}

function nfa_ensure_login_attempts_schema(PDO $pdo): void {
    static $ran = false;
    if ($ran) return;
    $ran = true;

    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS login_attempts (\n" .
            "  attempt_id INT(11) NOT NULL AUTO_INCREMENT,\n" .
            "  occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,\n" .
            "  attempted_username VARCHAR(255) NOT NULL,\n" .
            "  user_id INT(11) NULL,\n" .
            "  user_type VARCHAR(50) NULL,\n" .
            "  account_status VARCHAR(30) NULL,\n" .
            "  is_active TINYINT(1) NULL,\n" .
            "  reason_code VARCHAR(60) NOT NULL,\n" .
            "  ip_address VARCHAR(64) NULL,\n" .
            "  user_agent VARCHAR(255) NULL,\n" .
            "  PRIMARY KEY (attempt_id),\n" .
            "  KEY idx_occurred_at (occurred_at),\n" .
            "  KEY idx_user_id (user_id),\n" .
            "  KEY idx_attempted_username (attempted_username)\n" .
            ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    } catch (PDOException $e) {
        // best-effort
    }
}

function nfa_log_login_attempt_best_effort(PDO $pdo, string $attemptedUsername, ?array $userRow, string $reasonCode): void {
    try {
        $attemptedUsername = trim($attemptedUsername);
        if ($attemptedUsername === '') return;

        nfa_ensure_login_attempts_schema($pdo);

        $userId = $userRow ? (int)($userRow['user_id'] ?? 0) : 0;
        $userType = $userRow ? trim((string)($userRow['user_type'] ?? '')) : '';
        $status = $userRow ? trim((string)($userRow['status'] ?? '')) : '';
        $isActive = $userRow ? (int)($userRow['is_active'] ?? 1) : null;

        $ip = nfa_get_client_ip();
        $ua = trim((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
        if (strlen($ua) > 255) $ua = substr($ua, 0, 255);

        $stmt = $pdo->prepare(
            'INSERT INTO login_attempts (attempted_username, user_id, user_type, account_status, is_active, reason_code, ip_address, user_agent) ' .
            'VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $attemptedUsername,
            $userId > 0 ? $userId : null,
            $userType !== '' ? $userType : null,
            $status !== '' ? $status : null,
            $isActive,
            trim($reasonCode) !== '' ? trim($reasonCode) : 'unknown',
            $ip !== '' ? $ip : null,
            $ua !== '' ? $ua : null,
        ]);
    } catch (PDOException $e) {
        // best-effort
    }
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

function send_otp_email(string $toEmail, string $toName, string $otp): void {
    $mail = new PHPMailer(true);

    // Server settings (same SMTP config used in api.php)
    $mail->isSMTP();
    $mail->Host       = 'smtp.gmail.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = 'anonymous.00112211@gmail.com';
    $mail->Password   = 'xwucrpggtanqrvwp';
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = 587;

    // Recipients
    $mail->setFrom('no-reply@nfa.gov.ph', 'NFA Appointment System');
    $mail->addAddress($toEmail, $toName);

    // Content
    $mail->isHTML(true);
    $mail->Subject = 'Your NFA Login Verification Code';
    $mail->Body = "
        <h3>Login Verification Code</h3>
        <p>You are trying to sign in to the NFA Appointment System.</p>
        <p>Your 6-digit verification code is:</p>
        <div style=\"font-size: 28px; font-weight: 800; letter-spacing: 6px; padding: 10px 0;\">{$otp}</div>
        <p>This code will expire in <strong>5 minutes</strong>.</p>
        <p>If you did not request this code, you can ignore this email.</p>
    ";

    $mail->send();
}

// --- OTP verification / resend / cancel handlers (AJAX) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = (string)$_POST['action'];

    // --- Forgot password flow ---
    if ($action === 'startPasswordReset') {
        $email = trim((string)($_POST['email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            json_response(['success' => false, 'message' => 'Please enter a valid email address.'], 400);
        }

        try {
            $stmt = $pdo->prepare("SELECT user_id, username, email_address FROM users WHERE email_address = ? LIMIT 1");
            $stmt->execute([$email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                json_response(['success' => false, 'message' => 'No account is registered with that email address.'], 404);
            }

            // Rate limit send (30 seconds) per session
            $existing = $_SESSION['pending_pw_reset'] ?? null;
            if ($existing && !empty($existing['sent_at']) && (time() - (int)$existing['sent_at']) < 30) {
                json_response(['success' => false, 'message' => 'Please wait a bit before requesting a new code.'], 429);
            }

            $otp = (string)random_int(100000, 999999);
            $_SESSION['pending_pw_reset'] = [
                'user_id' => (int)$user['user_id'],
                'username' => (string)$user['username'],
                'email_address' => (string)$user['email_address'],
                'otp_hash' => password_hash($otp, PASSWORD_DEFAULT),
                'expires_at' => time() + 600,
                'attempts_left' => 5,
                'sent_at' => time(),
                'stage' => 'otp'
            ];

            send_password_reset_otp_email((string)$user['email_address'], (string)$user['username'], $otp);
            json_response(['success' => true]);
        } catch (Exception $e) {
            clear_pending_pw_reset();
            json_response(['success' => false, 'message' => 'Failed to send reset code. Please contact IT support.'], 500);
        } catch (PDOException $e) {
            clear_pending_pw_reset();
            json_response(['success' => false, 'message' => 'Server error. Please try again later.'], 500);
        }
    }

    if ($action === 'resendPasswordResetOtp') {
        $pending = $_SESSION['pending_pw_reset'] ?? null;
        if (!$pending || empty($pending['email_address']) || empty($pending['username'])) {
            json_response(['success' => false, 'message' => 'Reset session expired. Please try again.'], 401);
        }
        if (($pending['stage'] ?? '') !== 'otp') {
            json_response(['success' => false, 'message' => 'Reset session is not in OTP stage.'], 400);
        }

        $lastSentAt = (int)($pending['sent_at'] ?? 0);
        if ($lastSentAt && (time() - $lastSentAt) < 30) {
            json_response(['success' => false, 'message' => 'Please wait a bit before requesting a new code.'], 429);
        }

        $otp = (string)random_int(100000, 999999);
        $_SESSION['pending_pw_reset']['otp_hash'] = password_hash($otp, PASSWORD_DEFAULT);
        $_SESSION['pending_pw_reset']['expires_at'] = time() + 600;
        $_SESSION['pending_pw_reset']['attempts_left'] = 5;
        $_SESSION['pending_pw_reset']['sent_at'] = time();

        try {
            send_password_reset_otp_email((string)$pending['email_address'], (string)$pending['username'], $otp);
        } catch (Exception $e) {
            json_response(['success' => false, 'message' => 'Failed to send reset code. Please contact IT support.'], 500);
        }

        json_response(['success' => true, 'message' => 'A new code was sent to your email.']);
    }

    if ($action === 'verifyPasswordResetOtp') {
        $otp = preg_replace('/\D+/', '', (string)($_POST['otp'] ?? ''));
        if (strlen($otp) !== 6) {
            json_response(['success' => false, 'message' => 'Please enter the 6-digit code.'], 400);
        }

        $pending = $_SESSION['pending_pw_reset'] ?? null;
        if (!$pending || empty($pending['otp_hash']) || empty($pending['expires_at'])) {
            json_response(['success' => false, 'message' => 'Reset session expired. Please try again.'], 401);
        }
        if (($pending['stage'] ?? '') !== 'otp') {
            json_response(['success' => false, 'message' => 'Reset session is not in OTP stage.'], 400);
        }
        if (time() > (int)$pending['expires_at']) {
            clear_pending_pw_reset();
            json_response(['success' => false, 'message' => 'Code expired. Please try again.'], 401);
        }

        $attemptsLeft = (int)($pending['attempts_left'] ?? 5);
        if ($attemptsLeft <= 0) {
            clear_pending_pw_reset();
            json_response(['success' => false, 'message' => 'Too many invalid attempts. Please try again.'], 429);
        }

        $isValid = password_verify($otp, (string)$pending['otp_hash']);
        if (!$isValid) {
            $_SESSION['pending_pw_reset']['attempts_left'] = $attemptsLeft - 1;
            json_response(['success' => false, 'message' => 'Invalid code. Please try again.'], 401);
        }

        $_SESSION['pending_pw_reset']['stage'] = 'password';
        json_response(['success' => true]);
    }

    if ($action === 'setNewPassword') {
        $password = (string)($_POST['password'] ?? '');
        $confirm = (string)($_POST['confirm'] ?? '');

        $pending = $_SESSION['pending_pw_reset'] ?? null;
        if (!$pending || empty($pending['user_id']) || empty($pending['email_address']) || empty($pending['username'])) {
            json_response(['success' => false, 'message' => 'Reset session expired. Please try again.'], 401);
        }
        if (($pending['stage'] ?? '') !== 'password') {
            json_response(['success' => false, 'message' => 'Please verify the OTP first.'], 400);
        }

        if ($password === '' || $confirm === '') {
            json_response(['success' => false, 'message' => 'Please enter and confirm your new password.'], 400);
        }
        if (!hash_equals($password, $confirm)) {
            json_response(['success' => false, 'message' => 'Passwords do not match.'], 400);
        }

        $pwErrors = validate_new_password($password);
        if (!empty($pwErrors)) {
            json_response(['success' => false, 'message' => implode(' ', $pwErrors)], 400);
        }

        try {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE user_id = ?");
            $stmt->execute([$hash, (int)$pending['user_id']]);

            try {
                send_password_changed_email((string)$pending['email_address'], (string)$pending['username']);
            } catch (Exception $e) {
                // Don't fail reset if notification email fails
            }

            clear_pending_pw_reset();
            json_response(['success' => true]);
        } catch (PDOException $e) {
            json_response(['success' => false, 'message' => 'Failed to update password. Please try again.'], 500);
        }
    }

    if ($action === 'cancelPasswordReset') {
        clear_pending_pw_reset();
        json_response(['success' => true]);
    }

    if ($action === 'verifyOtp') {
        $otp = preg_replace('/\D+/', '', (string)($_POST['otp'] ?? ''));
        if (strlen($otp) !== 6) {
            json_response(['success' => false, 'message' => 'Please enter the 6-digit code.'], 400);
        }

        $pending = $_SESSION['pending_2fa'] ?? null;
        if (!$pending || empty($pending['otp_hash']) || empty($pending['expires_at'])) {
            json_response(['success' => false, 'message' => 'Verification session expired. Please sign in again.'], 401);
        }

        if (time() > (int)$pending['expires_at']) {
            clear_pending_2fa();
            json_response(['success' => false, 'message' => 'Code expired. Please sign in again.'], 401);
        }

        $attemptsLeft = (int)($pending['attempts_left'] ?? 5);
        if ($attemptsLeft <= 0) {
            clear_pending_2fa();
            json_response(['success' => false, 'message' => 'Too many invalid attempts. Please sign in again.'], 429);
        }

        $isValid = password_verify($otp, (string)$pending['otp_hash']);
        if (!$isValid) {
            $_SESSION['pending_2fa']['attempts_left'] = $attemptsLeft - 1;
            json_response(['success' => false, 'message' => 'Invalid code. Please try again.'], 401);
        }

        // Success: finalize authentication
        $_SESSION["loggedin"] = true;
        $_SESSION["id"] = (int)$pending['user_id'];
        $_SESSION["username"] = (string)$pending['username'];
        $_SESSION["user_type"] = (string)$pending['user_type'];
        $_SESSION["branch_id"] = (int)$pending['branch_id'];

        // Record login activity for Processor accounts (Admin monitoring scope)
        try {
            if (($_SESSION['user_type'] ?? '') === 'Processor') {
                $uid = (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? 0);
                log_activity_best_effort($pdo, $uid, 'Login');
            }
        } catch (Exception $e) {
            // best-effort
        }

        clear_pending_2fa();

        // IMPORTANT: Return paths relative to login.php (project root), not relative to php_helper/
        // so the browser stays under /Appointment_System/.
        $redirect = ($_SESSION["user_type"] === 'Admin') ? 'admin_dashboard.php' : 'processor_dashboard.php';
        json_response(['success' => true, 'redirect' => $redirect]);
    }

    if ($action === 'cancelOtp') {
        clear_pending_2fa();
        json_response(['success' => true]);
    }

    if ($action === 'resendOtp') {
        $pending = $_SESSION['pending_2fa'] ?? null;
        if (!$pending || empty($pending['email_address']) || empty($pending['username'])) {
            json_response(['success' => false, 'message' => 'No verification session found. Please sign in again.'], 401);
        }

        // Rate limit resend (30 seconds)
        $lastSentAt = (int)($pending['sent_at'] ?? 0);
        if ($lastSentAt && (time() - $lastSentAt) < 30) {
            json_response(['success' => false, 'message' => 'Please wait a bit before requesting a new code.'], 429);
        }

        $otp = (string)random_int(100000, 999999);
        $_SESSION['pending_2fa']['otp_hash'] = password_hash($otp, PASSWORD_DEFAULT);
        $_SESSION['pending_2fa']['expires_at'] = time() + 300;
        $_SESSION['pending_2fa']['attempts_left'] = 5;
        $_SESSION['pending_2fa']['sent_at'] = time();

        try {
            send_otp_email((string)$pending['email_address'], (string)$pending['username'], $otp);
        } catch (Exception $e) {
            json_response(['success' => false, 'message' => 'Failed to send OTP email. Please contact IT support.'], 500);
        }

        json_response(['success' => true, 'message' => 'A new code was sent to your email.']);
    }

    json_response(['success' => false, 'message' => 'Unknown action.'], 400);
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    
    $username = trim($_POST["username"]);
    $password = $_POST["password"];
    
    // Simple validation check
    if (empty($username) || empty($password)) {
        nfa_log_login_attempt_best_effort($pdo, (string)$username, null, 'missing_fields');
        // redirect back to top-level login.php (authenticate.php is inside php_helper)
        header("Location: ../login.php?error=1"); 
        exit;
    }

    // SQL to fetch user data and the hashed password
    // NOTE: `is_active` may not exist on older schemas.
    $selectIsActive = col_exists($pdo, 'users', 'is_active')
        ? 'COALESCE(is_active, 1) AS is_active'
        : '1 AS is_active';
    $sql = "SELECT user_id, username, password_hash, user_type, branch_id, email_address, status, {$selectIsActive} FROM users WHERE username = :username";
    
    if($stmt = $pdo->prepare($sql)) {
        $stmt->bindParam(":username", $param_username, PDO::PARAM_STR);
        $param_username = $username;
        
        if($stmt->execute()) {
            if($stmt->rowCount() == 1) {
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                $hashed_password = $row['password_hash'];

                // Verify the password against the stored hash
                if (password_verify($password, $hashed_password)) {

                    $status = trim((string)($row['status'] ?? ''));
                    $isActive = (int)($row['is_active'] ?? 1);

                    if (strcasecmp($status, 'Rejected') === 0) {
                        nfa_log_login_attempt_best_effort($pdo, (string)$username, $row, 'rejected_account');
                        header("Location: ../login.php?rejected=1");
                        exit;
                    }

                    if ($isActive === 0) {
                        nfa_log_login_attempt_best_effort($pdo, (string)$username, $row, 'deactivated_account');
                        header("Location: ../login.php?deactivated=1");
                        exit;
                    }

                    if (strcasecmp($status, 'Approved') !== 0) {
                        // Only approved accounts can login
                        nfa_log_login_attempt_best_effort($pdo, (string)$username, $row, 'pending_account');
                        header("Location: ../login.php?pending=1");
                        exit;
                    }

                    $email = trim((string)($row['email_address'] ?? ''));
                    if ($email === '') {
                        // Enforce 2FA only if email exists
                        nfa_log_login_attempt_best_effort($pdo, (string)$username, $row, 'missing_email');
                        header("Location: ../login.php?error=1");
                        exit;
                    }

                    // Generate OTP and store pending login context in session
                    $otp = (string)random_int(100000, 999999);
                    $_SESSION['pending_2fa'] = [
                        'user_id' => (int)$row['user_id'],
                        'username' => (string)$row['username'],
                        'user_type' => (string)$row['user_type'],
                        'branch_id' => (int)$row['branch_id'],
                        'email_address' => $email,
                        'otp_hash' => password_hash($otp, PASSWORD_DEFAULT),
                        'expires_at' => time() + 300,
                        'attempts_left' => 5,
                        'sent_at' => time()
                    ];

                    try {
                        send_otp_email($email, (string)$row['username'], $otp);
                    } catch (Exception $e) {
                        clear_pending_2fa();
                        nfa_log_login_attempt_best_effort($pdo, (string)$username, $row, 'otp_send_failed');
                        header("Location: ../login.php?error=1");
                        exit;
                    }

                    // Send user back to login page to enter OTP (modal popup)
                    header("Location: ../login.php?twofa=1");
                    exit;
                }
            }
        }
    }
    
    // Login failed (Invalid credentials)
    try {
        // Best-effort: if username exists, record the linked user_id for auditing
        $u = null;
        $stmt2 = $pdo->prepare("SELECT user_id, username, user_type, status, {$selectIsActive} FROM users WHERE username = :username LIMIT 1");
        $stmt2->bindParam(":username", $param_username2, PDO::PARAM_STR);
        $param_username2 = $username;
        if ($stmt2->execute() && $stmt2->rowCount() === 1) {
            $u = $stmt2->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        nfa_log_login_attempt_best_effort($pdo, (string)$username, $u, 'invalid_credentials');
    } catch (PDOException $e) {
        // best-effort
    }
    header("Location: ../login.php?error=1");
    exit;

} else {
    // If accessed without POST, redirect to login page
    header("location: ../login.php");
    exit;
}
?>