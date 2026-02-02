<?php
session_start();
require_once __DIR__ . '/../vendor/autoload.php';
require_once 'db_config.php'; 

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type');

function sanitize_input($data) {
    if (is_string($data)) {
        return trim($data);
    }
    return $data;
}

// Read request payload for endpoints that accept JSON or form-urlencoded.
// php://input can be empty depending on server/modules; merge in $_POST as a fallback.
function nfa_request_payload(): array {
    static $cached = null;
    if (is_array($cached)) return $cached;

    $payload = [];

    $raw = @file_get_contents('php://input');
    if (is_string($raw)) {
        $rawTrim = trim($raw);
        if ($rawTrim !== '') {
            $decoded = json_decode($rawTrim, true);
            if (is_array($decoded)) {
                $payload = $decoded;
            }
        }
    }

    if (!empty($_POST) && is_array($_POST)) {
        // Prefer explicit form fields when present
        $payload = array_merge($payload, $_POST);
    }

    $cached = $payload;
    return $payload;
}

function nfa_table_exists(PDO $pdo, string $table): bool {
    try {
        $stmt = $pdo->prepare('SHOW TABLES LIKE ?');
        $stmt->execute([$table]);
        return (bool)$stmt->fetchColumn();
    } catch (PDOException $e) {
        return false;
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

function nfa_ensure_users_admin_schema(PDO $pdo): void {
    static $ran = false;
    if ($ran) return;
    $ran = true;

    // Add basic account lifecycle / notification columns (best-effort)
    try {
        if (nfa_table_exists($pdo, 'users') && !nfa_column_exists($pdo, 'users', 'is_active')) {
            $pdo->exec("ALTER TABLE users ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1");
        }
    } catch (PDOException $e) {
        // best-effort
    }

    try {
        if (nfa_table_exists($pdo, 'users') && !nfa_column_exists($pdo, 'users', 'created_at')) {
            $pdo->exec("ALTER TABLE users ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP");
        }
    } catch (PDOException $e) {
        // best-effort
    }

    try {
        if (nfa_table_exists($pdo, 'users') && !nfa_column_exists($pdo, 'users', 'notif_is_read')) {
            $pdo->exec("ALTER TABLE users ADD COLUMN notif_is_read TINYINT(1) NOT NULL DEFAULT 0");
        }
    } catch (PDOException $e) {
        // best-effort
    }

    try {
        if (nfa_table_exists($pdo, 'users') && !nfa_column_exists($pdo, 'users', 'notif_deleted')) {
            $pdo->exec("ALTER TABLE users ADD COLUMN notif_deleted TINYINT(1) NOT NULL DEFAULT 0");
        }
    } catch (PDOException $e) {
        // best-effort
    }

    // Extend status enum to allow rejection (best-effort)
    try {
        if (nfa_table_exists($pdo, 'users')) {
            $col = $pdo->query("SHOW COLUMNS FROM users LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
            $type = strtolower((string)($col['Type'] ?? ''));
            if ($type !== '' && str_contains($type, "enum(") && !str_contains($type, "'rejected'")) {
                $pdo->exec("ALTER TABLE users MODIFY status ENUM('Pending','Approved','Rejected') NOT NULL DEFAULT 'Pending'");
            }
        }
    } catch (PDOException $e) {
        // best-effort
    }
}

function nfa_log_activity_best_effort(PDO $pdo, int $userId, string $action): void {
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

function nfa_require_role(string $role): void {
    if (!isset($_SESSION['loggedin'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        exit;
    }

    $t = (string)($_SESSION['user_type'] ?? '');
    if ($t !== $role) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Forbidden']);
        exit;
    }
}

function nfa_ensure_branch_appointment_freeze_schema(PDO $pdo): void {
    static $ran = false;
    if ($ran) return;
    $ran = true;

    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS branch_appointment_freeze (\n" .
            "  branch_id INT(11) NOT NULL,\n" .
            "  is_frozen TINYINT(1) NOT NULL DEFAULT 0,\n" .
            "  reason VARCHAR(255) NULL,\n" .
            "  frozen_by INT(11) NULL,\n" .
            "  frozen_at DATETIME NULL,\n" .
            "  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,\n" .
            "  PRIMARY KEY (branch_id),\n" .
            "  KEY idx_is_frozen (is_frozen),\n" .
            "  KEY idx_frozen_at (frozen_at)\n" .
            ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    } catch (PDOException $e) {
        // best-effort
    }
}

function nfa_get_branch_capacity_snapshot(PDO $pdo, int $branchId): array {
    $warehouse_capacity = 0.0;
    $inventory = 0.0;

    try {
        if ($branchId > 0 && nfa_table_exists($pdo, 'volume_capacity')) {
            $stmt = $pdo->prepare('SELECT warehouse_capacity, inventory FROM volume_capacity WHERE branch_id = ? LIMIT 1');
            $stmt->execute([$branchId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($row)) {
                $warehouse_capacity = (float)($row['warehouse_capacity'] ?? 0);
                $inventory = (float)($row['inventory'] ?? 0);
            }
        }
    } catch (PDOException $e) {
        // best-effort
    }

    $percent = ($warehouse_capacity > 0) ? ($inventory / $warehouse_capacity) * 100 : 0;
    $isFull = ($warehouse_capacity > 0) && ($inventory >= $warehouse_capacity);
    return [
        'warehouse_capacity' => $warehouse_capacity,
        'inventory' => $inventory,
        'percent' => $percent,
        'is_full' => $isFull,
    ];
}

function nfa_get_branch_appointment_freeze(PDO $pdo, int $branchId): array {
    nfa_ensure_branch_appointment_freeze_schema($pdo);
    $out = [
        'manual_frozen' => false,
        'manual_reason' => null,
        'manual_frozen_at' => null,
        'manual_frozen_by' => null,
    ];

    if ($branchId <= 0) return $out;
    if (!nfa_table_exists($pdo, 'branch_appointment_freeze')) return $out;

    try {
        $stmt = $pdo->prepare('SELECT is_frozen, reason, frozen_by, frozen_at FROM branch_appointment_freeze WHERE branch_id = ? LIMIT 1');
        $stmt->execute([$branchId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (is_array($row)) {
            $out['manual_frozen'] = ((int)($row['is_frozen'] ?? 0) === 1);
            $out['manual_reason'] = isset($row['reason']) ? (string)$row['reason'] : null;
            $out['manual_frozen_at'] = isset($row['frozen_at']) ? (string)$row['frozen_at'] : null;
            $out['manual_frozen_by'] = isset($row['frozen_by']) ? (int)$row['frozen_by'] : null;
        }
    } catch (PDOException $e) {
        // best-effort
    }

    return $out;
}

function nfa_get_effective_appointment_freeze(PDO $pdo, int $branchId): array {
    $cap = nfa_get_branch_capacity_snapshot($pdo, $branchId);
    $freeze = nfa_get_branch_appointment_freeze($pdo, $branchId);

    $effective = (bool)($freeze['manual_frozen'] ?? false) || (bool)($cap['is_full'] ?? false);
    $source = null;
    if ((bool)($cap['is_full'] ?? false)) {
        $source = 'warehouse_full';
    } elseif ((bool)($freeze['manual_frozen'] ?? false)) {
        $source = 'manual';
    }

    $message = '';
    if ($effective) {
        if ($source === 'warehouse_full') {
            $message = 'Warehouse is 100% full. New appointments are temporarily frozen for this branch.';
        } else {
            $r = trim((string)($freeze['manual_reason'] ?? ''));
            $message = $r !== ''
                ? ('New appointments are temporarily frozen for this branch. Reason: ' . $r)
                : 'New appointments are temporarily frozen for this branch.';
        }
    }

    return [
        'manual_frozen' => (bool)($freeze['manual_frozen'] ?? false),
        'manual_reason' => $freeze['manual_reason'] ?? null,
        'manual_frozen_at' => $freeze['manual_frozen_at'] ?? null,
        'manual_frozen_by' => $freeze['manual_frozen_by'] ?? null,
        'is_full' => (bool)($cap['is_full'] ?? false),
        'warehouse_capacity' => (float)($cap['warehouse_capacity'] ?? 0),
        'inventory' => (float)($cap['inventory'] ?? 0),
        'percent' => (float)($cap['percent'] ?? 0),
        'effective_frozen' => $effective,
        'source' => $source,
        'message' => $message,
    ];
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

function nfa_ensure_status_audit_schema(PDO $pdo): void {
    static $ran = false;
    if ($ran) return;
    $ran = true;

    // 1) Rename legacy table if present
    try {
        if (nfa_table_exists($pdo, 'appointment_cancellations') && !nfa_table_exists($pdo, 'cancelled_appointments')) {
            $pdo->exec('RENAME TABLE appointment_cancellations TO cancelled_appointments');
        }
    } catch (PDOException $e) {
        // best-effort
    }

    // 2) Core appointment columns
    try {
        if (nfa_table_exists($pdo, 'appointments') && !nfa_column_exists($pdo, 'appointments', 'date_submitted')) {
            $pdo->exec("ALTER TABLE appointments ADD COLUMN date_submitted DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP");
        }
    } catch (PDOException $e) {
        // best-effort
    }

    try {
        if (nfa_table_exists($pdo, 'appointments') && !nfa_column_exists($pdo, 'appointments', 'price')) {
            $pdo->exec("ALTER TABLE appointments ADD COLUMN price DECIMAL(10,2) NULL");
        }
    } catch (PDOException $e) {
        // best-effort
    }

    // 3) Audit tables
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS cancelled_appointments (
            cancellation_id INT(11) NOT NULL AUTO_INCREMENT,
            appointment_id INT(11) NOT NULL,
            reference_number VARCHAR(255) NOT NULL,
            reason_code VARCHAR(50) NOT NULL,
            reason_detail TEXT NULL,
            cancelled_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            cancelled_by INT(11) NULL,
            source VARCHAR(30) NULL,
            PRIMARY KEY (cancellation_id),
            KEY idx_cancel_appt (appointment_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    } catch (PDOException $e) {
        // best-effort
    }

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS rescheduled_appointments (
            reschedule_id INT(11) NOT NULL AUTO_INCREMENT,
            appointment_id INT(11) NOT NULL,
            reference_number VARCHAR(255) NOT NULL,
            old_date DATE NULL,
            old_time_slot VARCHAR(10) NULL,
            new_date DATE NOT NULL,
            new_time_slot VARCHAR(10) NOT NULL,
            rescheduled_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            rescheduled_by INT(11) NULL,
            source VARCHAR(30) NULL,
            PRIMARY KEY (reschedule_id),
            KEY idx_resched_appt (appointment_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    } catch (PDOException $e) {
        // best-effort
    }

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS confirmed_appointments (
            confirmation_id INT(11) NOT NULL AUTO_INCREMENT,
            appointment_id INT(11) NOT NULL,
            reference_number VARCHAR(255) NOT NULL,
            confirmed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            confirmed_by INT(11) NULL,
            source VARCHAR(30) NULL,
            PRIMARY KEY (confirmation_id),
            KEY idx_confirm_appt (appointment_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    } catch (PDOException $e) {
        // best-effort
    }

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS completed_appointments (
            completion_id INT(11) NOT NULL AUTO_INCREMENT,
            appointment_id INT(11) NOT NULL,
            reference_number VARCHAR(255) NOT NULL,
            completed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            completed_by INT(11) NULL,
            delivered_volume DOUBLE(10,2) NULL,
            price DECIMAL(10,2) NULL,
            source VARCHAR(30) NULL,
            PRIMARY KEY (completion_id),
            KEY idx_complete_appt (appointment_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    } catch (PDOException $e) {
        // best-effort
    }

    // 4) Seed audit tables from current appointment statuses (idempotent)
    try {
        if (nfa_table_exists($pdo, 'appointments') && nfa_table_exists($pdo, 'confirmed_appointments')) {
            $pdo->exec("INSERT INTO confirmed_appointments (appointment_id, reference_number, confirmed_at, confirmed_by, source)
                SELECT a.appointment_id, a.reference_number, COALESCE(a.date_submitted, NOW()), NULL, 'seed'
                FROM appointments a
                WHERE LOWER(a.status) = 'confirmed'
                  AND NOT EXISTS (SELECT 1 FROM confirmed_appointments c WHERE c.appointment_id = a.appointment_id)");
        }
    } catch (PDOException $e) {
        // best-effort
    }

    try {
        if (nfa_table_exists($pdo, 'appointments') && nfa_table_exists($pdo, 'rescheduled_appointments')) {
            $pdo->exec("INSERT INTO rescheduled_appointments (appointment_id, reference_number, old_date, old_time_slot, new_date, new_time_slot, rescheduled_at, rescheduled_by, source)
                SELECT a.appointment_id, a.reference_number, NULL, NULL, a.date, a.time_slot, COALESCE(a.date_submitted, NOW()), NULL, 'seed'
                FROM appointments a
                WHERE LOWER(a.status) = 'rescheduled'
                  AND NOT EXISTS (SELECT 1 FROM rescheduled_appointments r WHERE r.appointment_id = a.appointment_id)");
        }
    } catch (PDOException $e) {
        // best-effort
    }

    try {
        if (nfa_table_exists($pdo, 'appointments') && nfa_table_exists($pdo, 'completed_appointments')) {
            $pdo->exec("INSERT INTO completed_appointments (appointment_id, reference_number, completed_at, completed_by, delivered_volume, price, source)
                SELECT a.appointment_id, a.reference_number, COALESCE(a.date_submitted, NOW()), NULL, a.volume, a.price, 'seed'
                FROM appointments a
                WHERE LOWER(a.status) = 'completed'
                  AND NOT EXISTS (SELECT 1 FROM completed_appointments c WHERE c.appointment_id = a.appointment_id)");
        }
    } catch (PDOException $e) {
        // best-effort
    }

    try {
        if (nfa_table_exists($pdo, 'appointments') && nfa_table_exists($pdo, 'cancelled_appointments')) {
            $pdo->exec("INSERT INTO cancelled_appointments (appointment_id, reference_number, reason_code, reason_detail, cancelled_at, cancelled_by, source)
                SELECT a.appointment_id, a.reference_number, 'seed', NULL, COALESCE(a.date_submitted, NOW()), NULL, 'seed'
                FROM appointments a
                WHERE LOWER(a.status) IN ('cancelled','canceled')
                  AND NOT EXISTS (SELECT 1 FROM cancelled_appointments c WHERE c.appointment_id = a.appointment_id)");
        }
    } catch (PDOException $e) {
        // best-effort
    }
}

function nfa_random_token(int $bytes = 16): string {
    try {
        return bin2hex(random_bytes(max(8, $bytes)));
    } catch (Exception $e) {
        // Fallback (best-effort)
        return bin2hex(pack('N2', time(), random_int(1, PHP_INT_MAX)));
    }
}

function nfa_support_chat_is_open(?array $chat): bool {
    if (!$chat) return false;
    return strtolower((string)($chat['status'] ?? '')) === 'open';
}

function nfa_ensure_support_chat_schema(PDO $pdo): void {
    static $ran = false;
    if ($ran) return;
    $ran = true;

    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS support_chats (\n" .
            "  chat_id INT(11) NOT NULL AUTO_INCREMENT,\n" .
            "  chat_token VARCHAR(80) NOT NULL,\n" .
            "  origin VARCHAR(20) NOT NULL,\n" .
            "  status VARCHAR(20) NOT NULL DEFAULT 'open',\n" .
            "  region_id INT(11) NULL,\n" .
            "  branch_id INT(11) NULL,\n" .
            "  processor_user_id INT(11) NULL,\n" .
            "  farmer_display_name VARCHAR(120) NULL,\n" .
            "  farmer_contact VARCHAR(160) NULL,\n" .
            "  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,\n" .
            "  last_activity_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,\n" .
            "  closed_at TIMESTAMP NULL DEFAULT NULL,\n" .
            "  closed_by_role VARCHAR(20) NULL,\n" .
            "  closed_reason VARCHAR(30) NULL,\n" .
            "  PRIMARY KEY (chat_id),\n" .
            "  UNIQUE KEY uniq_chat_token (chat_token),\n" .
            "  KEY idx_origin_status (origin, status),\n" .
            "  KEY idx_branch (branch_id),\n" .
            "  KEY idx_last_activity (last_activity_at)\n" .
            ") ENGINE=InnoDB DEFAULT CHARSET=utf8"
        );
    } catch (PDOException $e) {
        error_log('Support chat schema (support_chats) create failed: ' . $e->getMessage());
    }

    // Best-effort column adds (for existing installs)
    try {
        if (nfa_table_exists($pdo, 'support_chats') && !nfa_column_exists($pdo, 'support_chats', 'closed_at')) {
            $pdo->exec("ALTER TABLE support_chats ADD COLUMN closed_at TIMESTAMP NULL DEFAULT NULL");
        }
    } catch (PDOException $e) {
        // best-effort
    }
    try {
        if (nfa_table_exists($pdo, 'support_chats') && !nfa_column_exists($pdo, 'support_chats', 'closed_by_role')) {
            $pdo->exec("ALTER TABLE support_chats ADD COLUMN closed_by_role VARCHAR(20) NULL");
        }
    } catch (PDOException $e) {
        // best-effort
    }
    try {
        if (nfa_table_exists($pdo, 'support_chats') && !nfa_column_exists($pdo, 'support_chats', 'closed_reason')) {
            $pdo->exec("ALTER TABLE support_chats ADD COLUMN closed_reason VARCHAR(30) NULL");
        }
    } catch (PDOException $e) {
        // best-effort
    }

    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS support_chat_messages (\n" .
            "  id INT(11) NOT NULL AUTO_INCREMENT,\n" .
            "  chat_id INT(11) NOT NULL,\n" .
            "  sender_role VARCHAR(20) NOT NULL,\n" .
            "  sender_user_id INT(11) NULL,\n" .
            "  message TEXT NOT NULL,\n" .
            "  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,\n" .
            "  PRIMARY KEY (id),\n" .
            "  KEY idx_chat_id (chat_id),\n" .
            "  KEY idx_created_at (created_at)\n" .
            ") ENGINE=InnoDB DEFAULT CHARSET=utf8"
        );
    } catch (PDOException $e) {
        error_log('Support chat schema (support_chat_messages) create failed: ' . $e->getMessage());
    }
}

function nfa_support_chat_cleanup(PDO $pdo): void {
    // Ephemeral lifecycle:
    // - Auto-close open chats after 3 minutes of inactivity
    // - Delete closed chats after a short grace period
    try {
        if (!nfa_table_exists($pdo, 'support_chats') || !nfa_table_exists($pdo, 'support_chat_messages')) return;

        // 1) Auto-close inactive open chats (3 minutes)
        $stmt = $pdo->query("SELECT chat_id FROM support_chats WHERE status = 'open' AND last_activity_at < (NOW() - INTERVAL 3 MINUTE) LIMIT 200");
        $toClose = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
        if ($toClose && count($toClose) > 0) {
            foreach ($toClose as $cid) {
                $chatId = (int)$cid;
                if ($chatId <= 0) continue;
                // Add a final system message so the farmer can see why it ended.
                $pdo->prepare('INSERT INTO support_chat_messages (chat_id, sender_role, sender_user_id, message) VALUES (?, ?, NULL, ?)')
                    ->execute([$chatId, 'system', 'This chat session has been closed due to inactivity. If you still need assistance, please start a new chat.']);
                $pdo->prepare("UPDATE support_chats SET status='closed', closed_at=NOW(), closed_by_role='system', closed_reason='inactivity', last_activity_at=NOW() WHERE chat_id = ?")
                    ->execute([$chatId]);
            }
        }

        // 2) Delete closed chats after 10 minutes (and their messages)
        $stmt2 = $pdo->query("SELECT chat_id FROM support_chats WHERE status = 'closed' AND last_activity_at < (NOW() - INTERVAL 10 MINUTE) LIMIT 200");
        $closedIds = $stmt2 ? $stmt2->fetchAll(PDO::FETCH_COLUMN) : [];
        if ($closedIds && count($closedIds) > 0) {
            $closedIds = array_values(array_filter(array_map('intval', $closedIds), fn($v) => $v > 0));
            if (count($closedIds) > 0) {
                $in = implode(',', array_fill(0, count($closedIds), '?'));
                $pdo->prepare("DELETE FROM support_chat_messages WHERE chat_id IN ($in)")->execute($closedIds);
                $pdo->prepare("DELETE FROM support_chats WHERE chat_id IN ($in)")->execute($closedIds);
            }
        }
    } catch (PDOException $e) {
        // best-effort
    }
}

function nfa_support_chat_close(PDO $pdo, int $chatId, string $byRole, string $reason, string $systemMessage): void {
    if ($chatId <= 0) return;
    $byRole = strtolower(trim($byRole));
    $reason = strtolower(trim($reason));
    if ($byRole === '') $byRole = 'system';
    if ($reason === '') $reason = 'ended';

    try {
        // Only close if currently open
        $row = nfa_support_chat_fetch_by_id($pdo, $chatId);
        if (!$row || !nfa_support_chat_is_open($row)) return;

        $pdo->prepare('INSERT INTO support_chat_messages (chat_id, sender_role, sender_user_id, message) VALUES (?, ?, NULL, ?)')
            ->execute([$chatId, 'system', $systemMessage]);

        $pdo->prepare("UPDATE support_chats SET status='closed', closed_at=NOW(), closed_by_role=?, closed_reason=?, last_activity_at=NOW() WHERE chat_id = ?")
            ->execute([$byRole, $reason, $chatId]);
    } catch (PDOException $e) {
        // best-effort
    }
}

function nfa_support_chat_require_staff(): void {
    if (!isset($_SESSION['loggedin'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        exit;
    }
    $t = (string)($_SESSION['user_type'] ?? '');
    if ($t !== 'Admin' && $t !== 'Processor') {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Forbidden']);
        exit;
    }
}

function nfa_support_chat_fetch_by_token(PDO $pdo, string $token): ?array {
    $token = trim($token);
    if ($token === '') return null;
    try {
        $stmt = $pdo->prepare('SELECT * FROM support_chats WHERE chat_token = ? LIMIT 1');
        $stmt->execute([$token]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (PDOException $e) {
        return null;
    }
}

function nfa_support_chat_fetch_by_id(PDO $pdo, int $chatId): ?array {
    if ($chatId <= 0) return null;
    try {
        $stmt = $pdo->prepare('SELECT * FROM support_chats WHERE chat_id = ? LIMIT 1');
        $stmt->execute([$chatId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (PDOException $e) {
        return null;
    }
}

function nfa_support_chat_staff_can_access(array $chat, string $userType, int $userId, int $branchId): bool {
    $origin = strtolower((string)($chat['origin'] ?? ''));
    if ($userType === 'Admin') {
        // Admin can read processor-origin chats, and can also help with farmer chats if needed.
        return $origin === 'processor' || $origin === 'farmer';
    }

    // Processor access:
    // - farmer chats for their branch
    // - their own processor->admin chats
    if ($origin === 'farmer') {
        return ((int)($chat['branch_id'] ?? 0)) === $branchId && $branchId > 0;
    }
    if ($origin === 'processor') {
        return ((int)($chat['processor_user_id'] ?? 0)) === $userId && $userId > 0;
    }
    return false;
}

function nfa_ensure_capacity_change_logs_schema(PDO $pdo): void {
    static $ran = false;
    if ($ran) return;
    $ran = true;

    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS capacity_change_logs (\n" .
            "  id INT(11) NOT NULL AUTO_INCREMENT,\n" .
            "  changed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,\n" .
            "  branch_id INT(11) NOT NULL,\n" .
            "  changed_by_user_id INT(11) NULL,\n" .
            "  changed_by_role VARCHAR(20) NULL,\n" .
            "  old_warehouse_capacity DECIMAL(12,2) NOT NULL DEFAULT 0,\n" .
            "  new_warehouse_capacity DECIMAL(12,2) NOT NULL DEFAULT 0,\n" .
            "  old_inventory DECIMAL(12,2) NOT NULL DEFAULT 0,\n" .
            "  new_inventory DECIMAL(12,2) NOT NULL DEFAULT 0,\n" .
            "  reason VARCHAR(255) NULL,\n" .
            "  PRIMARY KEY (id),\n" .
            "  KEY idx_branch_changed_at (branch_id, changed_at),\n" .
            "  KEY idx_changed_by (changed_by_user_id)\n" .
            ") ENGINE=InnoDB DEFAULT CHARSET=utf8"
        );
    } catch (PDOException $e) {
        // best-effort
    }
}

function nfa_log_capacity_change_best_effort(
    PDO $pdo,
    int $branchId,
    float $oldCap,
    float $newCap,
    float $oldInv,
    float $newInv,
    ?int $userId,
    ?string $role,
    ?string $reason
): void {
    try {
        if ($branchId <= 0) return;
        if (!nfa_table_exists($pdo, 'capacity_change_logs')) return;

        $role = is_string($role) ? trim($role) : '';
        $reason = is_string($reason) ? trim($reason) : '';
        if ($reason !== '' && strlen($reason) > 255) $reason = substr($reason, 0, 255);
        if ($role !== '' && strlen($role) > 20) $role = substr($role, 0, 20);

        $stmt = $pdo->prepare(
            'INSERT INTO capacity_change_logs (branch_id, changed_by_user_id, changed_by_role, old_warehouse_capacity, new_warehouse_capacity, old_inventory, new_inventory, reason) ' .
            'VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $branchId,
            ($userId && $userId > 0) ? $userId : null,
            ($role !== '') ? $role : null,
            $oldCap,
            $newCap,
            $oldInv,
            $newInv,
            ($reason !== '') ? $reason : null
        ]);
    } catch (PDOException $e) {
        // best-effort
    }
}

function nfa_get_smtp_config() {
    return [
        'host' => 'smtp.gmail.com',
        'user' => 'anonymous.00112211@gmail.com',
        'pass' => 'xwucrpggtanqrvwp',
        'port' => 587,
        'from_email' => 'no-reply@nfa.gov.ph',
        'from_name' => 'NFA PalayPortal'
    ];
}

function nfa_get_portal_root_url() {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

    $script = $_SERVER['SCRIPT_NAME'] ?? '/php_helper/api.php';
    $dir = str_replace('\\', '/', dirname($script));
    // api.php lives under /php_helper, portal root is its parent
    $rootPath = rtrim(dirname($dir), '/');
    if ($rootPath === '' || $rootPath === '.') $rootPath = '';

    return $scheme . '://' . $host . $rootPath;
}

function nfa_session_window($time_slot) {
    $slot = strtoupper((string)$time_slot);
    return ($slot === 'AM') ? '8:00 AM – 12:00 NN' : '1:00 PM – 5:00 PM';
}

function nfa_format_date_long($ymd) {
    if (!$ymd) return '';
    $ts = strtotime((string)$ymd);
    if (!$ts) return (string)$ymd;
    return date('F j, Y', $ts);
}

function nfa_send_html_email_best_effort($toEmail, $subject, $htmlBody, $attachments = []) {
    try {
        $toEmail = is_string($toEmail) ? trim($toEmail) : '';
        if ($toEmail === '' || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            return ['sent' => false, 'error' => 'Invalid recipient email'];
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

        if (is_array($attachments)) {
            foreach ($attachments as $att) {
                if (!is_array($att)) continue;
                $content = $att['content'] ?? null;
                $filename = $att['filename'] ?? 'attachment.txt';
                $encoding = $att['encoding'] ?? 'base64';
                $type = $att['type'] ?? 'application/octet-stream';
                if (!is_string($content)) continue;
                $mail->addStringAttachment($content, $filename, $encoding, $type);
            }
        }

        $mail->send();
        return ['sent' => true];
    } catch (Exception $e) {
        error_log('Email send failed: ' . $e->getMessage());
        return ['sent' => false, 'error' => $e->getMessage()];
    }
}

function nfa_build_farmer_status_email($title, $farmerName, $reference, $branchName, $dateYmd, $timeSlot, $volumeBags, $extraHtml = '') {
    $root = nfa_get_portal_root_url();
    $refEnc = rawurlencode((string)$reference);
    $trackerUrl = $root . '/appointment_tracker.php?ref=' . $refEnc;

    $farmerNameSafe = htmlspecialchars((string)$farmerName, ENT_QUOTES, 'UTF-8');
    $referenceSafe = htmlspecialchars((string)$reference, ENT_QUOTES, 'UTF-8');
    $branchSafe = htmlspecialchars((string)$branchName, ENT_QUOTES, 'UTF-8');
    $dateSafe = htmlspecialchars(nfa_format_date_long($dateYmd), ENT_QUOTES, 'UTF-8');
    $slotSafe = htmlspecialchars((string)strtoupper((string)$timeSlot), ENT_QUOTES, 'UTF-8');
    $windowSafe = htmlspecialchars(nfa_session_window($timeSlot), ENT_QUOTES, 'UTF-8');
    $volumeSafe = htmlspecialchars(number_format((float)$volumeBags), ENT_QUOTES, 'UTF-8');
    $supportEmail = 'publicaffairs@nfa.gov.ph';

    return "
        <div style=\"font-family:Arial,Helvetica,sans-serif;color:#111;line-height:1.45\">
            <h2 style=\"margin:0 0 10px 0\">{$title}</h2>
            <p style=\"margin:0 0 12px 0\">Good day {$farmerNameSafe},</p>
            <p style=\"margin:0 0 14px 0\">Below is the latest update on your NFA appointment.</p>
            <div style=\"border:1px solid #e5e7eb;border-radius:12px;padding:14px;background:#fafafa\">
                <p style=\"margin:0 0 8px 0\"><strong>Reference No.:</strong> {$referenceSafe}</p>
                <p style=\"margin:0 0 8px 0\"><strong>Branch:</strong> {$branchSafe}</p>
                <p style=\"margin:0 0 8px 0\"><strong>Date:</strong> {$dateSafe}</p>
                <p style=\"margin:0 0 8px 0\"><strong>Session:</strong> {$slotSafe} ({$windowSafe})</p>
                <p style=\"margin:0\"><strong>Volume:</strong> {$volumeSafe} bags</p>
            </div>
            {$extraHtml}
            <p style=\"margin:14px 0 0 0\">You can track your appointment status here:</p>
            <p style=\"margin:6px 0 0 0\"><a href=\"{$trackerUrl}\" target=\"_blank\" rel=\"noopener noreferrer\">{$trackerUrl}</a></p>
            <p style=\"margin:14px 0 0 0;color:#6b7280;font-size:12px\">If you need corrections or assistance, contact your NFA branch or <a href=\"mailto:{$supportEmail}\" target=\"_blank\" rel=\"noopener noreferrer\">{$supportEmail}</a>.</p>
        </div>
    ";
}

// Ensure the PDO connection is available
global $pdo;
if (!isset($pdo)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection failed during initialization.']);
    exit();
}

// Best-effort schema evolution (audit tables + new appointment columns)
try {
    nfa_ensure_status_audit_schema($pdo);
} catch (Exception $e) {
    // don't block API calls
}

// Best-effort schema evolution for Admin functions (users table)
try {
    nfa_ensure_users_admin_schema($pdo);
} catch (Exception $e) {
    // don't block API calls
}

// Best-effort schema for support chat (ephemeral assistance)
try {
    nfa_ensure_support_chat_schema($pdo);
    nfa_support_chat_cleanup($pdo);
} catch (Exception $e) {
    // don't block API calls
}

// Best-effort schema for capacity audit trail
try {
    nfa_ensure_capacity_change_logs_schema($pdo);
} catch (Exception $e) {
    // don't block API calls
}

// --- Main Request Handler ---
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'getChangeToken':
        // Lightweight server-backed change token for auto-refresh across different users/devices.
        // scope=admin|processor
        try {
            if (!isset($_SESSION['loggedin'])) {
                http_response_code(401);
                echo json_encode(['success' => false, 'error' => 'Unauthorized']);
                break;
            }

            $scope = strtolower((string)sanitize_input($_GET['scope'] ?? ''));
            $userType = (string)($_SESSION['user_type'] ?? '');

            if ($scope === 'admin') {
                if ($userType !== 'Admin') {
                    http_response_code(403);
                    echo json_encode(['success' => false, 'error' => 'Forbidden']);
                    break;
                }

                $parts = [];
                // Users table changes
                try {
                    if (nfa_table_exists($pdo, 'users')) {
                        $maxUserId = (int)($pdo->query('SELECT MAX(user_id) FROM users')->fetchColumn() ?: 0);
                        $parts[] = 'u_max:' . $maxUserId;

                        if (nfa_column_exists($pdo, 'users', 'created_at')) {
                            $maxUserCreated = (string)($pdo->query('SELECT MAX(created_at) FROM users')->fetchColumn() ?: '');
                            $parts[] = 'u_mc:' . $maxUserCreated;
                        }
                    }
                } catch (PDOException $e) {
                    // ignore
                }

                // Activity log changes
                try {
                    if (nfa_table_exists($pdo, 'activity_logs')) {
                        $maxLogId = (int)($pdo->query('SELECT MAX(log_id) FROM activity_logs')->fetchColumn() ?: 0);
                        $parts[] = 'al_max:' . $maxLogId;

                        $maxLogTs = (string)($pdo->query('SELECT MAX(timestamp) FROM activity_logs')->fetchColumn() ?: '');
                        $parts[] = 'al_mt:' . $maxLogTs;
                    }
                } catch (PDOException $e) {
                    // ignore
                }

                // Appointment status audit tables (best-effort)
                $auditTables = [
                    ['confirmed_appointments', 'confirmation_id', 'confirmed_at'],
                    ['cancelled_appointments', 'cancellation_id', 'cancelled_at'],
                    ['rescheduled_appointments', 'reschedule_id', 'rescheduled_at'],
                    ['completed_appointments', 'completion_id', 'completed_at'],
                ];

                foreach ($auditTables as $cfg) {
                    [$table, $idCol, $tsCol] = $cfg;
                    try {
                        if (!nfa_table_exists($pdo, $table)) continue;
                        $maxId = (int)($pdo->query("SELECT MAX({$idCol}) FROM {$table}")->fetchColumn() ?: 0);
                        $parts[] = $table . '_max:' . $maxId;
                        if (nfa_column_exists($pdo, $table, $tsCol)) {
                            $maxTs = (string)($pdo->query("SELECT MAX({$tsCol}) FROM {$table}")->fetchColumn() ?: '');
                            $parts[] = $table . '_mt:' . $maxTs;
                        }
                    } catch (PDOException $e) {
                        // ignore
                    }
                }

                // New appointments (pending etc.)
                try {
                    if (nfa_table_exists($pdo, 'appointments')) {
                        $maxApptId = (int)($pdo->query('SELECT MAX(appointment_id) FROM appointments')->fetchColumn() ?: 0);
                        $parts[] = 'a_max:' . $maxApptId;
                        if (nfa_column_exists($pdo, 'appointments', 'date_submitted')) {
                            $maxSubmitted = (string)($pdo->query('SELECT MAX(date_submitted) FROM appointments')->fetchColumn() ?: '');
                            $parts[] = 'a_ms:' . $maxSubmitted;
                        }
                    }
                } catch (PDOException $e) {
                    // ignore
                }

                $raw = implode('|', $parts);
                $token = sha1($raw);
                echo json_encode(['success' => true, 'scope' => 'admin', 'token' => $token, 'ts' => time()]);
                break;
            }

            if ($scope === 'processor') {
                if ($userType !== 'Processor') {
                    http_response_code(403);
                    echo json_encode(['success' => false, 'error' => 'Forbidden']);
                    break;
                }

                $branchId = (int)($_SESSION['branch_id'] ?? 0);
                if ($branchId <= 0) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'error' => 'Missing branch context']);
                    break;
                }

                $parts = ['branch:' . $branchId];

                // Appointments in this branch
                try {
                    if (nfa_table_exists($pdo, 'appointments')) {
                        $stmt = $pdo->prepare('SELECT MAX(appointment_id) FROM appointments WHERE branch_id = ?');
                        $stmt->execute([$branchId]);
                        $maxApptId = (int)($stmt->fetchColumn() ?: 0);
                        $parts[] = 'a_max:' . $maxApptId;

                        if (nfa_column_exists($pdo, 'appointments', 'date_submitted')) {
                            $stmt = $pdo->prepare('SELECT MAX(date_submitted) FROM appointments WHERE branch_id = ?');
                            $stmt->execute([$branchId]);
                            $maxSubmitted = (string)($stmt->fetchColumn() ?: '');
                            $parts[] = 'a_ms:' . $maxSubmitted;
                        }
                    }
                } catch (PDOException $e) {
                    // ignore
                }

                // Audit tables scoped by appointment->branch
                $auditTables = [
                    ['confirmed_appointments', 'confirmation_id', 'confirmed_at'],
                    ['cancelled_appointments', 'cancellation_id', 'cancelled_at'],
                    ['rescheduled_appointments', 'reschedule_id', 'rescheduled_at'],
                    ['completed_appointments', 'completion_id', 'completed_at'],
                ];

                foreach ($auditTables as $cfg) {
                    [$table, $idCol, $tsCol] = $cfg;
                    try {
                        if (!nfa_table_exists($pdo, $table) || !nfa_table_exists($pdo, 'appointments')) continue;

                        $stmt = $pdo->prepare(
                            "SELECT MAX(t.{$idCol}) FROM {$table} t JOIN appointments a ON a.appointment_id = t.appointment_id WHERE a.branch_id = ?"
                        );
                        $stmt->execute([$branchId]);
                        $maxId = (int)($stmt->fetchColumn() ?: 0);
                        $parts[] = $table . '_max:' . $maxId;

                        if (nfa_column_exists($pdo, $table, $tsCol)) {
                            $stmt = $pdo->prepare(
                                "SELECT MAX(t.{$tsCol}) FROM {$table} t JOIN appointments a ON a.appointment_id = t.appointment_id WHERE a.branch_id = ?"
                            );
                            $stmt->execute([$branchId]);
                            $maxTs = (string)($stmt->fetchColumn() ?: '');
                            $parts[] = $table . '_mt:' . $maxTs;
                        }
                    } catch (PDOException $e) {
                        // ignore
                    }
                }

                // Capacity changes for this branch
                try {
                    if (nfa_table_exists($pdo, 'volume_capacity')) {
                        $stmt = $pdo->prepare('SELECT warehouse_capacity, inventory FROM volume_capacity WHERE branch_id = ? LIMIT 1');
                        $stmt->execute([$branchId]);
                        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
                        if ($row) {
                            $parts[] = 'cap:' . (string)($row['warehouse_capacity'] ?? '');
                            $parts[] = 'inv:' . (string)($row['inventory'] ?? '');
                        }
                    }
                } catch (PDOException $e) {
                    // ignore
                }

                $raw = implode('|', $parts);
                $token = sha1($raw);
                echo json_encode(['success' => true, 'scope' => 'processor', 'branch_id' => $branchId, 'token' => $token, 'ts' => time()]);
                break;
            }

            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid scope']);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to compute change token']);
        }
        break;

    case 'generateReferenceNumber':
        // Generate a reference number for preview (final upon submission)
        try {
            $makeRef = function () {
                return 'NFA' . date('Ymd') . strtoupper(substr(md5(uniqid(rand(), true)), 0, 6));
            };

            $reference_number = $makeRef();
            // Ensure it's not already used (best-effort; collisions are extremely unlikely)
            for ($i = 0; $i < 5; $i++) {
                $stmt = $pdo->prepare('SELECT COUNT(*) FROM appointments WHERE reference_number = ?');
                $stmt->execute([$reference_number]);
                $exists = (int)$stmt->fetchColumn() > 0;
                if (!$exists) break;
                $reference_number = $makeRef();
            }

            echo json_encode(['success' => true, 'referenceNumber' => $reference_number]);
        } catch (\PDOException $e) {
            error_log('Reference generation failed: ' . $e->getMessage());
            echo json_encode(['success' => false, 'error' => 'Failed to generate reference number.']);
        }
        break;

    case 'checkUsername':
        // Check if a username already exists (used by registration page)
        try {
            $username = (string)sanitize_input($_GET['username'] ?? '');
            if ($username === '') {
                echo json_encode(['success' => true, 'exists' => false]);
                break;
            }

            $stmt = $pdo->prepare('SELECT 1 FROM users WHERE username = ? LIMIT 1');
            $stmt->execute([$username]);
            $exists = (bool)$stmt->fetchColumn();

            echo json_encode(['success' => true, 'exists' => $exists]);
        } catch (\PDOException $e) {
            error_log('Username check failed: ' . $e->getMessage());
            echo json_encode(['success' => false, 'error' => 'Failed to check username.']);
        }
        break;

    case 'checkEmail':
        // Check if an email is already registered (used by registration page)
        try {
            $email = (string)sanitize_input($_GET['email'] ?? '');
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                echo json_encode(['success' => true, 'exists' => false]);
                break;
            }

            $stmt = $pdo->prepare('SELECT 1 FROM users WHERE email_address = ? LIMIT 1');
            $stmt->execute([$email]);
            $exists = (bool)$stmt->fetchColumn();

            echo json_encode(['success' => true, 'exists' => $exists]);
        } catch (\PDOException $e) {
            error_log('Email check failed: ' . $e->getMessage());
            echo json_encode(['success' => false, 'error' => 'Failed to check email.']);
        }
        break;

    case 'trackAppointment':
        // Public appointment lookup by reference number (optionally verify farmer_id and/or email)
        try {
            $reference_number = (string)sanitize_input($_GET['reference_number'] ?? ($_GET['ref'] ?? ''));
            $reference_number = strtoupper(preg_replace('/\s+/', '', $reference_number));

            $farmer_id = (string)sanitize_input($_GET['farmer_id'] ?? '');
            $email = (string)sanitize_input($_GET['email'] ?? '');

            if ($reference_number === '') {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Missing reference number.']);
                break;
            }

            // Current system format: NFA + YYYYMMDD + 6 alnum
            if (!preg_match('/^NFA\d{8}[A-Z0-9]{6}$/', $reference_number)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Invalid reference format.']);
                break;
            }

            $where = ['a.reference_number = :ref'];
            $params = [':ref' => $reference_number];

            if ($farmer_id !== '') {
                $where[] = 'a.farmer_id = :farmer_id';
                $params[':farmer_id'] = $farmer_id;
            }

            if ($email !== '') {
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'error' => 'Invalid email format.']);
                    break;
                }
                $where[] = 'LOWER(a.email) = LOWER(:email)';
                $params[':email'] = $email;
            }

            $sql = "
                SELECT
                    a.reference_number,
                    a.status,
                    a.date,
                    a.time_slot,
                    a.volume,
                    a.farmer_id,
                    CONCAT_WS(' ', a.first_name, NULLIF(a.middle_name, ''), a.last_name, NULLIF(a.suffix, '')) AS farmer_name,
                    b.branch_name,
                    r.region_name
                FROM appointments a
                INNER JOIN branch b ON b.branch_id = a.branch_id
                INNER JOIN regions r ON r.region_id = a.region_id
                WHERE " . implode(' AND ', $where) . "
                LIMIT 1
            ";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Appointment not found. Please check your reference number.']);
                break;
            }

            echo json_encode([
                'success' => true,
                'data' => [
                    'reference_number' => $row['reference_number'],
                    'status' => strtolower((string)$row['status']),
                    'date' => $row['date'],
                    'time_slot' => $row['time_slot'],
                    'volume' => (float)$row['volume'],
                    'farmer_id' => $row['farmer_id'],
                    'farmer_name' => $row['farmer_name'],
                    'branch_name' => $row['branch_name'],
                    'region_name' => $row['region_name'],
                ]
            ]);
        } catch (\PDOException $e) {
            error_log('trackAppointment failed: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to track appointment.']);
        }
        break;

    case 'verifyTrackerIdentity':
        // Public identity verification for tracker actions (cancel/confirm)
        try {
            $data = json_decode(file_get_contents('php://input'), true);
            if (!is_array($data)) $data = [];

            $reference_number = (string)sanitize_input($data['reference_number'] ?? ($data['ref'] ?? ''));
            $reference_number = strtoupper(preg_replace('/\s+/', '', $reference_number));
            $farmer_id = (string)sanitize_input($data['farmer_id'] ?? '');
            $email = (string)sanitize_input($data['email'] ?? '');

            if ($reference_number === '' || !preg_match('/^NFA\d{8}[A-Z0-9]{6}$/', $reference_number)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Invalid reference number.']);
                break;
            }

            if (trim($farmer_id) === '' && trim($email) === '') {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Please provide Farmer ID or Email for verification.']);
                break;
            }

            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Invalid email format.']);
                break;
            }

            $stmt = $pdo->prepare('SELECT farmer_id, email FROM appointments WHERE reference_number = ? LIMIT 1');
            $stmt->execute([$reference_number]);
            $appt = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$appt) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Appointment not found.']);
                break;
            }

            if (trim($farmer_id) !== '' && (string)$appt['farmer_id'] !== (string)$farmer_id) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Verification failed. Farmer ID does not match this reference.']);
                break;
            }

            if (trim($email) !== '' && strtolower((string)$appt['email']) !== strtolower((string)$email)) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Verification failed. Email does not match this reference.']);
                break;
            }

            echo json_encode(['success' => true]);
        } catch (\PDOException $e) {
            error_log('verifyTrackerIdentity failed: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to verify identity.']);
        }
        break;

    case 'cancelAppointmentByTracker':
        // Farmer-facing cancellation (via public tracker) with reason capture
        try {
            $data = json_decode(file_get_contents('php://input'), true);
            if (!is_array($data)) $data = [];

            $reference_number = (string)sanitize_input($data['reference_number'] ?? '');
            $reference_number = strtoupper(preg_replace('/\s+/', '', $reference_number));
            $farmer_id = (string)sanitize_input($data['farmer_id'] ?? '');
            $email = (string)sanitize_input($data['email'] ?? '');
            $reason_code = (string)sanitize_input($data['reason_code'] ?? '');
            $reason_detail = (string)sanitize_input($data['reason_detail'] ?? '');

            if ($reference_number === '' || !preg_match('/^NFA\d{8}[A-Z0-9]{6}$/', $reference_number)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Invalid reference number.']);
                break;
            }

            // Require at least one identity signal for public actions
            if (trim($farmer_id) === '' && trim($email) === '') {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Please provide Farmer ID or Email for verification.']);
                break;
            }

            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Invalid email format.']);
                break;
            }

            $allowedReasons = ['schedule_conflict', 'no_longer_available', 'wrong_details', 'other'];
            if ($reason_code === '' || !in_array($reason_code, $allowedReasons, true)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Please select a valid cancellation reason.']);
                break;
            }

            if ($reason_code === 'other' && trim($reason_detail) === '') {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Details are required when reason is “Other”.']);
                break;
            }

            // Lookup appointment by reference
            $stmt = $pdo->prepare("SELECT a.appointment_id, a.status, a.email, a.farmer_id, a.first_name, a.last_name, a.suffix, a.date, a.time_slot, a.volume, b.branch_name
                FROM appointments a
                LEFT JOIN branch b ON a.branch_id = b.branch_id
                WHERE a.reference_number = ?
                LIMIT 1");
            $stmt->execute([$reference_number]);
            $appt = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$appt) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Appointment not found.']);
                break;
            }

            // Verify identity matches
            if (trim($farmer_id) !== '' && (string)$appt['farmer_id'] !== (string)$farmer_id) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Verification failed (Farmer ID does not match).']);
                break;
            }
            if (trim($email) !== '' && strtolower((string)$appt['email']) !== strtolower((string)$email)) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Verification failed (Email does not match).']);
                break;
            }

            $status = strtolower((string)($appt['status'] ?? ''));
            if ($status === 'cancelled' || $status === 'canceled') {
                echo json_encode(['success' => true, 'already' => true]);
                break;
            }
            if ($status === 'completed') {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Completed appointments cannot be cancelled.']);
                break;
            }

            // Save cancellation details (best-effort)
            try {
                $stmtIns = $pdo->prepare('INSERT INTO cancelled_appointments (appointment_id, reference_number, reason_code, reason_detail, cancelled_by, source) VALUES (?, ?, ?, ?, ?, ?)');
                $stmtIns->execute([(int)$appt['appointment_id'], $reference_number, $reason_code, $reason_detail, null, 'tracker']);
            } catch (\PDOException $e) {
                // Don't block cancellation if log table isn't available
                error_log('Cancellation log insert failed: ' . $e->getMessage());
            }

            // Update status
            $stmtUp = $pdo->prepare("UPDATE appointments SET status = 'cancelled' WHERE appointment_id = ? AND status NOT IN ('cancelled','completed')");
            $stmtUp->execute([(int)$appt['appointment_id']]);
            if ($stmtUp->rowCount() < 1) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Unable to cancel this appointment.']);
                break;
            }

            // Notify via email (best-effort)
            $emailSent = false;
            if (!empty($appt['email']) && filter_var($appt['email'], FILTER_VALIDATE_EMAIL)) {
                $farmerName = trim(($appt['first_name'] ?? '') . ' ' . ($appt['last_name'] ?? '') . (!empty($appt['suffix']) ? (' ' . $appt['suffix']) : ''));
                $branchName = $appt['branch_name'] ?: 'NFA Branch';
                $reasonExtra = '<p style="margin:14px 0 0 0"><strong>Status:</strong> Cancelled</p>';
                $reasonExtra .= '<p style="margin:8px 0 0 0"><strong>Reason:</strong> ' . htmlspecialchars($reason_code, ENT_QUOTES, 'UTF-8') . '</p>';
                if (trim($reason_detail) !== '') {
                    $reasonExtra .= '<p style="margin:6px 0 0 0"><strong>Details:</strong> ' . nl2br(htmlspecialchars($reason_detail, ENT_QUOTES, 'UTF-8')) . '</p>';
                }

                $body = nfa_build_farmer_status_email(
                    'Appointment Cancelled',
                    $farmerName !== '' ? $farmerName : 'Farmer',
                    $reference_number,
                    $branchName,
                    $appt['date'] ?? '',
                    $appt['time_slot'] ?? '',
                    $appt['volume'] ?? 0,
                    $reasonExtra
                );
                $send = nfa_send_html_email_best_effort($appt['email'], 'NFA Appointment Cancelled: ' . $reference_number, $body);
                $emailSent = !empty($send['sent']);
            }

            echo json_encode(['success' => true, 'email_sent' => $emailSent]);
        } catch (\PDOException $e) {
            error_log('cancelAppointmentByTracker failed: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to cancel appointment.']);
        }
        break;

    case 'confirmRescheduledByTracker':
        // Farmer-facing confirm for rescheduled appointments (via public tracker)
        try {
            $data = json_decode(file_get_contents('php://input'), true);
            if (!is_array($data)) $data = [];

            $reference_number = (string)sanitize_input($data['reference_number'] ?? '');
            $reference_number = strtoupper(preg_replace('/\s+/', '', $reference_number));
            $farmer_id = (string)sanitize_input($data['farmer_id'] ?? '');
            $email = (string)sanitize_input($data['email'] ?? '');

            if ($reference_number === '' || !preg_match('/^NFA\d{8}[A-Z0-9]{6}$/', $reference_number)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Invalid reference number.']);
                break;
            }

            if (trim($farmer_id) === '' && trim($email) === '') {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Please provide Farmer ID or Email for verification.']);
                break;
            }

            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Invalid email format.']);
                break;
            }

            $stmt = $pdo->prepare("SELECT a.appointment_id, a.status, a.email, a.farmer_id, a.first_name, a.last_name, a.suffix, a.date, a.time_slot, a.volume, b.branch_name
                FROM appointments a
                LEFT JOIN branch b ON a.branch_id = b.branch_id
                WHERE a.reference_number = ?
                LIMIT 1");
            $stmt->execute([$reference_number]);
            $appt = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$appt) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Appointment not found.']);
                break;
            }

            if (trim($farmer_id) !== '' && (string)$appt['farmer_id'] !== (string)$farmer_id) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Verification failed (Farmer ID does not match).']);
                break;
            }
            if (trim($email) !== '' && strtolower((string)$appt['email']) !== strtolower((string)$email)) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Verification failed (Email does not match).']);
                break;
            }

            $status = strtolower((string)($appt['status'] ?? ''));
            if ($status !== 'rescheduled') {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Only rescheduled appointments can be confirmed.']);
                break;
            }

            $stmtUp = $pdo->prepare("UPDATE appointments SET status = 'confirmed' WHERE appointment_id = ? AND status = 'rescheduled'");
            $stmtUp->execute([(int)$appt['appointment_id']]);
            if ($stmtUp->rowCount() < 1) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Unable to confirm this appointment.']);
                break;
            }

            // Audit trail (best-effort)
            try {
                $stmtIns = $pdo->prepare('INSERT INTO confirmed_appointments (appointment_id, reference_number, confirmed_by, source) VALUES (?, ?, ?, ?)');
                $stmtIns->execute([(int)$appt['appointment_id'], $reference_number, null, 'tracker']);
            } catch (\PDOException $e) {
                error_log('Confirm audit insert failed: ' . $e->getMessage());
            }

            $emailSent = false;
            if (!empty($appt['email']) && filter_var($appt['email'], FILTER_VALIDATE_EMAIL)) {
                $farmerName = trim(($appt['first_name'] ?? '') . ' ' . ($appt['last_name'] ?? '') . (!empty($appt['suffix']) ? (' ' . $appt['suffix']) : ''));
                $branchName = $appt['branch_name'] ?: 'NFA Branch';
                $extra = '<p style="margin:14px 0 0 0"><strong>Status:</strong> Confirmed</p>';
                $body = nfa_build_farmer_status_email(
                    'Appointment Confirmed',
                    $farmerName !== '' ? $farmerName : 'Farmer',
                    $reference_number,
                    $branchName,
                    $appt['date'] ?? '',
                    $appt['time_slot'] ?? '',
                    $appt['volume'] ?? 0,
                    $extra
                );
                $send = nfa_send_html_email_best_effort($appt['email'], 'NFA Appointment Confirmed: ' . $reference_number, $body);
                $emailSent = !empty($send['sent']);
            }

            echo json_encode(['success' => true, 'email_sent' => $emailSent]);
        } catch (\PDOException $e) {
            error_log('confirmRescheduledByTracker failed: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to confirm appointment.']);
        }
        break;

    case 'getRegions':
        // 1. Get Regions
        try {
            $stmt = $pdo->query("SELECT region_id, region_name FROM regions ORDER BY region_name");
            $regions = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'data' => $regions]);
        } catch (\PDOException $e) {
            error_log("Region fetch failed: " . $e->getMessage());
            echo json_encode(['success' => false, 'error' => 'Failed to retrieve regions.']);
        }
        break;

    case 'getBranches':
        // 2. Get Branches filtered by Region
        $region_id = (int)sanitize_input($_GET['region_id'] ?? 0); 
        if (!$region_id) {
            echo json_encode(['success' => true, 'data' => []]);
            break;
        }
        try {
            $stmt = $pdo->prepare("SELECT branch_id, branch_name FROM branch WHERE region_id = ? ORDER BY branch_name");
            $stmt->execute([$region_id]);
            $branches = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'data' => $branches]);
        } catch (\PDOException $e) {
            error_log("Branch fetch failed: " . $e->getMessage());
            echo json_encode(['success' => false, 'error' => 'Failed to retrieve branches. Please check the `branch` table exists and has data linked to the selected region.']);
        }
        break;

    case 'getBranchInfo':
        // 3. Consolidated fetch for Capacity, Slots, and Availability
    $branch_id = (int)sanitize_input($_GET['branch_id'] ?? 0);
    $start_date = sanitize_input($_GET['start_date'] ?? date('Y-m-01'));
    $end_date = sanitize_input($_GET['end_date'] ?? date('Y-m-t'));

        if (!$branch_id) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Missing branch ID.']);
            exit();
        }

        try {
            error_log("DEBUG: getBranchInfo starting for branch_id=$branch_id");
            
            // A. Fetch Volume Capacity (Q3)
            error_log("DEBUG: Fetching volume capacity");
            $stmt_vol = $pdo->prepare("SELECT warehouse_capacity, inventory FROM volume_capacity WHERE branch_id = :branch_id");
            $stmt_vol->bindParam(':branch_id', $branch_id, PDO::PARAM_INT);
            $stmt_vol->execute();
            $vol_data = $stmt_vol->fetch(PDO::FETCH_ASSOC);
            error_log("DEBUG: Volume data: " . json_encode($vol_data));
            
            $volume_info = [
                'total_capacity' => (float)($vol_data['warehouse_capacity'] ?? 0),
                'inventory' => (float)($vol_data['inventory'] ?? 0),
                'available_volume' => max(0, (float)($vol_data['warehouse_capacity'] ?? 0) - (float)($vol_data['inventory'] ?? 0))
            ];

            // B. Fetch Default Slot Capacity (Used by Q4/Q5 logic)
            error_log("DEBUG: Fetching slot capacity");
            // First try with date IS NULL or empty string for defaults (SQL dump uses '' for default rows)
            $stmt_default_cap = $pdo->prepare("SELECT capacity_am, capacity_pm FROM branch_slot_capacity WHERE branch_id = :branch_id AND (`date` IS NULL OR `date` = '')");
            $stmt_default_cap->bindParam(':branch_id', $branch_id, PDO::PARAM_INT);
            $stmt_default_cap->execute();
            $default_capacity = $stmt_default_cap->fetch(PDO::FETCH_ASSOC);
            
            if (!$default_capacity) {
                // If no default found, try getting the latest capacity for this branch
                error_log("DEBUG: No default capacity found, checking for any capacity entry");
                $stmt_any_cap = $pdo->prepare("SELECT capacity_am, capacity_pm FROM branch_slot_capacity WHERE branch_id = :branch_id ORDER BY capacity_id DESC LIMIT 1");
                $stmt_any_cap->bindParam(':branch_id', $branch_id, PDO::PARAM_INT);
                $stmt_any_cap->execute();
                $default_capacity = $stmt_any_cap->fetch(PDO::FETCH_ASSOC);
            }
            
            // If still no capacity found, use safe defaults
            $default_capacity = $default_capacity ?: ['capacity_am' => 5, 'capacity_pm' => 5];
            error_log("DEBUG: Using capacity: " . json_encode($default_capacity));

            // C. Fetch Booked Appointments (Q4/Q5)
            $sql_booked = "SELECT `date`, time_slot, COUNT(appointment_id) as booked_count FROM appointments 
                           WHERE branch_id = :branch_id AND `date` BETWEEN :start_date AND :end_date 
                           AND status != 'cancelled' GROUP BY `date`, time_slot";
            $stmt_booked = $pdo->prepare($sql_booked);
            $stmt_booked->bindParam(':branch_id', $branch_id, PDO::PARAM_INT);
            $stmt_booked->bindParam(':start_date', $start_date, PDO::PARAM_STR);
            $stmt_booked->bindParam(':end_date', $end_date, PDO::PARAM_STR);
            $stmt_booked->execute();
            
            $booked_slots = [];
            foreach ($stmt_booked->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $booked_slots[$row['date']][$row['time_slot']] = (int)$row['booked_count'];
            }

            // D. Fetch Holidays (safely handle missing holidays table)
            error_log("DEBUG: Checking for holidays");
            $holidays = [];
            $holiday_details = [];
            try {
                $sql_holidays = "SELECT holiday_date, holiday_name FROM holidays WHERE holiday_date BETWEEN :start_date AND :end_date";
                $stmt_holidays = $pdo->prepare($sql_holidays);
                $stmt_holidays->bindParam(':start_date', $start_date, PDO::PARAM_STR);
                $stmt_holidays->bindParam(':end_date', $end_date, PDO::PARAM_STR);
                $stmt_holidays->execute();
                $holiday_details = $stmt_holidays->fetchAll(PDO::FETCH_ASSOC);
                $holidays = array_column($holiday_details, 'holiday_date');
            } catch (\PDOException $e) {
                // If holidays table doesn't exist, just continue with empty holidays array
                error_log("Notice: Holidays table may not exist: " . $e->getMessage());
            }
            
            // E. Calculate Daily Availability (Q4 logic)
            $availability_data = [];
            $start = new DateTime($start_date);
            $end = new DateTime($end_date);
            $end->modify('+1 day'); 

            $interval = DateInterval::createFromDateString('1 day');
            $period = new DatePeriod($start, $interval, $end);

            foreach ($period as $dt) {
                $date_str = $dt->format("Y-m-d");
                $day_of_week = $dt->format('w'); // 0 (Sun) to 6 (Sat)
                
                $is_weekend = ($day_of_week == 0 || $day_of_week == 6);
                $is_holiday = in_array($date_str, $holidays);
                
                $am_booked = $booked_slots[$date_str]['AM'] ?? 0;
                $pm_booked = $booked_slots[$date_str]['PM'] ?? 0;

                $am_cap = $default_capacity['capacity_am'];
                $pm_cap = $default_capacity['capacity_pm'];

                $am_available = $am_cap - $am_booked;
                $pm_available = $pm_cap - $pm_booked;

                // Condition for disabling: weekend, holiday, OR (AM is full AND PM is full)
                $is_full = ($am_available <= 0 && $pm_available <= 0);
                $is_disabled = $is_weekend || $is_holiday || $is_full;

                $availability_data[$date_str] = [
                    'am_remaining' => max(0, $am_available),
                    'pm_remaining' => max(0, $pm_available),
                    'am_capacity' => $am_cap,
                    'pm_capacity' => $pm_cap,
                    'is_disabled' => $is_disabled
                ];
            }
            
            // F. Send Consolidated Response
            $freeze = nfa_get_effective_appointment_freeze($pdo, $branch_id);
            $appointment_freeze = [
                'effective_frozen' => (bool)($freeze['effective_frozen'] ?? false),
                'source' => $freeze['source'] ?? null,
                'message' => (string)($freeze['message'] ?? ''),
                'manual_frozen' => (bool)($freeze['manual_frozen'] ?? false),
                'is_full' => (bool)($freeze['is_full'] ?? false),
            ];

            echo json_encode([
                'success' => true,
                'capacity_info' => $volume_info,
                'default_slot_capacity' => $default_capacity,
                'daily_availability' => $availability_data,
                'holidays' => $holidays,
                'holiday_details' => $holiday_details,
                'appointment_freeze' => $appointment_freeze
            ]);

        } catch (\PDOException $e) {
            // Log full error server-side
            error_log("Branch info fetch failed: " . $e->getMessage());

            // Return a helpful error message for debugging (remove 'debug' in production)
            $clientError = 'Database query failed to fetch branch info.';
            echo json_encode([
                'success' => false,
                'error' => $clientError,
                'debug' => $e->getMessage()
            ]);
        }
        break;
        
    case 'getFarmerTypes':
        // 4. Get Farmer Types
        try {
            $stmt = $pdo->query("SELECT farmer_type_id, type_name FROM farmer_type ORDER BY type_name");
            $types = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode([
                'success' => true,
                // keep existing key for backwards compatibility
                'data' => $types,
                // add explicit key used by newer UI code
                'farmerTypes' => $types
            ]);
        } catch (\PDOException $e) {
            error_log("Farmer Types fetch failed: " . $e->getMessage());
            echo json_encode(['success' => false, 'error' => 'Failed to retrieve farmer types.']);
        }
        break;

    case 'validateEmailDomain':
        // Validate that an email domain exists (DNS A/MX) — best effort.
        $raw = file_get_contents('php://input');
        $payload = json_decode($raw ?: '', true);
        $email = sanitize_input(($payload['email'] ?? $_GET['email'] ?? ''));
        $email = is_string($email) ? trim($email) : '';

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'valid' => false, 'message' => 'Invalid email format.']);
            exit;
        }

        $domain = strtolower(substr(strrchr($email, '@'), 1) ?: '');
        if ($domain === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'valid' => false, 'message' => 'Missing email domain.']);
            exit;
        }

        // If DNS functions are not available, don't hard-block.
        if (!function_exists('checkdnsrr')) {
            echo json_encode(['success' => true, 'valid' => true, 'message' => 'Domain validation not available on server.']);
            exit;
        }

        $hasMx = @checkdnsrr($domain, 'MX');
        $hasA  = @checkdnsrr($domain, 'A') || @checkdnsrr($domain, 'AAAA');

        if ($hasMx || $hasA) {
            echo json_encode(['success' => true, 'valid' => true, 'message' => 'Email domain looks valid.']);
        } else {
            http_response_code(400);
            echo json_encode(['success' => false, 'valid' => false, 'message' => 'Email domain does not appear to exist. Please check the spelling.']);
        }
        exit;

    case 'submitAppointment':
        // 5. Submit Appointment (Merged from submit_appointment.php logic)
        error_log("DEBUG: Starting appointment submission");
        $raw_input = file_get_contents('php://input');
        error_log("DEBUG: Raw input: " . $raw_input);
        $data_raw = json_decode($raw_input, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("JSON decode error: " . json_last_error_msg());
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid JSON data received']);
            exit;
        }
        
        $data = [
            'branch_id'      => (int)sanitize_input($data_raw['branch_id'] ?? 0),
            'date'           => sanitize_input($data_raw['date'] ?? null),
            'time_slot'      => sanitize_input($data_raw['time_slot'] ?? null),
            'first_name'     => sanitize_input($data_raw['firstName'] ?? null),
            'middle_name'    => sanitize_input($data_raw['middleName'] ?? null),
            'last_name'      => sanitize_input($data_raw['lastName'] ?? null),
            'farmer_id'      => sanitize_input($data_raw['farmer_id'] ?? null),
            'suffix'         => sanitize_input($data_raw['suffix'] ?? null),
            'g_recaptcha_response' => sanitize_input($data_raw['g_recaptcha_response'] ?? $data_raw['recaptcha'] ?? null),
            'email'          => sanitize_input($data_raw['email'] ?? null),
            'contact_number' => sanitize_input($data_raw['contact'] ?? null),
            'gender'         => sanitize_input($data_raw['gender'] ?? null),
            'volume'         => (float)sanitize_input($data_raw['volume'] ?? 0),
            'farmer_type_id' => (int)sanitize_input($data_raw['farmer_type_id'] ?? 0), 
            'reference_number' => sanitize_input($data_raw['reference_number'] ?? ''),
        ];

        // --- Email validation (strict domain existence) ---
        $data['email'] = is_string($data['email']) ? trim($data['email']) : '';
        if ($data['email'] === '' || !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Please enter a valid email address.']);
            exit;
        }
        $emailDomain = strtolower(substr(strrchr($data['email'], '@'), 1) ?: '');
        if ($emailDomain === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Please enter a valid email domain.']);
            exit;
        }
        if (function_exists('checkdnsrr')) {
            $hasMx = @checkdnsrr($emailDomain, 'MX');
            $hasA  = @checkdnsrr($emailDomain, 'A') || @checkdnsrr($emailDomain, 'AAAA');
            if (!$hasMx && !$hasA) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Email domain does not appear to exist. Please check the spelling.']);
                exit;
            }
        }

        // --- Contact number normalization + validation ---
        $contactRaw = is_string($data['contact_number']) ? $data['contact_number'] : '';
        $digits = preg_replace('/\D+/', '', $contactRaw);
        if (strpos($digits, '63') === 0 && strlen($digits) >= 12) {
            $digits = '0' . substr($digits, 2);
        }
        $digits = substr($digits, 0, 11);
        if (!preg_match('/^09\d{9}$/', $digits)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Please enter a valid contact number in the format 09XX-XXX-XXXX.']);
            exit;
        }
        $data['contact_number'] = $digits;

        // Normalize gender casing to match UI dropdown labels
        // (prevents storing "male"/"female"/"other" when UI expects "Male"/"Female"/"Other").
        if (is_string($data['gender'])) {
            $g = strtolower(trim($data['gender']));
            if ($g === 'male') {
                $data['gender'] = 'Male';
            } elseif ($g === 'female') {
                $data['gender'] = 'Female';
            } elseif ($g === 'other') {
                $data['gender'] = 'Other';
            }
        }
        
        error_log("DEBUG: Processed data: " . json_encode($data));

        if (!$data['branch_id'] || !$data['date'] || !$data['first_name'] || !$data['last_name'] || !$data['farmer_id'] || $data['volume'] <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Missing required appointment data or invalid volume.']);
            exit;
        }

        // Waitlist management: block new appointments when warehouse is full or branch intake is manually frozen.
        try {
            $freezeInfo = nfa_get_effective_appointment_freeze($pdo, (int)$data['branch_id']);
            if (!empty($freezeInfo['effective_frozen'])) {
                http_response_code(409);
                $msg = (string)($freezeInfo['message'] ?? 'New appointments are temporarily frozen for this branch.');
                echo json_encode(['success' => false, 'error' => $msg, 'freeze' => $freezeInfo]);
                exit;
            }
        } catch (Exception $e) {
            // best-effort; do not block on freeze check failure
        }

        // Verify reCAPTCHA token is present
        if (empty($data['g_recaptcha_response'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Missing reCAPTCHA token.']);
            exit;
        }

        // Verify reCAPTCHA with Google's siteverify API (v2)
        $recaptcha_secret = '6LcdCQwsAAAAAJ3xlp-YTJp_Rgy_EC77jQ02ZnU9'; // provided secret
        $recaptcha_response = $data['g_recaptcha_response'];

        $verify_url = 'https://www.google.com/recaptcha/api/siteverify';
        $ch = curl_init($verify_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'secret' => $recaptcha_secret,
            'response' => $recaptcha_response,
            'remoteip' => $_SERVER['REMOTE_ADDR'] ?? ''
        ]));
        $verify_resp = curl_exec($ch);
        $curl_err = curl_error($ch);
        curl_close($ch);

        if ($verify_resp === false || !$verify_resp) {
            error_log('reCAPTCHA verify HTTP error: ' . $curl_err);
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to verify reCAPTCHA.']);
            exit;
        }

        $verify_data = json_decode($verify_resp, true);
        if (!isset($verify_data['success']) || $verify_data['success'] !== true) {
            error_log('reCAPTCHA verification failed: ' . $verify_resp);
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'reCAPTCHA verification failed.']);
            exit;
        }
        
        $reference_number = null;
        $client_ref = is_string($data['reference_number']) ? trim($data['reference_number']) : '';
        if ($client_ref !== '' && preg_match('/^NFA\d{8}[A-Z0-9]{6}$/', $client_ref)) {
            // Accept client pre-generated ref if not already used
            $stmt_ref = $pdo->prepare('SELECT COUNT(*) FROM appointments WHERE reference_number = ?');
            $stmt_ref->execute([$client_ref]);
            $exists = (int)$stmt_ref->fetchColumn() > 0;
            if (!$exists) {
                $reference_number = $client_ref;
            }
        }

        if (!$reference_number) {
            $reference_number = 'NFA' . date('Ymd') . strtoupper(substr(md5(uniqid(rand(), true)), 0, 6));
            // Best-effort uniqueness
            for ($i = 0; $i < 5; $i++) {
                $stmt_ref = $pdo->prepare('SELECT COUNT(*) FROM appointments WHERE reference_number = ?');
                $stmt_ref->execute([$reference_number]);
                $exists = (int)$stmt_ref->fetchColumn() > 0;
                if (!$exists) break;
                $reference_number = 'NFA' . date('Ymd') . strtoupper(substr(md5(uniqid(rand(), true)), 0, 6));
            }
        }

        try {
            $pdo->beginTransaction();

            error_log("DEBUG: Starting appointment insert");
            // Get region_id from branch (required by appointments table)
            $stmt_region = $pdo->prepare("SELECT region_id FROM branch WHERE branch_id = ?");
            $stmt_region->execute([$data['branch_id']]);
            $region_id = $stmt_region->fetchColumn();
            
            if (!$region_id) {
                throw new \PDOException("Invalid branch_id or region not found");
            }

            // Ensure appointments.mode exists for tracking entry source (appointment vs walk-in)
            try {
                $colStmt = $pdo->query("SHOW COLUMNS FROM appointments LIKE 'mode'");
                $col = $colStmt ? $colStmt->fetch(PDO::FETCH_ASSOC) : false;
                if (!$col) {
                    $pdo->exec("ALTER TABLE appointments ADD COLUMN mode VARCHAR(20) NOT NULL DEFAULT 'appointment'");
                }
            } catch (PDOException $e) {
                // Best-effort: keep working even if ALTER fails
            }

            // Insert into appointments table (including farmer_id and suffix)
            $sql = "INSERT INTO appointments 
                    (branch_id, region_id, date, time_slot, first_name, middle_name, last_name, 
                     farmer_id, suffix, email, contact_number, gender, volume, farmer_type_id, status, reference_number, mode) 
                    VALUES 
                    (:branch_id, :region_id, :date, :time_slot, :first_name, :middle_name, :last_name,
                     :farmer_id, :suffix, :email, :contact_number, :gender, :volume, :farmer_type_id, 'pending', :reference_number, 'appointment')";

            $stmt = $pdo->prepare($sql);
            $params = [
                ':branch_id' => $data['branch_id'],
                ':region_id' => $region_id,
                ':date' => $data['date'],
                ':time_slot' => $data['time_slot'],
                ':first_name' => $data['first_name'],
                ':middle_name' => $data['middle_name'],
                ':last_name' => $data['last_name'],
                ':farmer_id' => $data['farmer_id'],
                ':suffix' => $data['suffix'],
                ':email' => $data['email'],
                ':contact_number' => $data['contact_number'],
                ':gender' => $data['gender'],
                ':volume' => $data['volume'],
                ':farmer_type_id' => $data['farmer_type_id'],
                ':reference_number' => $reference_number
            ];
            error_log("DEBUG: SQL params: " . json_encode($params));
            $stmt->execute($params);
            
            $pdo->commit();

            // --- EMAIL NOTIFICATION LOGIC ---
            $smtpHost = 'smtp.gmail.com';
            $smtpUser = 'anonymous.00112211@gmail.com';
            $smtpPass = 'xwucrpggtanqrvwp';
            $smtpPort = 587;

            $sessionWindow = ($data['time_slot'] === 'AM') ? '8:00 AM – 12:00 NN' : '1:00 PM – 5:00 PM';
            $farmerFullName = trim($data['first_name'] . ' ' . ($data['middle_name'] ? ($data['middle_name'] . ' ') : '') . $data['last_name'] . ($data['suffix'] ? (' ' . $data['suffix']) : ''));

            $branchName = 'Branch ID ' . $data['branch_id'];
            try {
                $stmt_branch = $pdo->prepare("SELECT branch_name FROM branch WHERE branch_id = ? LIMIT 1");
                $stmt_branch->execute([$data['branch_id']]);
                $branchRow = $stmt_branch->fetch(PDO::FETCH_ASSOC);
                if ($branchRow && !empty($branchRow['branch_name'])) {
                    $branchName = $branchRow['branch_name'];
                }
            } catch (Exception $e) {
                // Best-effort only; keep branchName fallback.
            }

            $referenceSafe = htmlspecialchars($reference_number, ENT_QUOTES, 'UTF-8');
            $farmerFullNameSafe = htmlspecialchars($farmerFullName, ENT_QUOTES, 'UTF-8');
            $branchNameSafe = htmlspecialchars($branchName, ENT_QUOTES, 'UTF-8');
            $dateSafe = htmlspecialchars($data['date'], ENT_QUOTES, 'UTF-8');
            $slotSafe = htmlspecialchars($data['time_slot'], ENT_QUOTES, 'UTF-8');
            $windowSafe = htmlspecialchars($sessionWindow, ENT_QUOTES, 'UTF-8');
            $farmerIdSafe = htmlspecialchars($data['farmer_id'], ENT_QUOTES, 'UTF-8');
            $volumeSafe = htmlspecialchars((string)$data['volume'], ENT_QUOTES, 'UTF-8');

                        $confirmationDocHtml = "<!doctype html>
<html lang=\"en\"><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">
<title>NFA PalayPortal | Appointment Confirmation - {$referenceSafe}</title>
<style>
  body{font-family:Arial,Helvetica,sans-serif;color:#111;line-height:1.4;margin:0;padding:24px;background:#f6f7f9;}
  .doc{max-width:820px;margin:0 auto;background:#fff;border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;}
  .hdr{padding:18px 22px;border-bottom:1px solid #e5e7eb;display:flex;gap:14px;align-items:center;}
  .hdr h1{font-size:18px;margin:0;}
  .sub{color:#6b7280;font-size:12px;margin-top:4px;}
  .sec{padding:18px 22px;}
  .grid{display:grid;grid-template-columns:1fr 1fr;gap:10px 18px;}
  .row{display:flex;justify-content:space-between;gap:12px;padding:10px 12px;border:1px solid #eef0f3;border-radius:10px;background:#fafafa;}
  .k{color:#374151;font-weight:700;font-size:12px;text-transform:uppercase;letter-spacing:.04em;}
  .v{font-weight:700;color:#0b6a2b;}
  .note{margin-top:14px;color:#374151;font-size:13px;}
  .muted{color:#6b7280;font-size:12px;}
</style></head>
<body>
  <div class=\"doc\">
    <div class=\"hdr\">
      <div>
        <h1>NFA PalayPortal — Appointment Confirmation</h1>
        <div class=\"sub\">System Version 1 • Last Updated: January 2026</div>
      </div>
    </div>
    <div class=\"sec\">
      <div class=\"grid\">
                <div class=\"row\"><div class=\"k\">Reference No.</div><div class=\"v\">{$referenceSafe}</div></div>
        <div class=\"row\"><div class=\"k\">Status</div><div class=\"v\">Pending Approval</div></div>
                <div class=\"row\"><div class=\"k\">Branch</div><div>{$branchNameSafe}</div></div>
                <div class=\"row\"><div class=\"k\">Date</div><div>{$dateSafe}</div></div>
                <div class=\"row\"><div class=\"k\">Session</div><div>{$slotSafe} ({$windowSafe})</div></div>
                <div class=\"row\"><div class=\"k\">Farmer</div><div>{$farmerFullNameSafe}</div></div>
                <div class=\"row\"><div class=\"k\">Farmer ID</div><div>{$farmerIdSafe}</div></div>
                <div class=\"row\"><div class=\"k\">Volume</div><div>{$volumeSafe} bags</div></div>
      </div>
      <p class=\"note\"><strong>Arrival guidance:</strong> You may arrive anytime within your selected session window. Please bring your Farmer ID and this confirmation.</p>
    <p class=\"muted\">If you need corrections or assistance, contact your NFA branch or <a href=\"mailto:publicaffairs@nfa.gov.ph\" target=\"_blank\" rel=\"noopener noreferrer\">publicaffairs@nfa.gov.ph</a>.</p>
    </div>
  </div>
</body></html>";

            // 1) Notify processor (best-effort)
            try {
                $stmt_proc = $pdo->prepare("SELECT email_address FROM users WHERE branch_id = ? AND user_type = 'Processor' LIMIT 1");
                $stmt_proc->execute([$data['branch_id']]);
                $processor = $stmt_proc->fetch(PDO::FETCH_ASSOC);

                if ($processor && !empty($processor['email_address'])) {
                    $mailProc = new PHPMailer(true);
                    $mailProc->isSMTP();
                    $mailProc->Host = $smtpHost;
                    $mailProc->SMTPAuth = true;
                    $mailProc->Username = $smtpUser;
                    $mailProc->Password = $smtpPass;
                    $mailProc->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                    $mailProc->Port = $smtpPort;

                    $mailProc->setFrom('no-reply@nfa.gov.ph', 'NFA PalayPortal');
                    $mailProc->addAddress($processor['email_address']);
                    $mailProc->isHTML(true);
                    $mailProc->Subject = 'New Appointment: ' . $reference_number;
                    $mailProc->Body = "
                        <h3>New Farmer Appointment Notification</h3>
                        <p>An appointment has been scheduled and requires review.</p>
                        <ul>
                            <li><strong>Farmer:</strong> {$farmerFullName}</li>
                            <li><strong>Reference #:</strong> {$reference_number}</li>
                            <li><strong>Date:</strong> {$data['date']}</li>
                            <li><strong>Slot:</strong> {$data['time_slot']} ({$sessionWindow})</li>
                            <li><strong>Volume:</strong> {$data['volume']} bags</li>
                            <li><strong>Branch:</strong> {$branchName}</li>
                        </ul>
                        <p>Please log in to the portal to manage this appointment.</p>
                    ";

                    $mailProc->send();
                }
            } catch (Exception $e) {
                error_log("Processor email notification failed: " . $e->getMessage());
            }

            // 2) Confirmation email to farmer (best-effort)
            try {
                if (!empty($data['email'])) {
                    $mailFarmer = new PHPMailer(true);
                    $mailFarmer->isSMTP();
                    $mailFarmer->Host = $smtpHost;
                    $mailFarmer->SMTPAuth = true;
                    $mailFarmer->Username = $smtpUser;
                    $mailFarmer->Password = $smtpPass;
                    $mailFarmer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                    $mailFarmer->Port = $smtpPort;

                    $mailFarmer->setFrom('no-reply@nfa.gov.ph', 'NFA PalayPortal');
                    $mailFarmer->addAddress($data['email']);
                    $mailFarmer->isHTML(true);
                    $mailFarmer->Subject = 'Appointment Request Received: ' . $reference_number;
                    $mailFarmer->Body = "
                        <p>Good day {$farmerFullName},</p>
                        <p>Your appointment request has been received and is currently <strong>Pending Approval</strong>.</p>
                        <ul>
                            <li><strong>Reference No.:</strong> {$reference_number}</li>
                            <li><strong>Date:</strong> {$data['date']}</li>
                            <li><strong>Session:</strong> {$data['time_slot']} ({$sessionWindow})</li>
                            <li><strong>Volume:</strong> {$data['volume']} bags</li>
                            <li><strong>Branch:</strong> {$branchName}</li>
                        </ul>
                        <p>You may arrive anytime within your selected session window. Please bring your Farmer ID and a copy of your confirmation.</p>
                        <p>Attached is your appointment confirmation document for printing or saving.</p>
                        <p>Thank you.</p>
                    ";

                    $mailFarmer->addStringAttachment($confirmationDocHtml, 'Appointment_Confirmation_' . $reference_number . '.html', 'base64', 'text/html');
                    $mailFarmer->send();
                }
            } catch (Exception $e) {
                error_log("Farmer confirmation email failed: " . $e->getMessage());
            }

            echo json_encode(['success' => true, 'referenceNumber' => $reference_number]);

        } catch (\PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("Appointment Insert Error: " . $e->getMessage());
            http_response_code(500);
            echo json_encode([
                'success' => false, 
                'error' => 'Failed to book appointment due to server error.',
                'debug' => $e->getMessage()
            ]);
        } catch (\Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("General Error in appointment submission: " . $e->getMessage());
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'An unexpected error occurred.',
                'debug' => $e->getMessage()
            ]);
        }
        break;

    // --- Walk-in: lookup farmer details from latest appointment (Processor only) ---
    case 'lookupFarmerById':
        if (!isset($_SESSION['loggedin']) || ($_SESSION['user_type'] ?? '') !== 'Processor') {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Unauthorized']);
            break;
        }

        $branch_id = (int)($_SESSION['branch_id'] ?? 0);
        $farmer_id = (string)sanitize_input($_GET['farmer_id'] ?? '');
        $farmer_id = trim($farmer_id);
        if ($branch_id <= 0 || $farmer_id === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Missing branch or farmer ID']);
            break;
        }

        try {
            $stmt = $pdo->prepare("SELECT farmer_id, first_name, middle_name, last_name, suffix, email, contact_number, gender, farmer_type_id
                FROM appointments
                WHERE branch_id = ? AND farmer_id = ?
                ORDER BY appointment_id DESC
                LIMIT 1");
            $stmt->execute([$branch_id, $farmer_id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                echo json_encode(['success' => true, 'found' => false]);
                break;
            }

            echo json_encode([
                'success' => true,
                'found' => true,
                'farmer' => [
                    'farmer_id' => (string)($row['farmer_id'] ?? ''),
                    'first_name' => (string)($row['first_name'] ?? ''),
                    'middle_name' => (string)($row['middle_name'] ?? ''),
                    'last_name' => (string)($row['last_name'] ?? ''),
                    'suffix' => (string)($row['suffix'] ?? ''),
                    'email' => (string)($row['email'] ?? ''),
                    'contact_number' => (string)($row['contact_number'] ?? ''),
                    'gender' => (string)($row['gender'] ?? ''),
                    'farmer_type_id' => (int)($row['farmer_type_id'] ?? 0),
                ]
            ]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Lookup failed']);
        }
        break;

    // --- Walk-in: create appointment record (Processor only) ---
    case 'createWalkInAppointment':
        if (!isset($_SESSION['loggedin']) || ($_SESSION['user_type'] ?? '') !== 'Processor') {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Unauthorized']);
            break;
        }

        $branch_id = (int)($_SESSION['branch_id'] ?? 0);
        if ($branch_id <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Missing branch']);
            break;
        }

        // Waitlist management: block new walk-ins when warehouse is full or intake is frozen.
        try {
            $freezeInfo = nfa_get_effective_appointment_freeze($pdo, $branch_id);
            if (!empty($freezeInfo['effective_frozen'])) {
                http_response_code(409);
                $msg = (string)($freezeInfo['message'] ?? 'New appointments are temporarily frozen for this branch.');
                echo json_encode(['success' => false, 'error' => $msg, 'freeze' => $freezeInfo]);
                break;
            }
        } catch (Exception $e) {
            // best-effort
        }

        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data)) $data = [];

        $date = (string)sanitize_input($data['date'] ?? date('Y-m-d'));
        $time_slot = strtoupper((string)sanitize_input($data['time_slot'] ?? ''));
        $farmer_id = (string)sanitize_input($data['farmer_id'] ?? '');
        $first_name = (string)sanitize_input($data['first_name'] ?? '');
        $middle_name = (string)sanitize_input($data['middle_name'] ?? '');
        $last_name = (string)sanitize_input($data['last_name'] ?? '');
        $suffix = (string)sanitize_input($data['suffix'] ?? '');
        $email = (string)sanitize_input($data['email'] ?? '');
        $contact_number = (string)sanitize_input($data['contact_number'] ?? '');
        $gender = (string)sanitize_input($data['gender'] ?? '');
        $volume = $data['volume'] ?? null;
        $farmer_type_id = (int)sanitize_input($data['farmer_type_id'] ?? 0);

        $farmer_id = trim($farmer_id);
        $first_name = trim($first_name);
        $middle_name = trim($middle_name);
        $last_name = trim($last_name);
        $suffix = trim($suffix);
        $email = trim($email);
        $contact_number = trim($contact_number);
        $gender = trim($gender);

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid date']);
            break;
        }
        if (!in_array($time_slot, ['AM', 'PM'], true)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid time slot']);
            break;
        }
        if ($farmer_id === '' || $first_name === '' || $middle_name === '' || $last_name === '' || $email === '' || $contact_number === '' || $gender === '' || $farmer_type_id <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Please complete all required fields']);
            break;
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid email']);
            break;
        }

        $genderLower = strtolower($gender);
        if (!in_array($genderLower, ['male', 'female', 'other'], true)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid gender']);
            break;
        }
        $gender = $genderLower;

        // Normalize PH contact number to 09XXXXXXXXX
        $contact_digits = preg_replace('/\D+/', '', $contact_number);
        if (preg_match('/^639\d{9}$/', $contact_digits)) {
            $contact_digits = '0' . substr($contact_digits, 2);
        }
        if (!preg_match('/^09\d{9}$/', $contact_digits)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid contact number']);
            break;
        }
        $contact_number = $contact_digits;

        if (!is_numeric($volume) || (float)$volume <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid volume']);
            break;
        }

        // Generate reference number
        $reference_number = 'NFA' . date('Ymd') . strtoupper(substr(md5(uniqid(rand(), true)), 0, 6));
        try {
            // Best-effort uniqueness
            for ($i = 0; $i < 5; $i++) {
                $stmt_ref = $pdo->prepare('SELECT COUNT(*) FROM appointments WHERE reference_number = ?');
                $stmt_ref->execute([$reference_number]);
                $exists = (int)$stmt_ref->fetchColumn() > 0;
                if (!$exists) break;
                $reference_number = 'NFA' . date('Ymd') . strtoupper(substr(md5(uniqid(rand(), true)), 0, 6));
            }
        } catch (PDOException $e) {
            // ignore
        }

        try {
            $pdo->beginTransaction();

            // Ensure mode exists
            try {
                $colStmt = $pdo->query("SHOW COLUMNS FROM appointments LIKE 'mode'");
                $col = $colStmt ? $colStmt->fetch(PDO::FETCH_ASSOC) : false;
                if (!$col) {
                    $pdo->exec("ALTER TABLE appointments ADD COLUMN mode VARCHAR(20) NOT NULL DEFAULT 'appointment'");
                }
            } catch (PDOException $e) {
                // Best-effort
            }

            // Get region_id from branch
            $stmt_region = $pdo->prepare("SELECT region_id FROM branch WHERE branch_id = ?");
            $stmt_region->execute([$branch_id]);
            $region_id = $stmt_region->fetchColumn();
            if (!$region_id) {
                throw new PDOException('Invalid branch');
            }

            // Record as completed by default (walk-in is already processed in-person)
            $sql = "INSERT INTO appointments
                    (branch_id, region_id, date, time_slot, first_name, middle_name, last_name,
                     farmer_id, suffix, email, contact_number, gender, volume, farmer_type_id, status, reference_number, mode, is_read)
                    VALUES
                    (:branch_id, :region_id, :date, :time_slot, :first_name, :middle_name, :last_name,
                     :farmer_id, :suffix, :email, :contact_number, :gender, :volume, :farmer_type_id, 'completed', :reference_number, 'walk-in', 1)";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':branch_id' => $branch_id,
                ':region_id' => (int)$region_id,
                ':date' => $date,
                ':time_slot' => $time_slot,
                ':first_name' => $first_name,
                ':middle_name' => $middle_name,
                ':last_name' => $last_name,
                ':farmer_id' => $farmer_id,
                ':suffix' => $suffix,
                ':email' => $email,
                ':contact_number' => $contact_number,
                ':gender' => $gender,
                ':volume' => (float)$volume,
                ':farmer_type_id' => $farmer_type_id,
                ':reference_number' => $reference_number,
            ]);

            $appointment_id = (int)$pdo->lastInsertId();

            // Walk-in should affect branch inventory just like recording a delivery.
            // Inventory represents actual received bags: ADD the walk-in volume.
            $stmtCap = $pdo->prepare("SELECT volume_id, warehouse_capacity, inventory FROM volume_capacity WHERE branch_id = ? FOR UPDATE");
            $stmtCap->execute([$branch_id]);
            $cap = $stmtCap->fetch(PDO::FETCH_ASSOC);

            if ($cap) {
                $new_inventory = (float)($cap['inventory'] ?? 0) + (float)$volume;
                $stmtInv = $pdo->prepare("UPDATE volume_capacity SET inventory = ? WHERE branch_id = ?");
                $stmtInv->execute([$new_inventory, $branch_id]);
                $warehouse_capacity = (float)($cap['warehouse_capacity'] ?? 0);
            } else {
                // If capacity row doesn't exist for this branch, create it so inventory can be tracked.
                $stmtIns = $pdo->prepare("INSERT INTO volume_capacity (region_id, branch_id, warehouse_capacity, inventory) VALUES (?, ?, ?, ?)");
                $stmtIns->execute([(int)$region_id, $branch_id, 0, (float)$volume]);
                $new_inventory = (float)$volume;
                $warehouse_capacity = 0.0;
            }

            $available_volume = max(0, $warehouse_capacity - $new_inventory);
            $pdo->commit();

            echo json_encode([
                'success' => true,
                'appointment_id' => $appointment_id,
                'reference_number' => $reference_number,
                'status' => 'completed',
                'mode' => 'walk-in',
                'inventory' => $new_inventory,
                'warehouse_capacity' => $warehouse_capacity,
                'available_volume' => $available_volume
            ]);
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to create walk-in appointment']);
        }
        break;

    // --- Notification Read/Unread Handler (POST) ---

    // --- My Profile / Settings (User) ---
    case 'updateMyProfile':
        if (!isset($_SESSION['loggedin'])) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Unauthorized']);
            break;
        }

        $user_id = (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? 0);
        if ($user_id <= 0) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Unauthorized']);
            break;
        }

        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data)) $data = [];

        $first_name = trim((string)sanitize_input($data['first_name'] ?? ''));
        $middle_name = trim((string)sanitize_input($data['middle_name'] ?? ''));
        $last_name = trim((string)sanitize_input($data['last_name'] ?? ''));
        $suffix = trim((string)sanitize_input($data['suffix'] ?? ''));
        $email_address = trim((string)sanitize_input($data['email_address'] ?? ''));
        $contact_number = trim((string)sanitize_input($data['contact_number'] ?? ''));
        $gender = strtolower(trim((string)sanitize_input($data['gender'] ?? '')));

        if ($first_name === '' || $middle_name === '' || $last_name === '' || $email_address === '' || $contact_number === '' || $gender === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Please complete all required fields.']);
            break;
        }

        if (!filter_var($email_address, FILTER_VALIDATE_EMAIL)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid email.']);
            break;
        }

        if (!in_array($gender, ['male', 'female', 'other'], true)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid gender.']);
            break;
        }

        // Normalize PH contact number to 09XXXXXXXXX
        $digits = preg_replace('/\D+/', '', $contact_number);
        if (preg_match('/^639\d{9}$/', $digits)) {
            $digits = '0' . substr($digits, 2);
        }
        if (!preg_match('/^09\d{9}$/', $digits)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid contact number.']);
            break;
        }
        $contact_number = $digits;

        try {
            $stmt = $pdo->prepare("UPDATE users SET first_name = ?, middle_name = ?, last_name = ?, suffix = ?, email_address = ?, contact_number = ?, gender = ? WHERE user_id = ?");
            $stmt->execute([$first_name, $middle_name, $last_name, $suffix, $email_address, $contact_number, $gender, $user_id]);
            nfa_log_activity_best_effort($pdo, $user_id, 'Updated profile');
            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to update profile.']);
        }
        break;

    case 'changeMyPassword':
        if (!isset($_SESSION['loggedin'])) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Unauthorized']);
            break;
        }

        $user_id = (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? 0);
        if ($user_id <= 0) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Unauthorized']);
            break;
        }

        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data)) $data = [];
        $current_password = (string)($data['current_password'] ?? '');
        $new_password = (string)($data['new_password'] ?? '');

        if (trim($current_password) === '' || trim($new_password) === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Missing password fields.']);
            break;
        }
        if (strlen($new_password) < 8) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'New password must be at least 8 characters.']);
            break;
        }

        try {
            $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE user_id = ? LIMIT 1');
            $stmt->execute([$user_id]);
            $hash = $stmt->fetchColumn();
            if (!$hash || !password_verify($current_password, $hash)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Current password is incorrect.']);
                break;
            }

            $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
            $up = $pdo->prepare('UPDATE users SET password_hash = ? WHERE user_id = ?');
            $up->execute([$new_hash, $user_id]);
            nfa_log_activity_best_effort($pdo, $user_id, 'Changed password');
            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to change password.']);
        }
        break;
    case 'updateNotification':
    if (!isset($_SESSION['loggedin']) || ($_SESSION['user_type'] ?? '') !== 'Processor') {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        break;
    }
    $data = json_decode(file_get_contents('php://input'), true);
    $appointment_id = (int)($data['appointment_id'] ?? 0);
    $is_read = isset($data['is_read']) ? (int)$data['is_read'] : 0; // Capture the status from JS

    if ($appointment_id) {
        try {
            $stmt = $pdo->prepare("UPDATE appointments SET is_read = ? WHERE appointment_id = ?");
            $stmt->execute([$is_read, $appointment_id]);
            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    } else {
        echo json_encode(['success' => false, 'error' => 'Invalid ID']);
    }
    break;

    // --- Notification Delete Handler (POST) ---
    // Marks a notification as deleted so it no longer appears in the dropdown.
    // Implemented as a soft delete on the appointments table (notif_deleted = 1).
    case 'deleteNotification':
    if (!isset($_SESSION['loggedin']) || ($_SESSION['user_type'] ?? '') !== 'Processor') {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        break;
    }
    $data = json_decode(file_get_contents('php://input'), true);
    $appointment_id = (int)($data['appointment_id'] ?? 0);

    if ($appointment_id) {
        try {
            // Lazily add the column if it doesn't exist yet
            $colStmt = $pdo->query("SHOW COLUMNS FROM appointments LIKE 'notif_deleted'");
            $col = $colStmt ? $colStmt->fetch(PDO::FETCH_ASSOC) : false;
            if (!$col) {
                $pdo->exec("ALTER TABLE appointments ADD COLUMN notif_deleted TINYINT(1) NOT NULL DEFAULT 0");
            }

            $stmt = $pdo->prepare("UPDATE appointments SET notif_deleted = 1 WHERE appointment_id = ?");
            $stmt->execute([$appointment_id]);
            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    } else {
        echo json_encode(['success' => false, 'error' => 'Invalid ID']);
    }
    break;

    // --- Admin: Account Notification System ---
    case 'adminGetAccountNotifications':
        nfa_require_role('Admin');

        $limit = (int)sanitize_input($_GET['limit'] ?? 10);
        if ($limit <= 0) $limit = 10;
        if ($limit > 50) $limit = 50;

        try {
            $stmt = $pdo->prepare("SELECT user_id, first_name, last_name, username, user_type, status, region_id, branch_id,
                        COALESCE(created_at, NULL) AS created_at,
                        notif_is_read, notif_deleted
                FROM users
                WHERE status = 'Pending' AND (notif_deleted = 0 OR notif_deleted IS NULL)
                ORDER BY user_id DESC
                LIMIT {$limit}");
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $unread = 0;
            foreach ($rows as $r) {
                if ((int)($r['notif_is_read'] ?? 0) === 0) $unread++;
            }

            echo json_encode(['success' => true, 'items' => $rows, 'unread_count' => $unread]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to load notifications']);
        }
        break;

    case 'adminUpdateAccountNotification':
        nfa_require_role('Admin');
        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data)) $data = [];
        $user_id = (int)($data['user_id'] ?? 0);
        $is_read = isset($data['is_read']) ? (int)$data['is_read'] : 0;
        if ($user_id <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid user ID']);
            break;
        }
        try {
            $stmt = $pdo->prepare('UPDATE users SET notif_is_read = ? WHERE user_id = ?');
            $stmt->execute([$is_read ? 1 : 0, $user_id]);
            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to update notification']);
        }
        break;

    case 'adminDeleteAccountNotification':
        nfa_require_role('Admin');
        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data)) $data = [];
        $user_id = (int)($data['user_id'] ?? 0);
        if ($user_id <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid user ID']);
            break;
        }
        try {
            $stmt = $pdo->prepare('UPDATE users SET notif_deleted = 1 WHERE user_id = ?');
            $stmt->execute([$user_id]);
            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to delete notification']);
        }
        break;

    case 'adminMarkAllAccountNotificationsRead':
        nfa_require_role('Admin');
        try {
            $pdo->exec("UPDATE users SET notif_is_read = 1 WHERE status = 'Pending' AND (notif_deleted = 0 OR notif_deleted IS NULL)");
            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to mark all read']);
        }
        break;

    // --- Admin: Account Monitoring & Management ---
    case 'adminListAccounts':
        nfa_require_role('Admin');

        $status = trim((string)sanitize_input($_GET['status'] ?? ''));
        $q = trim((string)sanitize_input($_GET['q'] ?? ''));
        $includeInactive = (int)sanitize_input($_GET['include_inactive'] ?? 0) === 1;
        $limit = (int)sanitize_input($_GET['limit'] ?? 200);
        if ($limit <= 0) $limit = 200;
        if ($limit > 500) $limit = 500;

        $where = [];
        $params = [];

        // status filter: empty or "All" means no filter
        if ($status !== '' && strcasecmp($status, 'All') !== 0) {
            $where[] = 'u.status = ?';
            $params[] = $status;
        }

        // Only manage Processor accounts on this page
        $where[] = "u.user_type = 'Processor'";

        // Include deactivated processors only when explicitly requested.
        // Deactivated = status Approved + is_active = 0. Rejected accounts may also be inactive but must remain visible.
        if (!$includeInactive) {
            $where[] = 'NOT (u.status = \'Approved\' AND COALESCE(u.is_active, 1) = 0)';
        }

        if ($q !== '') {
            $where[] = '(
                u.username LIKE ?
                OR u.employee_id LIKE ?
                OR u.first_name LIKE ?
                OR u.middle_name LIKE ?
                OR u.last_name LIKE ?
                OR CONCAT_WS(" ", u.first_name, u.middle_name, u.last_name, u.suffix) LIKE ?
                OR CONCAT_WS(" ", u.last_name, u.first_name, u.middle_name, u.suffix) LIKE ?
            )';
            $like = '%' . $q . '%';
            array_push($params, $like, $like, $like, $like, $like, $like, $like);
        }

        $sql = "SELECT u.user_id, u.first_name, u.middle_name, u.last_name, u.suffix,
                       u.employee_id, u.email_address, u.contact_number, u.gender,
                       u.username, u.user_type, u.region_id, u.branch_id, u.status,
                       COALESCE(u.is_active, 1) AS is_active,
                       COALESCE(u.created_at, NULL) AS created_at,
                       r.region_name,
                       b.branch_name
                FROM users u
                LEFT JOIN regions r ON u.region_id = r.region_id
                LEFT JOIN branch b ON u.branch_id = b.branch_id";

        if (!empty($where)) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $sql .= ' ORDER BY FIELD(u.status, \'Pending\', \'Approved\', \'Rejected\') ASC, u.user_id DESC';
        $sql .= ' LIMIT ' . (int)$limit;

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'items' => $rows]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to load accounts']);
        }
        break;

    case 'adminApproveAccount':
        nfa_require_role('Admin');
        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data)) $data = [];
        $user_id = (int)($data['user_id'] ?? 0);
        if ($user_id <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid user ID']);
            break;
        }
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("UPDATE users SET status = 'Approved', notif_is_read = 1, is_active = 1 WHERE user_id = ?");
            $stmt->execute([$user_id]);

            $infoStmt = $pdo->prepare("SELECT first_name, middle_name, last_name, suffix, email_address, username FROM users WHERE user_id = ? LIMIT 1");
            $infoStmt->execute([$user_id]);
            $info = $infoStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $pdo->commit();

            $emailSent = false;
            $to = trim((string)($info['email_address'] ?? ''));
            if ($to !== '') {
                $name = trim(implode(' ', array_filter([
                    (string)($info['first_name'] ?? ''),
                    (string)($info['middle_name'] ?? ''),
                    (string)($info['last_name'] ?? ''),
                    (string)($info['suffix'] ?? ''),
                ])));
                if ($name === '') $name = (string)($info['username'] ?? '');

                $subject = 'NFA Account Approved';
                $body = "<div style='font-family:Arial,sans-serif'>
                            <h2 style='margin:0 0 10px;color:#0f6b35'>Your account has been approved</h2>
                            <p>Hello <strong>" . htmlspecialchars($name) . "</strong>,</p>
                            <p>Your NFA account request was approved. You may now log in to the system.</p>
                            <p style='color:#64748b;font-size:13px;margin-top:18px'>This is an automated message.</p>
                         </div>";
                $emailSent = (bool)nfa_send_html_email_best_effort($to, $subject, $body);
            }

            echo json_encode(['success' => true, 'email_sent' => $emailSent]);
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to approve account']);
        }
        break;

    case 'adminRejectAccount':
        nfa_require_role('Admin');
        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data)) $data = [];
        $user_id = (int)($data['user_id'] ?? 0);
        if ($user_id <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid user ID']);
            break;
        }
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("UPDATE users SET status = 'Rejected', notif_is_read = 1, is_active = 0 WHERE user_id = ?");
            $stmt->execute([$user_id]);

            $infoStmt = $pdo->prepare("SELECT first_name, middle_name, last_name, suffix, email_address, username FROM users WHERE user_id = ? LIMIT 1");
            $infoStmt->execute([$user_id]);
            $info = $infoStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $pdo->commit();

            $emailSent = false;
            $to = trim((string)($info['email_address'] ?? ''));
            if ($to !== '') {
                $name = trim(implode(' ', array_filter([
                    (string)($info['first_name'] ?? ''),
                    (string)($info['middle_name'] ?? ''),
                    (string)($info['last_name'] ?? ''),
                    (string)($info['suffix'] ?? ''),
                ])));
                if ($name === '') $name = (string)($info['username'] ?? '');

                $subject = 'NFA Account Rejected';
                $body = "<div style='font-family:Arial,sans-serif'>
                            <h2 style='margin:0 0 10px;color:#b91c1c'>Your account request was rejected</h2>
                            <p>Hello <strong>" . htmlspecialchars($name) . "</strong>,</p>
                            <p>We’re sorry — your NFA account request was rejected. If you believe this is a mistake, please contact your branch administrator.</p>
                            <p style='color:#64748b;font-size:13px;margin-top:18px'>This is an automated message.</p>
                         </div>";
                $emailSent = (bool)nfa_send_html_email_best_effort($to, $subject, $body);
            }

            echo json_encode(['success' => true, 'email_sent' => $emailSent]);
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to reject account']);
        }
        break;

    case 'adminSetAccountActive':
        nfa_require_role('Admin');
        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data)) $data = [];
        $user_id = (int)($data['user_id'] ?? 0);
        $is_active = isset($data['is_active']) ? (int)$data['is_active'] : 1;
        if ($user_id <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid user ID']);
            break;
        }
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('UPDATE users SET is_active = ? WHERE user_id = ?');
            $stmt->execute([$is_active ? 1 : 0, $user_id]);

            $infoStmt = $pdo->prepare("SELECT first_name, middle_name, last_name, suffix, email_address, username FROM users WHERE user_id = ? LIMIT 1");
            $infoStmt->execute([$user_id]);
            $info = $infoStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $pdo->commit();

            $emailSent = false;
            $to = trim((string)($info['email_address'] ?? ''));
            if ($to !== '') {
                $name = trim(implode(' ', array_filter([
                    (string)($info['first_name'] ?? ''),
                    (string)($info['middle_name'] ?? ''),
                    (string)($info['last_name'] ?? ''),
                    (string)($info['suffix'] ?? ''),
                ])));
                if ($name === '') $name = (string)($info['username'] ?? '');

                $subject = $is_active ? 'NFA Account Activated' : 'NFA Account Deactivated';
                $headline = $is_active ? 'Your account has been activated' : 'Your account has been deactivated';
                $color = $is_active ? '#0f6b35' : '#b91c1c';
                $message = $is_active
                    ? 'Your access to the NFA system has been restored. You can log in normally.'
                    : 'Your access to the NFA system has been temporarily disabled. Please contact your branch administrator if you need assistance.';

                $body = "<div style='font-family:Arial,sans-serif'>
                            <h2 style='margin:0 0 10px;color:" . $color . "'>" . htmlspecialchars($headline) . "</h2>
                            <p>Hello <strong>" . htmlspecialchars($name) . "</strong>,</p>
                            <p>" . htmlspecialchars($message) . "</p>
                            <p style='color:#64748b;font-size:13px;margin-top:18px'>This is an automated message.</p>
                         </div>";
                $emailSent = (bool)nfa_send_html_email_best_effort($to, $subject, $body);
            }

            echo json_encode(['success' => true, 'email_sent' => $emailSent]);
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to update account status']);
        }
        break;

    case 'adminReassignAccount':
        nfa_require_role('Admin');
        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data)) $data = [];
        $user_id = (int)($data['user_id'] ?? 0);
        $region_id = (int)($data['region_id'] ?? 0);
        $branch_id = (int)($data['branch_id'] ?? 0);

        if ($user_id <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid user ID']);
            break;
        }

        try {
            $stmtUser = $pdo->prepare('SELECT user_type FROM users WHERE user_id = ? LIMIT 1');
            $stmtUser->execute([$user_id]);
            $userType = (string)($stmtUser->fetchColumn() ?? '');
            if ($userType === '') {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'User not found']);
                break;
            }

            if ($userType === 'Processor') {
                if ($region_id <= 0 || $branch_id <= 0) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'error' => 'Region and branch are required for Processor accounts']);
                    break;
                }

                $stmt = $pdo->prepare('SELECT region_id FROM branch WHERE branch_id = ? LIMIT 1');
                $stmt->execute([$branch_id]);
                $branchRegionId = (int)($stmt->fetchColumn() ?? 0);
                if ($branchRegionId <= 0 || $branchRegionId !== $region_id) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'error' => 'Selected branch does not belong to the selected region']);
                    break;
                }

                $up = $pdo->prepare('UPDATE users SET region_id = ?, branch_id = ? WHERE user_id = ?');
                $up->execute([$region_id, $branch_id, $user_id]);
                echo json_encode(['success' => true]);
                break;
            }

            // Admin accounts: allow clearing assignment
            $up = $pdo->prepare('UPDATE users SET region_id = NULL, branch_id = NULL WHERE user_id = ?');
            $up->execute([$user_id]);
            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to reassign account']);
        }
        break;

    // --- Admin: System Monitoring (Processor Activity Logs) ---
    case 'adminGetActivityLogs':
        nfa_require_role('Admin');

        $limit = (int)sanitize_input($_GET['limit'] ?? 200);
        if ($limit <= 0) $limit = 200;
        if ($limit > 1000) $limit = 1000;

        $q = trim((string)sanitize_input($_GET['q'] ?? ''));
        $from = trim((string)sanitize_input($_GET['from'] ?? ''));
        $to = trim((string)sanitize_input($_GET['to'] ?? ''));
        $region_id = (int)sanitize_input($_GET['region_id'] ?? 0);
        $branch_id = (int)sanitize_input($_GET['branch_id'] ?? 0);

        $where = ["u.user_type = 'Processor'"];
        $params = [];

        if ($from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            $where[] = 'l.timestamp >= ?';
            $params[] = $from . ' 00:00:00';
        }
        if ($to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $where[] = 'l.timestamp <= ?';
            $params[] = $to . ' 23:59:59';
        }
        if ($q !== '') {
            $qWhere = '(l.action LIKE ? OR u.username LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ?)';
            $like = '%' . $q . '%';
            array_push($params, $like, $like, $like, $like);

            // Include details in search if the column exists
            if (nfa_column_exists($pdo, 'activity_logs', 'details')) {
                $qWhere = '(l.action LIKE ? OR u.username LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? OR l.details LIKE ?)';
                $params[] = $like;
            }

            $where[] = $qWhere;
        }

        if ($region_id > 0) {
            $where[] = 'u.region_id = ?';
            $params[] = $region_id;
        }
        if ($branch_id > 0) {
            $where[] = 'u.branch_id = ?';
            $params[] = $branch_id;
        }

        $select = [
            'l.log_id',
            'l.user_id',
            'l.action',
            'l.timestamp',
            'u.username',
            'u.first_name',
            'u.last_name',
            'u.user_type',
            'u.branch_id',
            'b.branch_name',
            'u.region_id',
            'r.region_name',
        ];

        // Optional columns (schema compatible)
        if (nfa_column_exists($pdo, 'users', 'employee_id')) {
            $select[] = 'u.employee_id';
        }
        if (nfa_column_exists($pdo, 'activity_logs', 'details')) {
            $select[] = 'l.details';
        }
        if (nfa_column_exists($pdo, 'activity_logs', 'ip_address')) {
            $select[] = 'l.ip_address';
        }

        $sql = "SELECT " . implode(', ', $select) . "
                FROM activity_logs l
                JOIN users u ON l.user_id = u.user_id
                LEFT JOIN branch b ON u.branch_id = b.branch_id
                LEFT JOIN regions r ON u.region_id = r.region_id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY l.timestamp DESC, l.log_id DESC
                LIMIT {$limit}";

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'items' => $rows]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to load activity logs']);
        }
        break;

    case 'adminGetLoginAttempts':
        nfa_require_role('Admin');
        nfa_ensure_login_attempts_schema($pdo);

        $limit = (int)sanitize_input($_GET['limit'] ?? 200);
        if ($limit <= 0) $limit = 200;
        if ($limit > 1000) $limit = 1000;

        $q = trim((string)sanitize_input($_GET['q'] ?? ''));
        $from = trim((string)sanitize_input($_GET['from'] ?? ''));
        $to = trim((string)sanitize_input($_GET['to'] ?? ''));
        $region_id = (int)sanitize_input($_GET['region_id'] ?? 0);
        $branch_id = (int)sanitize_input($_GET['branch_id'] ?? 0);

        $where = ['1=1'];
        $params = [];

        if ($from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            $where[] = 'a.occurred_at >= ?';
            $params[] = $from . ' 00:00:00';
        }
        if ($to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $where[] = 'a.occurred_at <= ?';
            $params[] = $to . ' 23:59:59';
        }
        if ($q !== '') {
            $like = '%' . $q . '%';
            $where[] = '(a.attempted_username LIKE ? OR a.reason_code LIKE ? OR a.ip_address LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? OR u.username LIKE ?)';
            array_push($params, $like, $like, $like, $like, $like, $like);
        }

        if ($region_id > 0) {
            $where[] = 'u.region_id = ?';
            $params[] = $region_id;
        }

        if ($branch_id > 0) {
            $where[] = 'u.branch_id = ?';
            $params[] = $branch_id;
        }

        $select = [
            'a.attempt_id',
            'a.occurred_at',
            'a.attempted_username',
            'a.user_id',
            'a.user_type',
            'a.account_status',
            'a.is_active',
            'a.reason_code',
            'a.ip_address',
            'a.user_agent',
            'u.username AS matched_username',
            'u.first_name',
            'u.last_name',
            'u.region_id',
            'r.region_name',
            'u.branch_id',
            'b.branch_name',
        ];

        if (nfa_column_exists($pdo, 'users', 'employee_id')) {
            $select[] = 'u.employee_id';
        }

        $sql = "SELECT " . implode(', ', $select) . "\n"
            . "FROM login_attempts a\n"
            . "LEFT JOIN users u ON a.user_id = u.user_id\n"
            . "LEFT JOIN regions r ON u.region_id = r.region_id\n"
            . "LEFT JOIN branch b ON u.branch_id = b.branch_id\n"
            . "WHERE " . implode(' AND ', $where) . "\n"
            . "ORDER BY a.occurred_at DESC, a.attempt_id DESC\n"
            . "LIMIT {$limit}";

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'items' => $rows]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to load login attempts']);
        }
        break;

    case 'getWeeklyData':
        // 6. Weekly activity data for processor dashboard
        $branch_id = (int)sanitize_input($_GET['branch_id'] ?? 0);
        $days = (int)sanitize_input($_GET['days'] ?? 7);

        if ($branch_id <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Missing branch ID.']);
            break;
        }

        if ($days <= 0) { $days = 7; }
        if ($days > 60) { $days = 60; }

        $start_date = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));

        try {
            $stmt = $pdo->prepare("SELECT DATE(date) AS day, COUNT(*) AS count, SUM(volume) AS total_volume
                                   FROM appointments
                                   WHERE branch_id = ? AND date >= ? AND status != 'cancelled'
                                   GROUP BY DATE(date)
                                   ORDER BY day");
            $stmt->execute([$branch_id, $start_date]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $byDate = [];
            foreach ($rows as $row) {
                $byDate[$row['day']] = [
                    'count' => (int)$row['count'],
                    'volume' => (float)$row['total_volume']
                ];
            }

            $labels = [];
            $counts = [];
            $volumes = [];

            for ($i = $days - 1; $i >= 0; $i--) {
                $date = date('Y-m-d', strtotime('-' . $i . ' days'));
                $labels[] = date('D', strtotime($date));

                if (isset($byDate[$date])) {
                    $counts[] = $byDate[$date]['count'];
                    $volumes[] = (int)$byDate[$date]['volume'];
                } else {
                    $counts[] = 0;
                    $volumes[] = 0;
                }
            }

            echo json_encode([
                'success' => true,
                'labels' => $labels,
                'counts' => $counts,
                'volumes' => $volumes
            ]);
        } catch (\PDOException $e) {
            error_log('getWeeklyData failed: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to retrieve weekly data.']);
        }
        break;

    case 'confirmAppointment':
        // 7. Confirm an appointment from processor dashboard
        $data = json_decode(file_get_contents('php://input'), true);
        $appointment_id = (int)sanitize_input($data['appointment_id'] ?? 0);

        if ($appointment_id <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid appointment ID.']);
            break;
        }

        try {
            if (!isset($_SESSION['loggedin']) || ($_SESSION['user_type'] ?? '') !== 'Processor') {
                http_response_code(401);
                echo json_encode(['success' => false, 'error' => 'Unauthorized']);
                break;
            }

            // Fetch appointment details for notification
            $stmtInfo = $pdo->prepare("SELECT a.reference_number, a.email, a.first_name, a.last_name, a.suffix, a.date, a.time_slot, a.volume, b.branch_name
                FROM appointments a
                LEFT JOIN branch b ON a.branch_id = b.branch_id
                WHERE a.appointment_id = ? LIMIT 1");
            $stmtInfo->execute([$appointment_id]);
            $info = $stmtInfo->fetch(PDO::FETCH_ASSOC);

            $stmt = $pdo->prepare("UPDATE appointments SET status = 'confirmed' WHERE appointment_id = ? AND status != 'cancelled'");
            $stmt->execute([$appointment_id]);
            if ($stmt->rowCount() < 1) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Unable to confirm this appointment.']);
                break;
            }

            // Audit trail (best-effort)
            try {
                $confirmedBy = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : (isset($_SESSION['id']) ? (int)$_SESSION['id'] : null);
                $ref = $info['reference_number'] ?? '';
                if ($ref !== '') {
                    $stmtIns = $pdo->prepare('INSERT INTO confirmed_appointments (appointment_id, reference_number, confirmed_by, source) VALUES (?, ?, ?, ?)');
                    $stmtIns->execute([$appointment_id, $ref, $confirmedBy ?: null, 'processor']);
                }
            } catch (\PDOException $e) {
                error_log('Confirm audit insert failed: ' . $e->getMessage());
            }

            $emailSent = false;
            if ($info && !empty($info['email']) && !empty($info['reference_number'])) {
                $farmerName = trim(($info['first_name'] ?? '') . ' ' . ($info['last_name'] ?? '') . (!empty($info['suffix']) ? (' ' . $info['suffix']) : ''));
                $branchName = $info['branch_name'] ?: 'NFA Branch';
                $extra = '<p style="margin:14px 0 0 0"><strong>Status:</strong> Confirmed</p>';
                $body = nfa_build_farmer_status_email(
                    'Appointment Confirmed',
                    $farmerName !== '' ? $farmerName : 'Farmer',
                    $info['reference_number'],
                    $branchName,
                    $info['date'] ?? '',
                    $info['time_slot'] ?? '',
                    $info['volume'] ?? 0,
                    $extra
                );

                $send = nfa_send_html_email_best_effort($info['email'], 'NFA Appointment Confirmed: ' . $info['reference_number'], $body);
                $emailSent = !empty($send['sent']);
            }

            echo json_encode(['success' => true, 'email_sent' => $emailSent]);

            $actorId = (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? 0);
            $ref = (string)($info['reference_number'] ?? '');
            nfa_log_activity_best_effort($pdo, $actorId, $ref !== '' ? ("Updated appointment status to Confirmed ({$ref})") : 'Updated appointment status to Confirmed');
        } catch (\PDOException $e) {
            error_log('confirmAppointment failed: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to confirm appointment.']);
        }
        break;

    case 'completeAppointment':
        // 8. Mark an appointment as completed and adjust inventory
        $data = json_decode(file_get_contents('php://input'), true);
        $appointment_id = (int)sanitize_input($data['appointment_id'] ?? 0);
        $new_volume = (float)sanitize_input($data['volume'] ?? 0);
        $price = $data['price'] ?? null;
        $price = ($price === null || $price === '') ? null : (float)sanitize_input($price);

        if ($appointment_id <= 0 || $new_volume < 0 || ($price !== null && $price < 0)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid appointment data.']);
            break;
        }

        try {
            if (!isset($_SESSION['loggedin']) || ($_SESSION['user_type'] ?? '') !== 'Processor') {
                http_response_code(401);
                echo json_encode(['success' => false, 'error' => 'Unauthorized']);
                break;
            }

            $pdo->beginTransaction();

            // Fetch appointment and branch
            $stmt = $pdo->prepare("SELECT branch_id, region_id, volume, status, email, first_name, last_name, suffix, reference_number, date, time_slot FROM appointments WHERE appointment_id = ? FOR UPDATE");
            $stmt->execute([$appointment_id]);
            $appt = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$appt) {
                $pdo->rollBack();
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Appointment not found.']);
                break;
            }

            $branch_id = (int)$appt['branch_id'];
            $region_id = (int)$appt['region_id'];
            $status = strtolower((string)($appt['status'] ?? ''));

            // Rule: delivery completion must match today's date AND the current slot window.
            // AM window: 8:00–12:00, PM window: 1:00–5:00 (server time).
            $today = date('Y-m-d');
            $nowMinutes = ((int)date('H')) * 60 + ((int)date('i'));
            $currentSlot = '';
            if ($nowMinutes >= (8 * 60) && $nowMinutes < (12 * 60)) {
                $currentSlot = 'AM';
            } elseif ($nowMinutes >= (13 * 60) && $nowMinutes < (17 * 60)) {
                $currentSlot = 'PM';
            }

            $apptDate = (string)($appt['date'] ?? '');
            $apptSlot = strtoupper((string)($appt['time_slot'] ?? ''));
            if ($apptDate !== $today || $apptSlot === '' || $currentSlot === '' || $apptSlot !== $currentSlot) {
                $pdo->rollBack();
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => 'Cannot submit delivery for this appointment at the current time. Completion is only allowed on the scheduled date and within the scheduled time slot window.'
                ]);
                break;
            }

            if ($status === 'cancelled') {
                $pdo->rollBack();
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Cannot record delivery for a cancelled appointment.']);
                break;
            }
            if ($status === 'completed') {
                $pdo->rollBack();
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'This appointment was already completed.']);
                break;
            }

            // Update appointment volume, price, and status
            $stmtUp = $pdo->prepare("UPDATE appointments SET volume = ?, price = ?, status = 'completed' WHERE appointment_id = ?");
            $stmtUp->execute([$new_volume, $price, $appointment_id]);

            // Audit trail (best-effort)
            try {
                $completedBy = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : (isset($_SESSION['id']) ? (int)$_SESSION['id'] : null);
                $ref = $appt['reference_number'] ?? '';
                if ($ref !== '') {
                    $stmtIns = $pdo->prepare('INSERT INTO completed_appointments (appointment_id, reference_number, completed_by, delivered_volume, price, source) VALUES (?, ?, ?, ?, ?, ?)');
                    $stmtIns->execute([$appointment_id, $ref, $completedBy ?: null, $new_volume, $price, 'processor']);
                }
            } catch (\PDOException $e) {
                error_log('Complete audit insert failed: ' . $e->getMessage());
            }

            // Adjust inventory for the branch
            $stmtCap = $pdo->prepare("SELECT volume_id, inventory FROM volume_capacity WHERE branch_id = ? FOR UPDATE");
            $stmtCap->execute([$branch_id]);
            $cap = $stmtCap->fetch(PDO::FETCH_ASSOC);

            if ($cap) {
                // Capacity guard: prevent accepting deliveries that exceed warehouse capacity
                try {
                    $stmtWc = $pdo->prepare("SELECT warehouse_capacity, inventory FROM volume_capacity WHERE branch_id = ? FOR UPDATE");
                    $stmtWc->execute([$branch_id]);
                    $cap2 = $stmtWc->fetch(PDO::FETCH_ASSOC);
                    $warehouseCapacity = (float)($cap2['warehouse_capacity'] ?? 0);
                    $currentInventory = (float)($cap2['inventory'] ?? 0);

                    if ($warehouseCapacity > 0 && ($currentInventory + $new_volume) > $warehouseCapacity) {
                        $pdo->rollBack();
                        http_response_code(400);
                        echo json_encode([
                            'success' => false,
                            'error' => 'Warehouse capacity is insufficient for this delivery. Please update capacity/inventory before submitting.',
                            'details' => [
                                'warehouse_capacity' => $warehouseCapacity,
                                'current_inventory' => $currentInventory,
                                'attempted_add' => $new_volume,
                                'would_be' => $currentInventory + $new_volume,
                            ]
                        ]);
                        break;
                    }
                } catch (PDOException $e) {
                    // If the capacity check fails unexpectedly, keep existing behavior (best-effort)
                }

                // Inventory represents actual received bags: always ADD delivered volume on completion.
                $new_inventory = (float)$cap['inventory'] + $new_volume;
                $stmtInv = $pdo->prepare("UPDATE volume_capacity SET inventory = ? WHERE branch_id = ?");
                $stmtInv->execute([$new_inventory, $branch_id]);
            } else {
                // If capacity row doesn't exist for this branch, create it so inventory can be tracked.
                $stmtIns = $pdo->prepare("INSERT INTO volume_capacity (region_id, branch_id, warehouse_capacity, inventory) VALUES (?, ?, ?, ?)");
                $stmtIns->execute([$region_id, $branch_id, 0, $new_volume]);
                $new_inventory = $new_volume;
            }

            $pdo->commit();

            // Farmer email notification (best-effort)
            $emailSent = false;
            try {
                if (!empty($appt['email']) && !empty($appt['reference_number'])) {
                    $stmtBranch = $pdo->prepare("SELECT branch_name FROM branch WHERE branch_id = ? LIMIT 1");
                    $stmtBranch->execute([$branch_id]);
                    $branchRow = $stmtBranch->fetch(PDO::FETCH_ASSOC);
                    $branchName = ($branchRow && !empty($branchRow['branch_name'])) ? $branchRow['branch_name'] : 'NFA Branch';

                    $farmerName = trim(($appt['first_name'] ?? '') . ' ' . ($appt['last_name'] ?? '') . (!empty($appt['suffix']) ? (' ' . $appt['suffix']) : ''));
                    $extra = '<p style="margin:14px 0 0 0"><strong>Status:</strong> Completed</p>'
                        . '<p style="margin:6px 0 0 0">Your delivery has been recorded. Thank you.</p>';

                    $body = nfa_build_farmer_status_email(
                        'Appointment Completed',
                        $farmerName !== '' ? $farmerName : 'Farmer',
                        $appt['reference_number'],
                        $branchName,
                        $appt['date'] ?? '',
                        $appt['time_slot'] ?? '',
                        $new_volume,
                        $extra
                    );

                    $send = nfa_send_html_email_best_effort($appt['email'], 'NFA Appointment Completed: ' . $appt['reference_number'], $body);
                    $emailSent = !empty($send['sent']);
                }
            } catch (Exception $e) {
                error_log('completeAppointment farmer email failed: ' . $e->getMessage());
            }

            echo json_encode(['success' => true, 'inventory' => $new_inventory, 'email_sent' => $emailSent]);

            $actorId = (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? 0);
            $ref = (string)($appt['reference_number'] ?? '');
            nfa_log_activity_best_effort($pdo, $actorId, $ref !== '' ? ("Received delivery / completed appointment ({$ref})") : 'Received delivery / completed appointment');
        } catch (\PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('completeAppointment failed: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to record delivery.']);
        }
        break;

    case 'rescheduleAppointment':
        // 9. Reschedule an appointment to a new date/slot
        $data = json_decode(file_get_contents('php://input'), true);
        $appointment_id = (int)sanitize_input($data['appointment_id'] ?? 0);
        $date = sanitize_input($data['date'] ?? '');
        $time_slot = strtoupper(sanitize_input($data['time_slot'] ?? ''));

        if ($appointment_id <= 0 || !$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !in_array($time_slot, ['AM','PM'], true)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid reschedule data.']);
            break;
        }

        try {
            if (!isset($_SESSION['loggedin']) || ($_SESSION['user_type'] ?? '') !== 'Processor') {
                http_response_code(401);
                echo json_encode(['success' => false, 'error' => 'Unauthorized']);
                break;
            }

            $pdo->beginTransaction();

            // Fetch appointment and branch
            $stmt = $pdo->prepare("SELECT branch_id, volume, email, first_name, last_name, suffix, reference_number, date AS old_date, time_slot AS old_time_slot FROM appointments WHERE appointment_id = ? FOR UPDATE");
            $stmt->execute([$appointment_id]);
            $appt = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$appt) {
                $pdo->rollBack();
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Appointment not found.']);
                break;
            }
            $branch_id = (int)$appt['branch_id'];

            // Rule: must not reschedule into the exact same date+slot
            $oldDate = (string)($appt['old_date'] ?? '');
            $oldSlot = strtoupper((string)($appt['old_time_slot'] ?? ''));
            if ($oldDate !== '' && $oldSlot !== '' && $date === $oldDate && $time_slot === $oldSlot) {
                $pdo->rollBack();
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Cannot reschedule to the same date and time slot.']);
                break;
            }

            // Get default slot capacity similar to getBranchInfo (default rows may have NULL or empty string date)
            $stmtCap = $pdo->prepare("SELECT capacity_am, capacity_pm FROM branch_slot_capacity WHERE branch_id = :branch_id AND (`date` IS NULL OR `date` = '')");
            $stmtCap->bindParam(':branch_id', $branch_id, PDO::PARAM_INT);
            $stmtCap->execute();
            $default_capacity = $stmtCap->fetch(PDO::FETCH_ASSOC);
            if (!$default_capacity) {
                $stmtAny = $pdo->prepare("SELECT capacity_am, capacity_pm FROM branch_slot_capacity WHERE branch_id = :branch_id ORDER BY capacity_id DESC LIMIT 1");
                $stmtAny->bindParam(':branch_id', $branch_id, PDO::PARAM_INT);
                $stmtAny->execute();
                $default_capacity = $stmtAny->fetch(PDO::FETCH_ASSOC);
            }
            $default_capacity = $default_capacity ?: ['capacity_am' => 5, 'capacity_pm' => 5];

            $slot_column = $time_slot === 'PM' ? 'capacity_pm' : 'capacity_am';
            $slot_capacity = (int)$default_capacity[$slot_column];

            // Count existing bookings for that date/slot excluding this appointment
            $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE branch_id = ? AND `date` = ? AND time_slot = ? AND status != 'cancelled' AND appointment_id != ?");
            $stmtCount->execute([$branch_id, $date, $time_slot, $appointment_id]);
            $booked = (int)$stmtCount->fetchColumn();

            if ($booked >= $slot_capacity) {
                $pdo->rollBack();
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Selected slot is already full.']);
                break;
            }

            // Apply reschedule and mark as rescheduled (so farmers can see it)
            $stmtUp = $pdo->prepare("UPDATE appointments SET `date` = ?, time_slot = ?, status = 'rescheduled' WHERE appointment_id = ?");
            $stmtUp->execute([$date, $time_slot, $appointment_id]);

            // Audit trail (best-effort)
            try {
                $rescheduledBy = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : (isset($_SESSION['id']) ? (int)$_SESSION['id'] : null);
                $ref = $appt['reference_number'] ?? '';
                if ($ref !== '') {
                    $stmtIns = $pdo->prepare('INSERT INTO rescheduled_appointments (appointment_id, reference_number, old_date, old_time_slot, new_date, new_time_slot, rescheduled_by, source) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                    $stmtIns->execute([$appointment_id, $ref, $appt['old_date'] ?? null, $appt['old_time_slot'] ?? null, $date, $time_slot, $rescheduledBy ?: null, 'processor']);
                }
            } catch (\PDOException $e) {
                error_log('Reschedule audit insert failed: ' . $e->getMessage());
            }

            $pdo->commit();

            // Farmer email notification (best-effort)
            $emailSent = false;
            try {
                if (!empty($appt['email']) && !empty($appt['reference_number'])) {
                    $stmtBranch = $pdo->prepare("SELECT branch_name FROM branch WHERE branch_id = ? LIMIT 1");
                    $stmtBranch->execute([$branch_id]);
                    $branchRow = $stmtBranch->fetch(PDO::FETCH_ASSOC);
                    $branchName = ($branchRow && !empty($branchRow['branch_name'])) ? $branchRow['branch_name'] : 'NFA Branch';

                    $farmerName = trim(($appt['first_name'] ?? '') . ' ' . ($appt['last_name'] ?? '') . (!empty($appt['suffix']) ? (' ' . $appt['suffix']) : ''));
                    $oldDate = $appt['old_date'] ?? '';
                    $oldSlot = strtoupper((string)($appt['old_time_slot'] ?? ''));
                    $oldDateSafe = htmlspecialchars(nfa_format_date_long($oldDate), ENT_QUOTES, 'UTF-8');
                    $oldSlotSafe = htmlspecialchars($oldSlot, ENT_QUOTES, 'UTF-8');
                    $oldWinSafe = htmlspecialchars(nfa_session_window($oldSlot), ENT_QUOTES, 'UTF-8');

                    $extra = '<p style="margin:14px 0 0 0"><strong>Status:</strong> Rescheduled</p>'
                        . '<p style="margin:6px 0 0 0">Previous schedule: <strong>' . $oldDateSafe . '</strong> (' . $oldSlotSafe . ' — ' . $oldWinSafe . ')</p>'
                        . '<p style="margin:6px 0 0 0">New schedule is shown in the details above.</p>';

                    $body = nfa_build_farmer_status_email(
                        'Appointment Rescheduled',
                        $farmerName !== '' ? $farmerName : 'Farmer',
                        $appt['reference_number'],
                        $branchName,
                        $date,
                        $time_slot,
                        $appt['volume'] ?? 0,
                        $extra
                    );

                    $send = nfa_send_html_email_best_effort($appt['email'], 'NFA Appointment Rescheduled: ' . $appt['reference_number'], $body);
                    $emailSent = !empty($send['sent']);
                }
            } catch (Exception $e) {
                error_log('rescheduleAppointment farmer email failed: ' . $e->getMessage());
            }

            echo json_encode(['success' => true, 'email_sent' => $emailSent]);

            $actorId = (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? 0);
            $ref = (string)($appt['reference_number'] ?? '');
            nfa_log_activity_best_effort($pdo, $actorId, $ref !== '' ? ("Rescheduled appointment ({$ref})") : 'Rescheduled appointment');
        } catch (\PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('rescheduleAppointment failed: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to reschedule appointment.']);
        }
        break;

    case 'autoCancelExpiredAppointments':
        // Auto-cancel appointments that are already past their schedule window (processor branch only)
        try {
            if (!isset($_SESSION['loggedin']) || ($_SESSION['user_type'] ?? '') !== 'Processor') {
                http_response_code(401);
                echo json_encode(['success' => false, 'error' => 'Unauthorized']);
                break;
            }

            $branchId = (int)($_SESSION['branch_id'] ?? 0);
            if ($branchId <= 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Missing branch context.']);
                break;
            }

            // Ensure audit schema exists (best-effort)
            nfa_ensure_status_audit_schema($pdo);

            $today = date('Y-m-d');
            $nowMinutes = ((int)date('H')) * 60 + ((int)date('i'));
            $pmStarted = $nowMinutes >= (13 * 60);
            $pmEnded = $nowMinutes >= (17 * 60);

            // Select appointments that are now in the past relative to schedule windows.
            // - Any date < today: expired
            // - Same-day AM becomes expired once PM starts (1:00 PM)
            // - Same-day PM becomes expired after PM window ends (5:00 PM)
            $stmt = $pdo->prepare(
                "SELECT a.appointment_id, a.reference_number, a.email, a.first_name, a.last_name, a.suffix, a.date, a.time_slot, a.volume,
                        b.branch_name
                 FROM appointments a
                 LEFT JOIN branch b ON a.branch_id = b.branch_id
                 WHERE a.branch_id = ?
                   AND LOWER(a.status) IN ('pending','confirmed','rescheduled')
                   AND (
                        a.date < ?
                        OR (a.date = ? AND UPPER(a.time_slot) = 'AM' AND ? = 1)
                        OR (a.date = ? AND UPPER(a.time_slot) = 'PM' AND ? = 1)
                   )"
            );
            $stmt->execute([$branchId, $today, $today, $pmStarted ? 1 : 0, $today, $pmEnded ? 1 : 0]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (!$rows) {
                echo json_encode(['success' => true, 'cancelled_count' => 0, 'email_sent' => 0]);
                break;
            }

            $cancelledCount = 0;
            $emailSentCount = 0;

            foreach ($rows as $appt) {
                $appointmentId = (int)($appt['appointment_id'] ?? 0);
                if ($appointmentId <= 0) continue;

                try {
                    $pdo->beginTransaction();

                    // Lock & re-check current status to avoid racing with manual updates
                    $stmtLock = $pdo->prepare("SELECT status FROM appointments WHERE appointment_id = ? FOR UPDATE");
                    $stmtLock->execute([$appointmentId]);
                    $curStatus = strtolower((string)($stmtLock->fetchColumn() ?? ''));
                    if (!in_array($curStatus, ['pending', 'confirmed', 'rescheduled'], true)) {
                        $pdo->rollBack();
                        continue;
                    }

                    $stmtUp = $pdo->prepare("UPDATE appointments SET status = 'cancelled' WHERE appointment_id = ? AND LOWER(status) IN ('pending','confirmed','rescheduled')");
                    $stmtUp->execute([$appointmentId]);
                    if ($stmtUp->rowCount() < 1) {
                        $pdo->rollBack();
                        continue;
                    }

                    // Cancellation audit trail (best-effort)
                    try {
                        $ref = (string)($appt['reference_number'] ?? '');
                        if ($ref !== '' && nfa_table_exists($pdo, 'cancelled_appointments')) {
                            $detail = 'Auto-cancelled by system: schedule window has passed.';
                            $stmtIns = $pdo->prepare('INSERT INTO cancelled_appointments (appointment_id, reference_number, reason_code, reason_detail, cancelled_by, source) VALUES (?, ?, ?, ?, ?, ?)');
                            $stmtIns->execute([$appointmentId, $ref, 'system_expired', $detail, null, 'system']);
                        }
                    } catch (PDOException $e) {
                        // best-effort
                    }

                    $pdo->commit();
                    $cancelledCount += 1;
                } catch (PDOException $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    continue;
                }

                // Farmer email notification (best-effort)
                try {
                    $email = (string)($appt['email'] ?? '');
                    $ref = (string)($appt['reference_number'] ?? '');
                    if ($email !== '' && $ref !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        $farmerName = trim(($appt['first_name'] ?? '') . ' ' . ($appt['last_name'] ?? '') . (!empty($appt['suffix']) ? (' ' . $appt['suffix']) : ''));
                        $branchName = (string)($appt['branch_name'] ?? 'NFA Branch');

                        $extra = '<p style="margin:14px 0 0 0"><strong>Status:</strong> Cancelled</p>'
                            . '<p style="margin:6px 0 0 0">Reason: This appointment was automatically cancelled because the scheduled time window has already passed.</p>';

                        $body = nfa_build_farmer_status_email(
                            'Appointment Cancelled',
                            $farmerName !== '' ? $farmerName : 'Farmer',
                            $ref,
                            $branchName,
                            $appt['date'] ?? '',
                            $appt['time_slot'] ?? '',
                            $appt['volume'] ?? 0,
                            $extra
                        );

                        $send = nfa_send_html_email_best_effort($email, 'NFA Appointment Cancelled: ' . $ref, $body);
                        if (!empty($send['sent'])) {
                            $emailSentCount += 1;
                        }
                    }
                } catch (Exception $e) {
                    // best-effort
                }
            }

            echo json_encode([
                'success' => true,
                'cancelled_count' => $cancelledCount,
                'email_sent' => $emailSentCount
            ]);
        } catch (PDOException $e) {
            error_log('autoCancelExpiredAppointments failed: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to auto-cancel expired appointments.']);
        }
        break;

    case 'getDashboardData':
        // 10. Lightweight dashboard refresh data
        $branch_id = (int)sanitize_input($_GET['branch_id'] ?? 0);

        if ($branch_id <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Missing branch ID.']);
            break;
        }

        try {
            // Capacity and inventory
            $stmtCap = $pdo->prepare("SELECT warehouse_capacity, inventory FROM volume_capacity WHERE branch_id = ?");
            $stmtCap->execute([$branch_id]);
            $cap = $stmtCap->fetch(PDO::FETCH_ASSOC) ?: ['warehouse_capacity' => 0, 'inventory' => 0];

            $capacity = (float)$cap['warehouse_capacity'];
            $inventory = (float)$cap['inventory'];
            $available = max(0, $capacity - $inventory);

            // Pending appointments count (all future + today)
            $today = date('Y-m-d');
            $stmtPending = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE branch_id = ? AND status = 'pending' AND date >= ?");
            $stmtPending->execute([$branch_id, $today]);
            $pending = (int)$stmtPending->fetchColumn();

            echo json_encode([
                'success' => true,
                'capacity' => $capacity,
                'inventory' => $inventory,
                'available' => $available,
                'pending_count' => $pending
            ]);
        } catch (\PDOException $e) {
            error_log('getDashboardData failed: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to refresh dashboard data.']);
        }
        break;

    case 'getCapacityManagementData':
        // Processor-only: fetch branch capacity/inventory with labels
        try {
            if (!isset($_SESSION['loggedin']) || ($_SESSION['user_type'] ?? '') !== 'Processor') {
                http_response_code(401);
                echo json_encode(['success' => false, 'error' => 'Unauthorized.']);
                break;
            }

            $branch_id = (int)($_SESSION['branch_id'] ?? 0);
            if ($branch_id <= 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Missing branch context.']);
                break;
            }

            $stmtBranch = $pdo->prepare("SELECT b.branch_id, b.branch_name, b.region_id, r.region_name FROM branch b JOIN regions r ON b.region_id = r.region_id WHERE b.branch_id = ? LIMIT 1");
            $stmtBranch->execute([$branch_id]);
            $branch = $stmtBranch->fetch(PDO::FETCH_ASSOC);
            if (!$branch) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Branch not found.']);
                break;
            }

            $stmtCap = $pdo->prepare('SELECT volume_id, warehouse_capacity, inventory FROM volume_capacity WHERE branch_id = ? LIMIT 1');
            $stmtCap->execute([$branch_id]);
            $cap = $stmtCap->fetch(PDO::FETCH_ASSOC);

            $warehouse_capacity = (float)($cap['warehouse_capacity'] ?? 0);
            $inventory = (float)($cap['inventory'] ?? 0);
            $available = max(0, $warehouse_capacity - $inventory);
            $percent = ($warehouse_capacity > 0) ? ($inventory / $warehouse_capacity) * 100 : 0;

            $freeze = nfa_get_effective_appointment_freeze($pdo, $branch_id);

            echo json_encode([
                'success' => true,
                'data' => [
                    'volume_id' => $cap ? (int)$cap['volume_id'] : null,
                    'branch_id' => (int)$branch['branch_id'],
                    'branch_name' => (string)$branch['branch_name'],
                    'region_id' => (int)$branch['region_id'],
                    'region_name' => (string)$branch['region_name'],
                    'warehouse_capacity' => $warehouse_capacity,
                    'inventory' => $inventory,
                    'available' => $available,
                    'percent' => $percent,
                    'appointment_freeze' => $freeze
                ]
            ]);
        } catch (\PDOException $e) {
            error_log('getCapacityManagementData failed: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to load capacity data.']);
        }
        break;

    case 'processorGetAppointmentFreeze':
        try {
            nfa_require_role('Processor');
            $branch_id = (int)($_SESSION['branch_id'] ?? 0);
            if ($branch_id <= 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Missing branch context.']);
                break;
            }

            $freeze = nfa_get_effective_appointment_freeze($pdo, $branch_id);
            echo json_encode(['success' => true, 'data' => $freeze]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to load freeze status.']);
        }
        break;

    case 'processorSetAppointmentFreeze':
        try {
            nfa_require_role('Processor');
            $branch_id = (int)($_SESSION['branch_id'] ?? 0);
            if ($branch_id <= 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Missing branch context.']);
                break;
            }

            $payload = nfa_request_payload();
            $isFrozenRaw = $payload['is_frozen'] ?? $payload['frozen'] ?? null;
            $isFrozen = (int)(is_bool($isFrozenRaw) ? ($isFrozenRaw ? 1 : 0) : (is_numeric($isFrozenRaw) ? (int)$isFrozenRaw : 0));
            $isFrozen = $isFrozen ? 1 : 0;

            $reason = null;
            if ($isFrozen) {
                $r = sanitize_input($payload['reason'] ?? '');
                $r = is_string($r) ? trim($r) : '';
                if ($r !== '') {
                    if (mb_strlen($r) > 255) $r = mb_substr($r, 0, 255);
                    $reason = $r;
                }
            }

            nfa_ensure_branch_appointment_freeze_schema($pdo);
            $actorId = (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? 0);
            $frozenAt = $isFrozen ? date('Y-m-d H:i:s') : null;
            $frozenBy = $isFrozen ? ($actorId > 0 ? $actorId : null) : null;

            // Upsert per-branch freeze flag
            $stmt = $pdo->prepare(
                'INSERT INTO branch_appointment_freeze (branch_id, is_frozen, reason, frozen_by, frozen_at) '
                . 'VALUES (?, ?, ?, ?, ?) '
                . 'ON DUPLICATE KEY UPDATE is_frozen = VALUES(is_frozen), reason = VALUES(reason), frozen_by = VALUES(frozen_by), frozen_at = VALUES(frozen_at)'
            );
            $stmt->execute([$branch_id, $isFrozen, $reason, $frozenBy, $frozenAt]);

            nfa_log_activity_best_effort(
                $pdo,
                $actorId,
                $isFrozen ? 'Froze new appointments (warehouse intake paused)' : 'Unfroze new appointments (warehouse intake resumed)'
            );

            $freeze = nfa_get_effective_appointment_freeze($pdo, $branch_id);
            echo json_encode(['success' => true, 'data' => $freeze]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to update freeze status.']);
        }
        break;

    case 'updateCapacityManagement':
        // Processor-only: update warehouse_capacity and/or inventory for current branch
        try {
            if (!isset($_SESSION['loggedin']) || ($_SESSION['user_type'] ?? '') !== 'Processor') {
                http_response_code(401);
                echo json_encode(['success' => false, 'error' => 'Unauthorized.']);
                break;
            }

            $branch_id = (int)($_SESSION['branch_id'] ?? 0);
            if ($branch_id <= 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Missing branch context.']);
                break;
            }

            $raw = file_get_contents('php://input');
            $payload = json_decode($raw, true);
            if (!is_array($payload)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Invalid request body.']);
                break;
            }

            $hasCapacity = array_key_exists('warehouse_capacity', $payload);
            $hasInventory = array_key_exists('inventory', $payload);
            if (!$hasCapacity && !$hasInventory) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Nothing to update.']);
                break;
            }

            $reason = null;
            if (array_key_exists('reason', $payload)) {
                $reasonRaw = $payload['reason'];
                if (is_string($reasonRaw)) {
                    $reason = trim((string)sanitize_input($reasonRaw));
                }
            }

            $newCapacity = null;
            $newInventory = null;
            if ($hasCapacity) {
                $newCapacity = $payload['warehouse_capacity'];
                if (!is_numeric($newCapacity)) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'error' => 'Warehouse capacity must be numeric.']);
                    break;
                }
                $newCapacity = (float)$newCapacity;
                if ($newCapacity < 0) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'error' => 'Warehouse capacity cannot be negative.']);
                    break;
                }
            }
            if ($hasInventory) {
                $newInventory = $payload['inventory'];
                if (!is_numeric($newInventory)) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'error' => 'Inventory must be numeric.']);
                    break;
                }
                $newInventory = (float)$newInventory;
                if ($newInventory < 0) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'error' => 'Inventory cannot be negative.']);
                    break;
                }
            }

            // Load existing row (or create one if missing)
            $stmtBranch = $pdo->prepare('SELECT region_id FROM branch WHERE branch_id = ? LIMIT 1');
            $stmtBranch->execute([$branch_id]);
            $branchRow = $stmtBranch->fetch(PDO::FETCH_ASSOC);
            if (!$branchRow) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Branch not found.']);
                break;
            }
            $region_id = (int)$branchRow['region_id'];

            $stmtCap = $pdo->prepare('SELECT volume_id, warehouse_capacity, inventory FROM volume_capacity WHERE branch_id = ? LIMIT 1');
            $stmtCap->execute([$branch_id]);
            $cap = $stmtCap->fetch(PDO::FETCH_ASSOC);

            if (!$cap) {
                $pdo->prepare('INSERT INTO volume_capacity (region_id, branch_id, warehouse_capacity, inventory) VALUES (?, ?, 0, 0)')
                    ->execute([$region_id, $branch_id]);
                $stmtCap->execute([$branch_id]);
                $cap = $stmtCap->fetch(PDO::FETCH_ASSOC);
            }

            $currentCapacity = (float)($cap['warehouse_capacity'] ?? 0);
            $currentInventory = (float)($cap['inventory'] ?? 0);

            $finalCapacity = $hasCapacity ? $newCapacity : $currentCapacity;
            $finalInventory = $hasInventory ? $newInventory : $currentInventory;

            if ($finalCapacity < 0 || $finalInventory < 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Values cannot be negative.']);
                break;
            }
            if ($finalCapacity > 0 && $finalInventory > $finalCapacity) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Inventory cannot exceed warehouse capacity.']);
                break;
            }

            $stmtUpdate = $pdo->prepare('UPDATE volume_capacity SET warehouse_capacity = ?, inventory = ? WHERE branch_id = ?');
            $stmtUpdate->execute([$finalCapacity, $finalInventory, $branch_id]);

            // Audit trail (best-effort)
            if (abs($finalCapacity - $currentCapacity) > 0.00001 || abs($finalInventory - $currentInventory) > 0.00001) {
                $actorId = (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? 0);
                $actorRole = (string)($_SESSION['user_type'] ?? '');
                nfa_log_capacity_change_best_effort(
                    $pdo,
                    $branch_id,
                    $currentCapacity,
                    $finalCapacity,
                    $currentInventory,
                    $finalInventory,
                    $actorId,
                    $actorRole,
                    $reason
                );
            }

            $available = max(0, $finalCapacity - $finalInventory);
            $percent = ($finalCapacity > 0) ? ($finalInventory / $finalCapacity) * 100 : 0;

            $freeze = nfa_get_effective_appointment_freeze($pdo, $branch_id);

            echo json_encode([
                'success' => true,
                'data' => [
                    'branch_id' => $branch_id,
                    'warehouse_capacity' => $finalCapacity,
                    'inventory' => $finalInventory,
                    'available' => $available,
                    'percent' => $percent,
                    'appointment_freeze' => $freeze
                ]
            ]);

            $actorId = (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? 0);
            nfa_log_activity_best_effort($pdo, $actorId, 'Updated warehouse capacity/inventory');
        } catch (\PDOException $e) {
            error_log('updateCapacityManagement failed: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to update capacity.']);
        }
        break;

    // --- Admin: Cross-branch capacity monitoring + audit trail ---
    // --- Admin: Master data management (Regions + Farmer Types) ---
    case 'adminListRegions':
        try {
            nfa_require_role('Admin');
            $stmt = $pdo->query('SELECT region_id, region_name FROM regions ORDER BY region_name');
            $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
            echo json_encode(['success' => true, 'data' => $rows]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to load regions']);
        }
        break;

    case 'adminCreateRegion':
        try {
            nfa_require_role('Admin');
            $payload = nfa_request_payload();
            $name = sanitize_input($payload['region_name'] ?? '');
            $name = is_string($name) ? trim($name) : '';
            $initialBranchName = sanitize_input($payload['initial_branch_name'] ?? '');
            $initialBranchName = is_string($initialBranchName) ? trim($initialBranchName) : '';
            if ($name === '' || mb_strlen($name) > 255) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Region name is required (max 255 chars).']);
                break;
            }

            // Requirement: when creating a new region, also create at least one branch under it.
            if ($initialBranchName === '' || mb_strlen($initialBranchName) > 255) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Initial branch name is required (max 255 chars).']);
                break;
            }

            $pdo->beginTransaction();

            $stmt = $pdo->prepare('INSERT INTO regions (region_name) VALUES (?)');
            $stmt->execute([$name]);
            $newId = (int)$pdo->lastInsertId();

            $stmtB = $pdo->prepare('INSERT INTO branch (region_id, branch_name) VALUES (?, ?)');
            $stmtB->execute([$newId, $initialBranchName]);
            $newBranchId = (int)$pdo->lastInsertId();

            $pdo->commit();

            $actorId = (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? 0);
            nfa_log_activity_best_effort($pdo, $actorId, 'Created region: ' . $name . ' (branch: ' . $initialBranchName . ')');

            echo json_encode(['success' => true, 'data' => ['region_id' => $newId, 'region_name' => $name, 'branch_id' => $newBranchId, 'branch_name' => $initialBranchName]]);
        } catch (PDOException $e) {
            try { if ($pdo->inTransaction()) $pdo->rollBack(); } catch (Exception $_) {}
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to create region']);
        }
        break;

    case 'adminUpdateRegion':
        try {
            nfa_require_role('Admin');
            $payload = nfa_request_payload();
            $regionId = (int)sanitize_input($payload['region_id'] ?? 0);
            $name = sanitize_input($payload['region_name'] ?? '');
            $name = is_string($name) ? trim($name) : '';
            if ($regionId <= 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Missing region_id']);
                break;
            }
            if ($name === '' || mb_strlen($name) > 255) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Region name is required (max 255 chars).']);
                break;
            }

            $stmt = $pdo->prepare('UPDATE regions SET region_name = ? WHERE region_id = ?');
            $stmt->execute([$name, $regionId]);

            $actorId = (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? 0);
            nfa_log_activity_best_effort($pdo, $actorId, 'Updated region #' . $regionId);

            echo json_encode(['success' => true, 'data' => ['region_id' => $regionId, 'region_name' => $name]]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to update region']);
        }
        break;

    case 'adminDeleteRegion':
        try {
            nfa_require_role('Admin');
            $payload = nfa_request_payload();
            $regionId = (int)sanitize_input($payload['region_id'] ?? 0);
            if ($regionId <= 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Missing region_id']);
                break;
            }

            // Safe delete: block if used by any branches (even though FK could cascade).
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM branch WHERE region_id = ?');
            $stmt->execute([$regionId]);
            $inUse = (int)$stmt->fetchColumn();
            if ($inUse > 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Cannot delete region: it is linked to existing branches.']);
                break;
            }

            $stmtName = $pdo->prepare('SELECT region_name FROM regions WHERE region_id = ?');
            $stmtName->execute([$regionId]);
            $oldName = (string)($stmtName->fetchColumn() ?: '');

            $stmtDel = $pdo->prepare('DELETE FROM regions WHERE region_id = ?');
            $stmtDel->execute([$regionId]);

            $actorId = (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? 0);
            nfa_log_activity_best_effort($pdo, $actorId, 'Deleted region #' . $regionId . ($oldName !== '' ? (': ' . $oldName) : ''));

            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to delete region']);
        }
        break;

    case 'adminListFarmerTypes':
        try {
            nfa_require_role('Admin');
            $stmt = $pdo->query('SELECT farmer_type_id, type_name FROM farmer_type ORDER BY type_name');
            $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
            echo json_encode(['success' => true, 'data' => $rows]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to load farmer types']);
        }
        break;

    case 'adminCreateFarmerType':
        try {
            nfa_require_role('Admin');
            $payload = nfa_request_payload();
            $name = sanitize_input($payload['type_name'] ?? '');
            $name = is_string($name) ? trim($name) : '';
            if ($name === '' || mb_strlen($name) > 50) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Type name is required (max 50 chars).']);
                break;
            }

            $stmt = $pdo->prepare('INSERT INTO farmer_type (type_name) VALUES (?)');
            $stmt->execute([$name]);
            $newId = (int)$pdo->lastInsertId();

            $actorId = (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? 0);
            nfa_log_activity_best_effort($pdo, $actorId, 'Created farmer type: ' . $name);

            echo json_encode(['success' => true, 'data' => ['farmer_type_id' => $newId, 'type_name' => $name]]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to create farmer type']);
        }
        break;

    case 'adminUpdateFarmerType':
        try {
            nfa_require_role('Admin');
            $payload = nfa_request_payload();
            $typeId = (int)sanitize_input($payload['farmer_type_id'] ?? 0);
            $name = sanitize_input($payload['type_name'] ?? '');
            $name = is_string($name) ? trim($name) : '';
            if ($typeId <= 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Missing farmer_type_id']);
                break;
            }
            if ($name === '' || mb_strlen($name) > 50) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Type name is required (max 50 chars).']);
                break;
            }

            $stmt = $pdo->prepare('UPDATE farmer_type SET type_name = ? WHERE farmer_type_id = ?');
            $stmt->execute([$name, $typeId]);

            $actorId = (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? 0);
            nfa_log_activity_best_effort($pdo, $actorId, 'Updated farmer type #' . $typeId);

            echo json_encode(['success' => true, 'data' => ['farmer_type_id' => $typeId, 'type_name' => $name]]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to update farmer type']);
        }
        break;

    case 'adminDeleteFarmerType':
        try {
            nfa_require_role('Admin');
            $payload = nfa_request_payload();
            $typeId = (int)sanitize_input($payload['farmer_type_id'] ?? 0);
            if ($typeId <= 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Missing farmer_type_id']);
                break;
            }

            // Safe delete: block if used by any appointment.
            if (nfa_table_exists($pdo, 'appointments')) {
                $stmt = $pdo->prepare('SELECT COUNT(*) FROM appointments WHERE farmer_type_id = ?');
                $stmt->execute([$typeId]);
                $inUse = (int)$stmt->fetchColumn();
                if ($inUse > 0) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'error' => 'Cannot delete farmer type: it is used by existing appointments.']);
                    break;
                }
            }

            $stmtName = $pdo->prepare('SELECT type_name FROM farmer_type WHERE farmer_type_id = ?');
            $stmtName->execute([$typeId]);
            $oldName = (string)($stmtName->fetchColumn() ?: '');

            $stmtDel = $pdo->prepare('DELETE FROM farmer_type WHERE farmer_type_id = ?');
            $stmtDel->execute([$typeId]);

            $actorId = (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? 0);
            nfa_log_activity_best_effort($pdo, $actorId, 'Deleted farmer type #' . $typeId . ($oldName !== '' ? (': ' . $oldName) : ''));

            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to delete farmer type']);
        }
        break;

    // --- Branches (linked to regions) ---
    case 'adminListBranches':
        try {
            nfa_require_role('Admin');
            $regionId = (int)sanitize_input($_GET['region_id'] ?? 0);
            $where = '';
            $params = [];
            if ($regionId > 0) {
                $where = 'WHERE b.region_id = ?';
                $params[] = $regionId;
            }

            $stmt = $pdo->prepare(
                'SELECT b.branch_id, b.branch_name, b.region_id, r.region_name, b.address, b.contact_number, b.website_link '
                . 'FROM branch b '
                . 'JOIN regions r ON r.region_id = b.region_id '
                . $where
                . ' ORDER BY r.region_name, b.branch_name'
            );
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'data' => $rows ?: []]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to load branches']);
        }
        break;

    case 'adminCreateBranch':
        try {
            nfa_require_role('Admin');
            $payload = nfa_request_payload();
            $regionId = (int)sanitize_input($payload['region_id'] ?? 0);
            $branchName = sanitize_input($payload['branch_name'] ?? '');
            $branchName = is_string($branchName) ? trim($branchName) : '';
            $address = sanitize_input($payload['address'] ?? '');
            $address = is_string($address) ? trim($address) : '';
            $contact = sanitize_input($payload['contact_number'] ?? '');
            $contact = is_string($contact) ? trim($contact) : '';
            $website = sanitize_input($payload['website_link'] ?? '');
            $website = is_string($website) ? trim($website) : '';

            if ($regionId <= 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Region is required.']);
                break;
            }
            if ($branchName === '' || mb_strlen($branchName) > 255) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Branch name is required (max 255 chars).']);
                break;
            }

            // Ensure region exists
            $stmtR = $pdo->prepare('SELECT 1 FROM regions WHERE region_id = ?');
            $stmtR->execute([$regionId]);
            if (!$stmtR->fetchColumn()) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Region not found.']);
                break;
            }

            $stmt = $pdo->prepare('INSERT INTO branch (region_id, branch_name, address, contact_number, website_link) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([
                $regionId,
                $branchName,
                $address !== '' ? $address : null,
                $contact !== '' ? $contact : null,
                $website !== '' ? $website : null
            ]);
            $newId = (int)$pdo->lastInsertId();

            $actorId = (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? 0);
            nfa_log_activity_best_effort($pdo, $actorId, 'Created branch #' . $newId . ': ' . $branchName);

            echo json_encode(['success' => true, 'data' => ['branch_id' => $newId, 'region_id' => $regionId, 'branch_name' => $branchName]]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to create branch']);
        }
        break;

    case 'adminUpdateBranch':
        try {
            nfa_require_role('Admin');
            $payload = nfa_request_payload();
            $branchId = (int)sanitize_input($payload['branch_id'] ?? 0);
            $branchName = sanitize_input($payload['branch_name'] ?? '');
            $branchName = is_string($branchName) ? trim($branchName) : '';

            if ($branchId <= 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Missing branch_id']);
                break;
            }
            if ($branchName === '' || mb_strlen($branchName) > 255) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Branch name is required (max 255 chars).']);
                break;
            }

            $stmt = $pdo->prepare('UPDATE branch SET branch_name = ? WHERE branch_id = ?');
            $stmt->execute([$branchName, $branchId]);

            $actorId = (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? 0);
            nfa_log_activity_best_effort($pdo, $actorId, 'Updated branch #' . $branchId);

            echo json_encode(['success' => true, 'data' => ['branch_id' => $branchId, 'branch_name' => $branchName]]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to update branch']);
        }
        break;

    case 'adminDeleteBranch':
        try {
            nfa_require_role('Admin');
            $payload = nfa_request_payload();
            $branchId = (int)sanitize_input($payload['branch_id'] ?? 0);
            if ($branchId <= 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Missing branch_id']);
                break;
            }

            // Safe delete: block if referenced anywhere important.
            $refChecks = [
                ['appointments', 'branch_id', 'appointments'],
                ['volume_capacity', 'branch_id', 'volume capacity'],
                ['branch_slot_capacity', 'branch_id', 'slot capacity'],
                ['stock_history', 'branch_id', 'stock history'],
                ['users', 'branch_id', 'users'],
                ['support_chats', 'branch_id', 'support chats'],
                ['capacity_change_logs', 'branch_id', 'capacity audit logs'],
            ];

            foreach ($refChecks as $chk) {
                [$table, $col, $label] = $chk;
                if (!nfa_table_exists($pdo, $table)) continue;
                try {
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM `{$table}` WHERE `{$col}` = ?");
                    $stmt->execute([$branchId]);
                    $c = (int)$stmt->fetchColumn();
                    if ($c > 0) {
                        http_response_code(400);
                        echo json_encode(['success' => false, 'error' => 'Cannot delete branch: it is referenced by ' . $label . '.']);
                        break 2;
                    }
                } catch (PDOException $e) {
                    // If a check fails, be conservative and block deletion.
                    http_response_code(400);
                    echo json_encode(['success' => false, 'error' => 'Cannot delete branch: reference check failed for ' . $label . '.']);
                    break 2;
                }
            }

            $stmtName = $pdo->prepare('SELECT branch_name FROM branch WHERE branch_id = ?');
            $stmtName->execute([$branchId]);
            $oldName = (string)($stmtName->fetchColumn() ?: '');

            $stmtDel = $pdo->prepare('DELETE FROM branch WHERE branch_id = ?');
            $stmtDel->execute([$branchId]);

            $actorId = (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? 0);
            nfa_log_activity_best_effort($pdo, $actorId, 'Deleted branch #' . $branchId . ($oldName !== '' ? (': ' . $oldName) : ''));

            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to delete branch']);
        }
        break;

    case 'adminListBranchCapacities':
        try {
            nfa_require_role('Admin');

            $regionId = (int)sanitize_input($_GET['region_id'] ?? 0);
            $where = '';
            $params = [];
            if ($regionId > 0) {
                $where = 'WHERE b.region_id = ?';
                $params[] = $regionId;
            }

            $stmt = $pdo->prepare(
                "SELECT b.branch_id, b.branch_name, r.region_id, r.region_name, " .
                "       COALESCE(v.warehouse_capacity, 0) AS warehouse_capacity, " .
                "       COALESCE(v.inventory, 0) AS inventory " .
                "FROM branch b " .
                "JOIN regions r ON r.region_id = b.region_id " .
                "LEFT JOIN volume_capacity v ON v.branch_id = b.branch_id " .
                $where .
                " ORDER BY r.region_name, b.branch_name"
            );
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $out = array_map(function ($r) {
                $cap = (float)($r['warehouse_capacity'] ?? 0);
                $inv = (float)($r['inventory'] ?? 0);
                $avail = max(0, $cap - $inv);
                $pct = ($cap > 0) ? ($inv / $cap) * 100 : 0;
                return [
                    'region_id' => (int)($r['region_id'] ?? 0),
                    'region_name' => (string)($r['region_name'] ?? ''),
                    'branch_id' => (int)($r['branch_id'] ?? 0),
                    'branch_name' => (string)($r['branch_name'] ?? ''),
                    'warehouse_capacity' => $cap,
                    'inventory' => $inv,
                    'available' => $avail,
                    'percent' => $pct,
                ];
            }, $rows ?: []);

            echo json_encode(['success' => true, 'data' => $out]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to load branch capacities']);
        }
        break;

    case 'adminListCapacityChangeLogs':
        try {
            nfa_require_role('Admin');
            $branchId = (int)sanitize_input($_GET['branch_id'] ?? 0);
            $limit = (int)sanitize_input($_GET['limit'] ?? 200);
            if ($limit <= 0) $limit = 200;
            if ($limit > 500) $limit = 500;

            $where = [];
            $params = [];
            if ($branchId > 0) {
                $where[] = 'l.branch_id = ?';
                $params[] = $branchId;
            }

            $whereSql = count($where) ? ('WHERE ' . implode(' AND ', $where)) : '';

            $stmt = $pdo->prepare(
                "SELECT l.id, l.changed_at, l.branch_id, b.branch_name, r.region_name, " .
                "       l.changed_by_user_id, l.changed_by_role, u.username, u.first_name, u.last_name, " .
                "       l.old_warehouse_capacity, l.new_warehouse_capacity, l.old_inventory, l.new_inventory, l.reason " .
                "FROM capacity_change_logs l " .
                "LEFT JOIN branch b ON b.branch_id = l.branch_id " .
                "LEFT JOIN regions r ON r.region_id = b.region_id " .
                "LEFT JOIN users u ON u.user_id = l.changed_by_user_id " .
                $whereSql .
                " ORDER BY l.changed_at DESC, l.id DESC LIMIT " . (int)$limit
            );
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $out = array_map(function ($r) {
                $who = trim((string)($r['first_name'] ?? '') . ' ' . (string)($r['last_name'] ?? ''));
                if ($who === '') $who = (string)($r['username'] ?? '');
                if ($who === '') $who = 'Unknown';
                return [
                    'id' => (int)($r['id'] ?? 0),
                    'changed_at' => (string)($r['changed_at'] ?? ''),
                    'region_name' => (string)($r['region_name'] ?? ''),
                    'branch_id' => (int)($r['branch_id'] ?? 0),
                    'branch_name' => (string)($r['branch_name'] ?? ''),
                    'changed_by' => $who,
                    'changed_by_role' => (string)($r['changed_by_role'] ?? ''),
                    'old_warehouse_capacity' => (float)($r['old_warehouse_capacity'] ?? 0),
                    'new_warehouse_capacity' => (float)($r['new_warehouse_capacity'] ?? 0),
                    'old_inventory' => (float)($r['old_inventory'] ?? 0),
                    'new_inventory' => (float)($r['new_inventory'] ?? 0),
                    'reason' => (string)($r['reason'] ?? ''),
                ];
            }, $rows ?: []);

            echo json_encode(['success' => true, 'data' => $out]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to load capacity change logs']);
        }
        break;

    case 'getReportsOverview':
        // Processor-only: aggregated report metrics for charts
        try {
            if (!isset($_SESSION['loggedin']) || ($_SESSION['user_type'] ?? '') !== 'Processor') {
                http_response_code(401);
                echo json_encode(['success' => false, 'error' => 'Unauthorized.']);
                break;
            }

            $branch_id = (int)($_SESSION['branch_id'] ?? 0);
            if ($branch_id <= 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Missing branch context.']);
                break;
            }

            $start_date = (string)sanitize_input($_GET['start_date'] ?? '');
            $end_date = (string)sanitize_input($_GET['end_date'] ?? '');
            $time_slot = strtoupper((string)sanitize_input($_GET['time_slot'] ?? ''));
            $farmer_type_id = (int)sanitize_input($_GET['farmer_type_id'] ?? 0);

            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Invalid date range.']);
                break;
            }

            $allowedStatuses = ['pending', 'confirmed', 'rescheduled', 'completed', 'cancelled'];
            $statusesRaw = (string)sanitize_input($_GET['statuses'] ?? '');
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
                ':end_date' => $end_date
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
                    $key = ':st' . $i;
                    $in[] = $key;
                    $params[$key] = $st;
                }
                $where .= ' AND a.status IN (' . implode(',', $in) . ')';
            }

            // Summary metrics
            $stmtSummary = $pdo->prepare(
                "SELECT 
                    COUNT(*) AS total_appointments,
                    COALESCE(SUM(a.volume), 0) AS total_volume,
                    COALESCE(AVG(a.volume), 0) AS avg_volume,
                    SUM(CASE WHEN a.status = 'completed' THEN 1 ELSE 0 END) AS completed_count,
                    SUM(CASE WHEN a.status IN ('confirmed','rescheduled') THEN 1 ELSE 0 END) AS confirmed_count,
                    SUM(CASE WHEN a.status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled_count
                 FROM appointments a 
                 {$where}"
            );
            $stmtSummary->execute($params);
            $summary = $stmtSummary->fetch(PDO::FETCH_ASSOC) ?: [];

            // Status distribution
            $stmtStatus = $pdo->prepare(
                "SELECT a.status, COUNT(*) AS count, COALESCE(SUM(a.volume), 0) AS volume
                 FROM appointments a 
                 {$where}
                 GROUP BY a.status"
            );
            $stmtStatus->execute($params);
            $statusRows = $stmtStatus->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'data' => [
                    'summary' => [
                        'total_appointments' => (int)($summary['total_appointments'] ?? 0),
                        'total_volume' => (float)($summary['total_volume'] ?? 0),
                        'avg_volume' => (float)($summary['avg_volume'] ?? 0),
                        'completed_count' => (int)($summary['completed_count'] ?? 0),
                        'confirmed_count' => (int)($summary['confirmed_count'] ?? 0),
                        'cancelled_count' => (int)($summary['cancelled_count'] ?? 0)
                    ],
                    'status' => $statusRows
                ]
            ]);
        } catch (\PDOException $e) {
            error_log('getReportsOverview failed: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to load report overview.']);
        }
        break;

    case 'getReportsAppointments':
        // Processor-only: paginated appointment rows for report table
        try {
            if (!isset($_SESSION['loggedin']) || ($_SESSION['user_type'] ?? '') !== 'Processor') {
                http_response_code(401);
                echo json_encode(['success' => false, 'error' => 'Unauthorized.']);
                break;
            }

            $branch_id = (int)($_SESSION['branch_id'] ?? 0);
            if ($branch_id <= 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Missing branch context.']);
                break;
            }

            $start_date = (string)sanitize_input($_GET['start_date'] ?? '');
            $end_date = (string)sanitize_input($_GET['end_date'] ?? '');
            $time_slot = strtoupper((string)sanitize_input($_GET['time_slot'] ?? ''));
            $farmer_type_id = (int)sanitize_input($_GET['farmer_type_id'] ?? 0);

            $page = max(1, (int)sanitize_input($_GET['page'] ?? 1));
            $pageSize = (int)sanitize_input($_GET['page_size'] ?? 25);
            if ($pageSize < 10) $pageSize = 10;
            if ($pageSize > 100) $pageSize = 100;
            $offset = ($page - 1) * $pageSize;

            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Invalid date range.']);
                break;
            }

            $allowedStatuses = ['pending', 'confirmed', 'rescheduled', 'completed', 'cancelled'];
            $statusesRaw = (string)sanitize_input($_GET['statuses'] ?? '');
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
                ':end_date' => $end_date
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
                    $key = ':st' . $i;
                    $in[] = $key;
                    $params[$key] = $st;
                }
                $where .= ' AND a.status IN (' . implode(',', $in) . ')';
            }

            $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM appointments a {$where}");
            $stmtCount->execute($params);
            $total = (int)$stmtCount->fetchColumn();

            $stmtRows = $pdo->prepare(
                "SELECT a.appointment_id, a.reference_number, a.`date`, a.time_slot, a.first_name, a.last_name,
                        a.volume, a.status, f.type_name
                 FROM appointments a
                 LEFT JOIN farmer_type f ON a.farmer_type_id = f.farmer_type_id
                 {$where}
                 ORDER BY a.`date` DESC, FIELD(a.time_slot, 'AM', 'PM'), a.appointment_id DESC
                 LIMIT :limit OFFSET :offset"
            );

            foreach ($params as $k => $v) {
                $stmtRows->bindValue($k, $v);
            }
            $stmtRows->bindValue(':limit', $pageSize, PDO::PARAM_INT);
            $stmtRows->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmtRows->execute();
            $rows = $stmtRows->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'data' => [
                    'total' => $total,
                    'page' => $page,
                    'page_size' => $pageSize,
                    'rows' => $rows
                ]
            ]);
        } catch (\PDOException $e) {
            error_log('getReportsAppointments failed: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to load report rows.']);
        }
        break;

    case 'getWarehouseReport':
        // Processor-only: warehouse report (capacity snapshot + completed intake trend aligned to filter range)
        try {
            if (!isset($_SESSION['loggedin']) || ($_SESSION['user_type'] ?? '') !== 'Processor') {
                http_response_code(401);
                echo json_encode(['success' => false, 'error' => 'Unauthorized.']);
                break;
            }

            $branch_id = (int)($_SESSION['branch_id'] ?? 0);
            if ($branch_id <= 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Missing branch context.']);
                break;
            }

            $start_date = (string)sanitize_input($_GET['start_date'] ?? '');
            $end_date = (string)sanitize_input($_GET['end_date'] ?? '');
            $time_slot = strtoupper((string)sanitize_input($_GET['time_slot'] ?? ''));
            $farmer_type_id = (int)sanitize_input($_GET['farmer_type_id'] ?? 0);

            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Invalid date range.']);
                break;
            }

            // Capacity snapshot
            $stmtCap = $pdo->prepare('SELECT warehouse_capacity, inventory FROM volume_capacity WHERE branch_id = ?');
            $stmtCap->execute([$branch_id]);
            $cap = $stmtCap->fetch(PDO::FETCH_ASSOC) ?: ['warehouse_capacity' => 0, 'inventory' => 0];
            $warehouse_capacity = (float)($cap['warehouse_capacity'] ?? 0);
            $inventory = (float)($cap['inventory'] ?? 0);
            $available = max(0, $warehouse_capacity - $inventory);
            $percent = $warehouse_capacity > 0 ? ($inventory / $warehouse_capacity) * 100 : 0;

            // Completed deliveries trend (smart granularity)
            $params = [
                ':branch_id' => $branch_id,
                ':start_date' => $start_date,
                ':end_date' => $end_date
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

            $startTs = strtotime($start_date);
            $endTs = strtotime($end_date);
            if (!$startTs || !$endTs) {
                $startTs = time();
                $endTs = time();
            }
            $rangeDays = (int)floor(($endTs - $startTs) / 86400) + 1;

            // Smart granularity rules (per UI spec):
            // - Last 7 days: show Daily or Hourly points. We don't have timestamps, so we approximate "hourly"
            //   with session-level (AM/PM) points when time_slot filter is not set.
            // - 1 month: daily
            // - >= 2 months: weekly totals
            // - >= 7 months: monthly totals
            $granularity = 'day';
            if ($rangeDays >= 210) {
                $granularity = 'month';
            } elseif ($rangeDays >= 60) {
                $granularity = 'week';
            } elseif ($rangeDays <= 7 && !($time_slot === 'AM' || $time_slot === 'PM')) {
                $granularity = 'session';
            }

            $trendPoints = [];

            if ($granularity === 'session') {
                $stmt = $pdo->prepare(
                    "SELECT DATE(a.`date`) AS d, UPPER(a.time_slot) AS slot, COUNT(*) AS cnt, COALESCE(SUM(a.volume), 0) AS vol\n" .
                    "FROM appointments a\n" .
                    "{$where}\n" .
                    "GROUP BY DATE(a.`date`), UPPER(a.time_slot)\n" .
                    "ORDER BY d ASC, FIELD(UPPER(a.time_slot), 'AM', 'PM')"
                );
                $stmt->execute($params);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

                $map = [];
                foreach ($rows as $r) {
                    $d = (string)($r['d'] ?? '');
                    $slot = strtoupper((string)($r['slot'] ?? ''));
                    if ($d === '' || ($slot !== 'AM' && $slot !== 'PM')) continue;
                    $map[$d . '|' . $slot] = ['count' => (int)($r['cnt'] ?? 0), 'volume' => (float)($r['vol'] ?? 0)];
                }

                $cur = new \DateTime($start_date);
                $end = new \DateTime($end_date);
                $slots = ['AM', 'PM'];
                while ($cur <= $end) {
                    $d = $cur->format('Y-m-d');
                    foreach ($slots as $slot) {
                        $v = $map[$d . '|' . $slot] ?? ['count' => 0, 'volume' => 0.0];
                        $trendPoints[] = [
                            'start_date' => $d,
                            'end_date' => $d,
                            'time_slot' => $slot,
                            'count' => (int)$v['count'],
                            'volume' => (float)$v['volume']
                        ];
                    }
                    $cur->modify('+1 day');
                }
            } elseif ($granularity === 'day') {
                $stmt = $pdo->prepare(
                    "SELECT DATE(a.`date`) AS d, COUNT(*) AS cnt, COALESCE(SUM(a.volume), 0) AS vol
                     FROM appointments a
                     {$where}
                     GROUP BY DATE(a.`date`)
                     ORDER BY d ASC"
                );
                $stmt->execute($params);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

                $map = [];
                foreach ($rows as $r) {
                    $k = (string)($r['d'] ?? '');
                    if ($k === '') continue;
                    $map[$k] = ['count' => (int)($r['cnt'] ?? 0), 'volume' => (float)($r['vol'] ?? 0)];
                }

                $cur = new \DateTime($start_date);
                $end = new \DateTime($end_date);
                while ($cur <= $end) {
                    $k = $cur->format('Y-m-d');
                    $v = $map[$k] ?? ['count' => 0, 'volume' => 0.0];
                    $trendPoints[] = [
                        'start_date' => $k,
                        'end_date' => $k,
                        'count' => (int)$v['count'],
                        'volume' => (float)$v['volume']
                    ];
                    $cur->modify('+1 day');
                }
            } elseif ($granularity === 'week') {
                $stmt = $pdo->prepare(
                    "SELECT YEARWEEK(a.`date`, 1) AS yw, COUNT(*) AS cnt, COALESCE(SUM(a.volume), 0) AS vol
                     FROM appointments a
                     {$where}
                     GROUP BY yw
                     ORDER BY yw ASC"
                );
                $stmt->execute($params);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

                $map = [];
                foreach ($rows as $r) {
                    $k = (string)($r['yw'] ?? '');
                    if ($k === '') continue;
                    $map[$k] = ['count' => (int)($r['cnt'] ?? 0), 'volume' => (float)($r['vol'] ?? 0)];
                }

                $cur = new \DateTime($start_date);
                // Align buckets to ISO week (Mon-Sun)
                $cur->modify('monday this week');
                $rangeEnd = new \DateTime($end_date);

                while ($cur <= $rangeEnd) {
                    $bucketStart = clone $cur;
                    $bucketEnd = clone $cur;
                    $bucketEnd->modify('+6 days');

                    $labelStart = (new \DateTime($start_date) > $bucketStart) ? new \DateTime($start_date) : $bucketStart;
                    $labelEnd = ($rangeEnd < $bucketEnd) ? clone $rangeEnd : $bucketEnd;

                    $isoYear = (int)$bucketStart->format('o');
                    $isoWeek = (int)$bucketStart->format('W');
                    $key = (string)(($isoYear * 100) + $isoWeek);

                    $v = $map[$key] ?? ['count' => 0, 'volume' => 0.0];
                    $trendPoints[] = [
                        'start_date' => $labelStart->format('Y-m-d'),
                        'end_date' => $labelEnd->format('Y-m-d'),
                        'count' => (int)$v['count'],
                        'volume' => (float)$v['volume']
                    ];

                    $cur->modify('+7 days');
                }
            } else { // month
                $stmt = $pdo->prepare(
                    "SELECT DATE_FORMAT(a.`date`, '%Y-%m') AS ym, COUNT(*) AS cnt, COALESCE(SUM(a.volume), 0) AS vol
                     FROM appointments a
                     {$where}
                     GROUP BY ym
                     ORDER BY ym ASC"
                );
                $stmt->execute($params);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

                $map = [];
                foreach ($rows as $r) {
                    $k = (string)($r['ym'] ?? '');
                    if ($k === '') continue;
                    $map[$k] = ['count' => (int)($r['cnt'] ?? 0), 'volume' => (float)($r['vol'] ?? 0)];
                }

                $cur = new \DateTime(date('Y-m-01', $startTs));
                $endMonth = new \DateTime(date('Y-m-01', $endTs));
                while ($cur <= $endMonth) {
                    $k = $cur->format('Y-m');
                    $v = $map[$k] ?? ['count' => 0, 'volume' => 0.0];

                    $startOfMonth = clone $cur;
                    $endOfMonth = clone $cur;
                    $endOfMonth->modify('last day of this month');

                    $labelStart = (new \DateTime($start_date) > $startOfMonth) ? new \DateTime($start_date) : $startOfMonth;
                    $labelEnd = ((new \DateTime($end_date)) < $endOfMonth) ? new \DateTime($end_date) : $endOfMonth;

                    $trendPoints[] = [
                        'start_date' => $labelStart->format('Y-m-d'),
                        'end_date' => $labelEnd->format('Y-m-d'),
                        'count' => (int)$v['count'],
                        'volume' => (float)$v['volume']
                    ];
                    $cur->modify('+1 month');
                }
            }

            echo json_encode([
                'success' => true,
                'data' => [
                    'capacity' => [
                        'warehouse_capacity' => $warehouse_capacity,
                        'inventory' => $inventory,
                        'available' => $available,
                        'percent' => $percent
                    ],
                    'trend' => [
                        'granularity' => $granularity,
                        'points' => $trendPoints
                    ]
                ]
            ]);
        } catch (\PDOException $e) {
            error_log('getWarehouseReport failed: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to load warehouse report.']);
        }
        break;

    case 'exportReportsCsv':
        // Processor-only: CSV export of appointments for the active filters
        try {
            if (!isset($_SESSION['loggedin']) || ($_SESSION['user_type'] ?? '') !== 'Processor') {
                http_response_code(401);
                echo json_encode(['success' => false, 'error' => 'Unauthorized.']);
                break;
            }

            $branch_id = (int)($_SESSION['branch_id'] ?? 0);
            if ($branch_id <= 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Missing branch context.']);
                break;
            }

            $start_date = (string)sanitize_input($_GET['start_date'] ?? '');
            $end_date = (string)sanitize_input($_GET['end_date'] ?? '');
            $time_slot = strtoupper((string)sanitize_input($_GET['time_slot'] ?? ''));
            $farmer_type_id = (int)sanitize_input($_GET['farmer_type_id'] ?? 0);

            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Invalid date range.']);
                break;
            }

            $allowedStatuses = ['pending', 'confirmed', 'rescheduled', 'completed', 'cancelled'];
            $statusesRaw = (string)sanitize_input($_GET['statuses'] ?? '');
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
                ':end_date' => $end_date
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
                    $key = ':st' . $i;
                    $in[] = $key;
                    $params[$key] = $st;
                }
                $where .= ' AND a.status IN (' . implode(',', $in) . ')';
            }

            $stmt = $pdo->prepare(
                "SELECT a.reference_number, a.`date`, a.time_slot, a.first_name, a.last_name, a.gender, a.email, a.contact_number,
                        a.volume, f.type_name AS farmer_type, a.status
                 FROM appointments a
                 LEFT JOIN farmer_type f ON a.farmer_type_id = f.farmer_type_id
                 {$where}
                 ORDER BY a.`date` DESC, FIELD(a.time_slot, 'AM', 'PM'), a.appointment_id DESC"
            );
            $stmt->execute($params);

            $actorId = (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? 0);
            nfa_log_activity_best_effort($pdo, $actorId, 'Generated report (CSV export)');

            // Override JSON header for CSV download
            $filename = 'NFA_Reports_' . $start_date . '_to_' . $end_date . '.csv';
            header_remove('Content-Type');
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');

            $out = fopen('php://output', 'w');
            fputcsv($out, ['Reference No', 'Date', 'Time Slot', 'First Name', 'Last Name', 'Gender', 'Email', 'Contact No', 'Volume (bags)', 'Farmer Type', 'Status']);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                fputcsv($out, [
                    $row['reference_number'] ?? '',
                    $row['date'] ?? '',
                    $row['time_slot'] ?? '',
                    $row['first_name'] ?? '',
                    $row['last_name'] ?? '',
                    $row['gender'] ?? '',
                    $row['email'] ?? '',
                    $row['contact_number'] ?? '',
                    $row['volume'] ?? '',
                    $row['farmer_type'] ?? '',
                    $row['status'] ?? ''
                ]);
            }
            fclose($out);
            exit;
        } catch (\PDOException $e) {
            error_log('exportReportsCsv failed: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to export CSV.']);
        }
        break;

    // --- Support Chat (Public/Farmer) ---
    case 'listSupportRegions':
        try {
            // Public endpoint
            $rows = [];
            if (nfa_table_exists($pdo, 'regions')) {
                $stmt = $pdo->query('SELECT region_id, region_name FROM regions ORDER BY region_name');
                $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
            }
            echo json_encode(['success' => true, 'regions' => $rows]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to load regions']);
        }
        break;

    case 'listSupportBranches':
        try {
            $region_id = (int)sanitize_input($_GET['region_id'] ?? 0);
            if ($region_id <= 0) {
                echo json_encode(['success' => true, 'branches' => []]);
                break;
            }
            $rows = [];
            if (nfa_table_exists($pdo, 'branch')) {
                $stmt = $pdo->prepare('SELECT branch_id, branch_name FROM branch WHERE region_id = ? ORDER BY branch_name');
                $stmt->execute([$region_id]);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            echo json_encode(['success' => true, 'branches' => $rows]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to load branches']);
        }
        break;

    case 'startSupportChat':
        try {
            $data = nfa_request_payload();
            $origin = strtolower((string)sanitize_input($data['origin'] ?? ''));
            if ($origin !== 'farmer') {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Invalid origin']);
                break;
            }

            $region_id = (int)sanitize_input($data['region_id'] ?? 0);
            $branch_id = (int)sanitize_input($data['branch_id'] ?? 0);
            if ($region_id <= 0 || $branch_id <= 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Missing region/branch']);
                break;
            }

            $displayName = trim((string)sanitize_input($data['display_name'] ?? ''));
            $contact = trim((string)sanitize_input($data['contact'] ?? ''));
            if (strlen($displayName) > 120) $displayName = substr($displayName, 0, 120);
            if (strlen($contact) > 160) $contact = substr($contact, 0, 160);

            $token = nfa_random_token(18);
            $stmt = $pdo->prepare(
                'INSERT INTO support_chats (chat_token, origin, status, region_id, branch_id, farmer_display_name, farmer_contact, last_activity_at) ' .
                'VALUES (?, ?, \'open\', ?, ?, ?, ?, NOW())'
            );
            $stmt->execute([$token, 'farmer', $region_id, $branch_id, ($displayName !== '' ? $displayName : null), ($contact !== '' ? $contact : null)]);

            $chatId = (int)$pdo->lastInsertId();
            // System greeting
            $pdo->prepare('INSERT INTO support_chat_messages (chat_id, sender_role, sender_user_id, message) VALUES (?, ?, NULL, ?)')
                ->execute([$chatId, 'system', 'You are now connected. Please type your concern.']);

            echo json_encode(['success' => true, 'token' => $token]);
        } catch (PDOException $e) {
            error_log('startSupportChat failed: ' . $e->getMessage());
            http_response_code(500);
            $resp = ['success' => false, 'error' => 'Failed to start chat'];
            if (isset($_GET['debug']) && $_GET['debug'] == '1') {
                $resp['debug'] = $e->getMessage();
            }
            echo json_encode($resp);
        }
        break;

    case 'sendSupportChatMessage':
        try {
            $data = nfa_request_payload();
            $token = (string)sanitize_input($data['token'] ?? '');
            $msg = trim((string)sanitize_input($data['message'] ?? ''));
            if ($token === '' || $msg === '') {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Missing token/message']);
                break;
            }
            if (strlen($msg) > 1200) $msg = substr($msg, 0, 1200);

            $chat = nfa_support_chat_fetch_by_token($pdo, $token);
            if (!$chat) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Chat not found']);
                break;
            }
            if (!nfa_support_chat_is_open($chat)) {
                http_response_code(409);
                echo json_encode(['success' => false, 'error' => 'This chat session has already ended.']);
                break;
            }

            $chatId = (int)$chat['chat_id'];
            $pdo->prepare('INSERT INTO support_chat_messages (chat_id, sender_role, sender_user_id, message) VALUES (?, ?, NULL, ?)')
                ->execute([$chatId, 'farmer', $msg]);
            $pdo->prepare('UPDATE support_chats SET last_activity_at = NOW() WHERE chat_id = ?')->execute([$chatId]);

            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            error_log('sendSupportChatMessage failed: ' . $e->getMessage());
            http_response_code(500);
            $resp = ['success' => false, 'error' => 'Failed to send message'];
            if (isset($_GET['debug']) && $_GET['debug'] == '1') {
                $resp['debug'] = $e->getMessage();
            }
            echo json_encode($resp);
        }
        break;

    case 'getSupportChatMessages':
        try {
            $token = (string)sanitize_input($_GET['token'] ?? '');
            $since_id = (int)sanitize_input($_GET['since_id'] ?? 0);
            $chat = nfa_support_chat_fetch_by_token($pdo, $token);
            if (!$chat) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Chat not found']);
                break;
            }

            $chatId = (int)$chat['chat_id'];
            $stmt = $pdo->prepare(
                'SELECT id, sender_role, message, created_at ' .
                'FROM support_chat_messages ' .
                'WHERE chat_id = ? AND id > ? ' .
                'ORDER BY id ASC ' .
                'LIMIT 200'
            );
            $stmt->execute([$chatId, max(0, $since_id)]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $lastId = $since_id;
            foreach ($rows as $r) {
                $id = (int)($r['id'] ?? 0);
                if ($id > $lastId) $lastId = $id;
            }

            $messages = array_map(function ($r) {
                $role = (string)($r['sender_role'] ?? '');
                $label = $role === 'system' ? 'System' : ($role === 'farmer' ? 'You' : 'Staff');
                return [
                    'id' => (int)($r['id'] ?? 0),
                    'sender_role' => $role,
                    'sender_label' => $label,
                    'message' => (string)($r['message'] ?? ''),
                    'created_at' => (string)($r['created_at'] ?? '')
                ];
            }, $rows);

            echo json_encode([
                'success' => true,
                'status' => strtolower((string)($chat['status'] ?? 'open')),
                'closed_reason' => (string)($chat['closed_reason'] ?? ''),
                'closed_by_role' => (string)($chat['closed_by_role'] ?? ''),
                'messages' => $messages,
                'last_id' => $lastId
            ]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to load messages']);
        }
        break;

    case 'closeSupportChat':
        try {
            $data = nfa_request_payload();
            $token = (string)sanitize_input($data['token'] ?? '');
            $chat = nfa_support_chat_fetch_by_token($pdo, $token);
            if (!$chat) {
                echo json_encode(['success' => true]);
                break;
            }
            $chatId = (int)$chat['chat_id'];
            nfa_support_chat_close(
                $pdo,
                $chatId,
                'farmer',
                'ended',
                'You have ended this chat session. If you still need assistance, please start a new chat.'
            );
            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to close chat']);
        }
        break;

    // --- Support Chat (Staff Inbox) ---
    case 'staffListSupportChats':
        try {
            nfa_support_chat_require_staff();
            $userType = (string)($_SESSION['user_type'] ?? '');
            $userId = (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? 0);
            $branchId = (int)($_SESSION['branch_id'] ?? 0);

            $rows = [];
            if ($userType === 'Processor') {
                $stmt = $pdo->prepare(
                    "SELECT\n" .
                    "  c.chat_id, c.origin, c.created_at, c.last_activity_at, c.farmer_display_name, c.farmer_contact,\n" .
                    "  b.branch_name, r.region_name,\n" .
                    "  lm.sender_role AS last_sender_role\n" .
                    "FROM support_chats c\n" .
                    "LEFT JOIN branch b ON c.branch_id = b.branch_id\n" .
                    "LEFT JOIN regions r ON c.region_id = r.region_id\n" .
                    "LEFT JOIN (\n" .
                    "  SELECT m.chat_id, m.sender_role\n" .
                    "  FROM support_chat_messages m\n" .
                    "  INNER JOIN (\n" .
                    "    SELECT chat_id, MAX(id) AS max_id\n" .
                    "    FROM support_chat_messages\n" .
                    "    WHERE sender_role <> 'system'\n" .
                    "    GROUP BY chat_id\n" .
                    "  ) x ON x.chat_id = m.chat_id AND x.max_id = m.id\n" .
                    ") lm ON lm.chat_id = c.chat_id\n" .
                    "WHERE c.status = 'open' AND c.origin = 'farmer' AND c.branch_id = ?\n" .
                    "ORDER BY c.last_activity_at DESC\n" .
                    "LIMIT 50"
                );
                $stmt->execute([$branchId]);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $stmt = $pdo->query(
                    "SELECT\n" .
                    "  c.chat_id, c.origin, c.created_at, c.last_activity_at, c.processor_user_id,\n" .
                    "  u.first_name, u.last_name, u.username,\n" .
                    "  lm.sender_role AS last_sender_role\n" .
                    "FROM support_chats c\n" .
                    "LEFT JOIN users u ON c.processor_user_id = u.user_id\n" .
                    "LEFT JOIN (\n" .
                    "  SELECT m.chat_id, m.sender_role\n" .
                    "  FROM support_chat_messages m\n" .
                    "  INNER JOIN (\n" .
                    "    SELECT chat_id, MAX(id) AS max_id\n" .
                    "    FROM support_chat_messages\n" .
                    "    WHERE sender_role <> 'system'\n" .
                    "    GROUP BY chat_id\n" .
                    "  ) x ON x.chat_id = m.chat_id AND x.max_id = m.id\n" .
                    ") lm ON lm.chat_id = c.chat_id\n" .
                    "WHERE c.status = 'open' AND c.origin = 'processor'\n" .
                    "ORDER BY c.last_activity_at DESC\n" .
                    "LIMIT 80"
                );
                $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
            }

            $out = array_map(function ($r) use ($userType) {
                $chatId = (int)($r['chat_id'] ?? 0);
                $origin = (string)($r['origin'] ?? '');
                $last = (string)($r['last_activity_at'] ?? ($r['created_at'] ?? ''));
                $lastSenderRole = strtolower(trim((string)($r['last_sender_role'] ?? '')));

                if ($userType === 'Processor') {
                    $who = trim((string)($r['farmer_display_name'] ?? ''));
                    if ($who === '') $who = 'Farmer';
                    $branch = trim((string)($r['branch_name'] ?? ''));
                    $region = trim((string)($r['region_name'] ?? ''));
                    $title = $who;
                    $subtitleParts = [];
                    if ($region !== '') $subtitleParts[] = $region;
                    if ($branch !== '') $subtitleParts[] = $branch;
                    $subtitleParts[] = 'Last: ' . $last;

                    // Treat as "unread / needs reply" if the latest non-system message is from the farmer
                    // (or there are no non-system messages yet).
                    $needsReply = ($lastSenderRole === '' || $lastSenderRole === 'farmer') ? 1 : 0;
                    return [
                        'chat_id' => $chatId,
                        'origin' => $origin,
                        'title' => $title,
                        'subtitle' => implode(' • ', $subtitleParts),
                        'needs_reply' => $needsReply,
                    ];
                }

                $who = trim((string)($r['first_name'] ?? '') . ' ' . (string)($r['last_name'] ?? ''));
                if ($who === '') $who = (string)($r['username'] ?? 'Processor');
                $title = $who;
                $subtitle = 'Processor Support • Last: ' . $last;

                // For Admin: "unread / needs reply" if the latest non-system message is from the processor
                // (or there are no non-system messages yet).
                $needsReply = ($lastSenderRole === '' || $lastSenderRole === 'processor') ? 1 : 0;
                return [
                    'chat_id' => $chatId,
                    'origin' => $origin,
                    'title' => $title,
                    'subtitle' => $subtitle,
                    'needs_reply' => $needsReply,
                ];
            }, $rows);

            echo json_encode(['success' => true, 'chats' => $out]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to load chats']);
        }
        break;

    case 'staffSupportChatNotificationSummary':
        try {
            nfa_support_chat_require_staff();
            $userType = (string)($_SESSION['user_type'] ?? '');
            $userId = (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? 0);
            $branchId = (int)($_SESSION['branch_id'] ?? 0);

            // Open chats count + chats needing reply.
            // "Needs reply" is based on the latest non-system message (if none, treat as needing reply).
            $openCount = 0;
            $needsReplyCount = 0;

            if ($userType === 'Processor') {
                $stmt = $pdo->prepare(
                    "SELECT\n" .
                    "  COUNT(*) AS open_count,\n" .
                    "  SUM(CASE WHEN lm.sender_role IS NULL OR lm.sender_role = 'farmer' THEN 1 ELSE 0 END) AS needs_reply_count\n" .
                    "FROM support_chats c\n" .
                    "LEFT JOIN (\n" .
                    "  SELECT m.chat_id, m.sender_role\n" .
                    "  FROM support_chat_messages m\n" .
                    "  INNER JOIN (\n" .
                    "    SELECT chat_id, MAX(id) AS max_id\n" .
                    "    FROM support_chat_messages\n" .
                    "    WHERE sender_role <> 'system'\n" .
                    "    GROUP BY chat_id\n" .
                    "  ) x ON x.chat_id = m.chat_id AND x.max_id = m.id\n" .
                    ") lm ON lm.chat_id = c.chat_id\n" .
                    "WHERE c.status = 'open' AND c.origin = 'farmer' AND c.branch_id = ?"
                );
                $stmt->execute([$branchId]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
                $openCount = (int)($row['open_count'] ?? 0);
                $needsReplyCount = (int)($row['needs_reply_count'] ?? 0);
            } else {
                // Admin notifications focus on processor-origin chats.
                $stmt = $pdo->query(
                    "SELECT\n" .
                    "  COUNT(*) AS open_count,\n" .
                    "  SUM(CASE WHEN lm.sender_role IS NULL OR lm.sender_role = 'processor' THEN 1 ELSE 0 END) AS needs_reply_count\n" .
                    "FROM support_chats c\n" .
                    "LEFT JOIN (\n" .
                    "  SELECT m.chat_id, m.sender_role\n" .
                    "  FROM support_chat_messages m\n" .
                    "  INNER JOIN (\n" .
                    "    SELECT chat_id, MAX(id) AS max_id\n" .
                    "    FROM support_chat_messages\n" .
                    "    WHERE sender_role <> 'system'\n" .
                    "    GROUP BY chat_id\n" .
                    "  ) x ON x.chat_id = m.chat_id AND x.max_id = m.id\n" .
                    ") lm ON lm.chat_id = c.chat_id\n" .
                    "WHERE c.status = 'open' AND c.origin = 'processor'"
                );
                $row = $stmt ? ($stmt->fetch(PDO::FETCH_ASSOC) ?: []) : [];
                $openCount = (int)($row['open_count'] ?? 0);
                $needsReplyCount = (int)($row['needs_reply_count'] ?? 0);
            }

            echo json_encode([
                'success' => true,
                'open_count' => $openCount,
                'needs_reply_count' => $needsReplyCount
            ]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to load support chat summary']);
        }
        break;

    case 'staffGetSupportChatMessages':
        try {
            nfa_support_chat_require_staff();
            $userType = (string)($_SESSION['user_type'] ?? '');
            $userId = (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? 0);
            $branchId = (int)($_SESSION['branch_id'] ?? 0);

            $chatId = (int)sanitize_input($_GET['chat_id'] ?? 0);
            $since_id = (int)sanitize_input($_GET['since_id'] ?? 0);
            $chat = nfa_support_chat_fetch_by_id($pdo, $chatId);
            if (!$chat) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Chat not found']);
                break;
            }
            if (!nfa_support_chat_staff_can_access($chat, $userType, $userId, $branchId)) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Forbidden']);
                break;
            }

            $stmt = $pdo->prepare(
                'SELECT id, sender_role, sender_user_id, message, created_at ' .
                'FROM support_chat_messages ' .
                'WHERE chat_id = ? AND id > ? ' .
                'ORDER BY id ASC ' .
                'LIMIT 400'
            );
            $stmt->execute([$chatId, max(0, $since_id)]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $lastId = $since_id;
            foreach ($rows as $r) {
                $id = (int)($r['id'] ?? 0);
                if ($id > $lastId) $lastId = $id;
            }

            $messages = array_map(function ($r) {
                $role = strtolower((string)($r['sender_role'] ?? ''));
                $label = $role === 'admin' ? 'Admin' : ($role === 'processor' ? 'Processor' : ($role === 'farmer' ? 'Farmer' : 'System'));
                return [
                    'id' => (int)($r['id'] ?? 0),
                    'sender_role' => $role,
                    'sender_label' => $label,
                    'message' => (string)($r['message'] ?? ''),
                    'created_at' => (string)($r['created_at'] ?? '')
                ];
            }, $rows);

            echo json_encode([
                'success' => true,
                'status' => strtolower((string)($chat['status'] ?? 'open')),
                'closed_reason' => (string)($chat['closed_reason'] ?? ''),
                'closed_by_role' => (string)($chat['closed_by_role'] ?? ''),
                'messages' => $messages,
                'last_id' => $lastId
            ]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to load messages']);
        }
        break;

    case 'staffSendSupportChatMessage':
        try {
            nfa_support_chat_require_staff();
            $userType = (string)($_SESSION['user_type'] ?? '');
            $userId = (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? 0);
            $branchId = (int)($_SESSION['branch_id'] ?? 0);

            $data = nfa_request_payload();
            $chatId = (int)sanitize_input($data['chat_id'] ?? 0);
            $msg = trim((string)sanitize_input($data['message'] ?? ''));
            if ($chatId <= 0 || $msg === '') {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Missing chat/message']);
                break;
            }
            if (strlen($msg) > 1200) $msg = substr($msg, 0, 1200);

            $chat = nfa_support_chat_fetch_by_id($pdo, $chatId);
            if (!$chat) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Chat not found']);
                break;
            }
            if (!nfa_support_chat_is_open($chat)) {
                http_response_code(409);
                echo json_encode(['success' => false, 'error' => 'This chat session has already ended.']);
                break;
            }
            if (!nfa_support_chat_staff_can_access($chat, $userType, $userId, $branchId)) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Forbidden']);
                break;
            }

            $senderRole = strtolower($userType) === 'admin' ? 'admin' : 'processor';
            $pdo->prepare('INSERT INTO support_chat_messages (chat_id, sender_role, sender_user_id, message) VALUES (?, ?, ?, ?)')
                ->execute([$chatId, $senderRole, $userId > 0 ? $userId : null, $msg]);
            $pdo->prepare('UPDATE support_chats SET last_activity_at = NOW() WHERE chat_id = ?')->execute([$chatId]);

            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to send message']);
        }
        break;

    case 'staffCloseSupportChat':
        try {
            nfa_support_chat_require_staff();
            $userType = (string)($_SESSION['user_type'] ?? '');
            $userId = (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? 0);
            $branchId = (int)($_SESSION['branch_id'] ?? 0);

            $data = nfa_request_payload();
            $chatId = (int)sanitize_input($data['chat_id'] ?? 0);
            $chat = nfa_support_chat_fetch_by_id($pdo, $chatId);
            if (!$chat) {
                echo json_encode(['success' => true]);
                break;
            }
            if (!nfa_support_chat_staff_can_access($chat, $userType, $userId, $branchId)) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Forbidden']);
                break;
            }

            $byRole = (strtolower($userType) === 'admin') ? 'admin' : 'processor';
            $msg = ($byRole === 'processor')
                ? 'This chat session has been ended by the processor. If you still need assistance, please start a new chat.'
                : 'This chat session has been ended by the admin. If you still need assistance, please start a new chat.';
            nfa_support_chat_close($pdo, (int)$chatId, $byRole, 'ended_by_' . $byRole, $msg);

            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to close chat']);
        }
        break;

    case 'staffStartSupportChat':
        // Staff-only start chat (currently used for Processor -> Admin)
        try {
            nfa_support_chat_require_staff();

            $userType = (string)($_SESSION['user_type'] ?? '');
            if ($userType !== 'Processor') {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Forbidden']);
                break;
            }

            $userId = (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? 0);
            if ($userId <= 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Missing user context']);
                break;
            }

            // Reuse existing open processor->admin chat if present
            $stmt = $pdo->prepare("SELECT chat_id FROM support_chats WHERE status = 'open' AND origin = 'processor' AND processor_user_id = ? ORDER BY last_activity_at DESC LIMIT 1");
            $stmt->execute([$userId]);
            $existingId = (int)($stmt->fetchColumn() ?: 0);
            if ($existingId > 0) {
                echo json_encode(['success' => true, 'chat_id' => $existingId, 'title' => 'Admin Support', 'subtitle' => 'Your request to admin']);
                break;
            }

            $token = nfa_random_token(18);
            $pdo->prepare('INSERT INTO support_chats (chat_token, origin, status, processor_user_id, last_activity_at) VALUES (?, ?, \'open\', ?, NOW())')
                ->execute([$token, 'processor', $userId]);
            $chatId = (int)$pdo->lastInsertId();

            $pdo->prepare('INSERT INTO support_chat_messages (chat_id, sender_role, sender_user_id, message) VALUES (?, ?, ?, ?)')
                ->execute([$chatId, 'system', null, 'Processor started a support chat.']);

            echo json_encode(['success' => true, 'chat_id' => $chatId, 'title' => 'Admin Support', 'subtitle' => 'Your request to admin']);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to start chat']);
        }
        break;
}
?>