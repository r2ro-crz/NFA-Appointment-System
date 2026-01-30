<?php
session_start();
require_once __DIR__ . '/php_helper/db_config.php';

// Prevent caching of protected pages and the logout response itself
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
header('Expires: 0');

// Best-effort activity logging
try {
    $userType = (string)($_SESSION['user_type'] ?? '');
    $userId = (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? 0);

    if (($userType === 'Processor' || $userType === 'Admin') && $userId > 0) {
        $stmt = $pdo->prepare('INSERT INTO activity_logs (user_id, action) VALUES (?, ?)');
        $stmt->execute([$userId, 'Logout']);
    }
} catch (Exception $e) {
    // best-effort
}

// Clear session
$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params['path'], $params['domain'], $params['secure'], $params['httponly']
    );
}

session_destroy();

header('Location: login.php');
exit;
