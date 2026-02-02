<?php
session_start();
require_once __DIR__ . '/php_helper/db_config.php';
require_once __DIR__ . '/php_helper/branding.php';

// Prevent caching
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

if (!isset($_SESSION['loggedin'])) {
    header('location: login.php');
    exit;
}

$userType = (string)($_SESSION['user_type'] ?? '');
if ($userType !== 'Admin' && $userType !== 'Processor') {
    header('location: login.php');
    exit;
}

$user_id = (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? 0);
$branch_id = (int)($_SESSION['branch_id'] ?? 0);

$first_name = (string)($_SESSION['username'] ?? 'User');
$last_name = '';
$user_email = '';
try {
    if ($user_id > 0) {
        $stmt = $pdo->prepare('SELECT first_name, last_name, email_address FROM users WHERE user_id = ? LIMIT 1');
        $stmt->execute([$user_id]);
        if ($u = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $first_name = (string)($u['first_name'] ?? $first_name);
            $last_name = (string)($u['last_name'] ?? '');
            $user_email = (string)($u['email_address'] ?? '');
        }
    }
} catch (Exception $e) {
    // ignore
}

$initials = '';
if ($first_name !== '') $initials .= strtoupper(substr($first_name, 0, 1));
if ($last_name !== '') $initials .= strtoupper(substr($last_name, 0, 1));
if ($initials === '' && !empty($_SESSION['username'])) $initials = strtoupper(substr((string)$_SESSION['username'], 0, 1));

$subtitle = $userType === 'Admin' ? 'Support Inbox (Processor Requests)' : 'Support Inbox (Farmer Chats)';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(nfa_page_title('Support Inbox'), ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="icon" href="<?php echo htmlspecialchars(NFA_FAVICON, ENT_QUOTES, 'UTF-8'); ?>" type="image/png"/>
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/processor.css">
    <link rel="stylesheet" href="css/admin.css">
    <link rel="stylesheet" href="css/chat.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .support-wrap{ display:grid; grid-template-columns: 360px 1fr; gap: 14px; margin-top: 1.2rem; }
        .support-panel{ background: white; border-radius: var(--radius-lg); border: 1px solid rgba(0,0,0,0.08); overflow:hidden; }
        .support-panel.dark{ background: rgba(18, 24, 32, 0.96); color: rgba(255,255,255,0.92); border-color: rgba(255,255,255,0.12); }
        .support-head{ padding: 12px 14px; border-bottom: 1px solid rgba(0,0,0,0.08); display:flex; align-items:center; justify-content:space-between; gap:10px; }
        .support-panel.dark .support-head{ border-bottom-color: rgba(255,255,255,0.12); }
        .support-list{ max-height: calc(100vh - 240px); overflow:auto; }
        .support-item{ display:block; padding: 12px 14px; border-bottom: 1px solid rgba(0,0,0,0.06); cursor:pointer; }
        .support-panel.dark .support-item{ border-bottom-color: rgba(255,255,255,0.10); }
        .support-item.active{ background: rgba(0,122,51,0.10); }
        .support-item.unread{ background: rgba(231, 76, 60, 0.08); border-left: 4px solid rgba(231, 76, 60, 0.75); }
        .support-item.unread .support-title{ display:flex; align-items:center; justify-content:space-between; gap: 10px; }
        .support-unread-dot{ width: 10px; height: 10px; border-radius: 50%; background: rgba(231, 76, 60, 0.95); box-shadow: 0 0 0 3px rgba(231, 76, 60, 0.18); flex: 0 0 auto; }
        .support-title{ font-weight: 700; }
        .support-sub{ color: rgba(0,0,0,0.65); font-size: 12px; margin-top: 4px; }
        .support-panel.dark .support-sub{ color: rgba(255,255,255,0.70); }
        .support-chat-area{ height: calc(100vh - 240px); display:flex; flex-direction:column; }
        .support-chat-messages{ flex:1; overflow:auto; padding: 12px; display:flex; flex-direction:column; gap: 10px; }
        .support-compose{ padding: 10px 12px; border-top: 1px solid rgba(0,0,0,0.08); display:grid; grid-template-columns: 1fr auto; gap: 8px; }
        .support-panel.dark .support-compose{ border-top-color: rgba(255,255,255,0.12); }
        .support-compose input{ padding: 10px 10px; border-radius: 12px; border: 1px solid rgba(0,0,0,0.12); }
        .support-panel.dark .support-compose input{ border-color: rgba(255,255,255,0.14); background: rgba(255,255,255,0.06); color: rgba(255,255,255,0.92); }
        .support-compose .btn{ border-radius: 12px; }
        .support-bubble{ max-width: 86%; padding: 10px 12px; border-radius: 14px; border: 1px solid rgba(0,0,0,0.12); background: rgba(0,0,0,0.02); }
        .support-panel.dark .support-bubble{ border-color: rgba(255,255,255,0.14); background: rgba(255,255,255,0.06); }
        .support-bubble.me{ align-self:flex-end; border-color: rgba(0,122,51,0.35); background: rgba(0,122,51,0.10); }
        .support-meta{ font-size: 11px; opacity: 0.72; margin-top: 6px; }
        @media (max-width: 980px){ .support-wrap{ grid-template-columns: 1fr; } .support-list{ max-height: 260px; } .support-chat-area{ height: 520px; } }
    </style>
</head>
<body class="dashboard">

<nav class="top-nav" role="navigation" aria-label="Main navigation">
    <div class="logo">
        <div class="brand-logos">
            <img src="<?php echo htmlspecialchars(NFA_SYSTEM_LOGO, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars(NFA_SYSTEM_NAME, ENT_QUOTES, 'UTF-8'); ?>" class="system-logo">
        </div>
        <div class="logo-text">
            <h1 class="nfa-title"><?php echo htmlspecialchars(NFA_BRAND_NAME, ENT_QUOTES, 'UTF-8'); ?></h1>
            <p class="nfa-subtitle"><span class="page-subtitle"><?php echo htmlspecialchars($subtitle, ENT_QUOTES, 'UTF-8'); ?></span></p>
        </div>
    </div>

    <div class="user-actions">
        <div class="user-profile" tabindex="0" role="button" aria-label="User menu" aria-expanded="false" aria-controls="userDropdown">
            <div class="user-avatar" aria-hidden="true"><?php echo htmlspecialchars($initials); ?></div>
            <div class="user-info">
                <span class="user-name"><?php echo htmlspecialchars(trim($first_name . ' ' . $last_name)); ?></span>
                <span class="user-role"><?php echo htmlspecialchars($userType); ?></span>
            </div>
            <div class="user-dropdown" id="userDropdown" role="region" aria-label="User dropdown menu">
                <div class="user-dropdown-header">
                    <div class="user-dropdown-avatar" aria-hidden="true"><?php echo htmlspecialchars($initials); ?></div>
                    <div class="user-dropdown-info">
                        <strong><?php echo htmlspecialchars(trim($first_name . ' ' . $last_name)); ?></strong>
                        <small><?php echo htmlspecialchars($user_email); ?></small>
                        <div class="user-role-badge" aria-label="Role"><?php echo htmlspecialchars($userType); ?></div>
                    </div>
                </div>
                <div class="user-dropdown-menu" role="menu">
                    <a href="profile.php" class="dropdown-item" role="menuitem">
                        <i class="fas fa-user-cog"></i>
                        <span class="dropdown-item-content">
                            <span class="dropdown-item-title">My Profile</span>
                            <span class="dropdown-item-desc">View and update your account</span>
                        </span>
                        <i class="fas fa-chevron-right dropdown-item-arrow" aria-hidden="true"></i>
                    </a>
                    <a href="settings.php" class="dropdown-item" role="menuitem">
                        <i class="fas fa-cog"></i>
                        <span class="dropdown-item-content">
                            <span class="dropdown-item-title">Settings</span>
                            <span class="dropdown-item-desc">Preferences and appearance</span>
                        </span>
                        <i class="fas fa-chevron-right dropdown-item-arrow" aria-hidden="true"></i>
                    </a>
                    <div class="dropdown-divider" role="separator"></div>
                    <a href="<?php echo $userType === 'Admin' ? 'admin_dashboard.php' : 'processor_dashboard.php'; ?>" class="dropdown-item" role="menuitem">
                        <i class="fas fa-arrow-left"></i> Back to Dashboard
                    </a>
                    <a href="logout.php" class="dropdown-item logout" role="menuitem">
                        <i class="fas fa-sign-out-alt"></i> Logout
                    </a>
                </div>
            </div>
        </div>
    </div>
</nav>

<main class="container-fluid" role="main">
    <div class="welcome-header" style="margin-top: 1.25rem;">
        <div class="welcome-text">
            <h1><i class="fas fa-headset"></i> Support Inbox</h1>
            <p><?php echo htmlspecialchars($subtitle, ENT_QUOTES, 'UTF-8'); ?></p>
        </div>
        <div style="display:flex; gap:10px; align-items:center;">
            <a class="btn-outline" href="<?php echo $userType === 'Admin' ? 'admin_dashboard.php' : 'processor_dashboard.php'; ?>">
                <i class="fas fa-arrow-left"></i> Back to Dashboard
            </a>
            <?php if ($userType === 'Processor'): ?>
                <button class="btn-outline" type="button" id="btnContactAdmin"><i class="fas fa-message"></i> Contact Admin</button>
            <?php endif; ?>
        </div>
    </div>

    <div class="support-wrap">
        <section class="support-panel" aria-label="Conversation list">
            <div class="support-head">
                <div style="font-weight:700;">Conversations</div>
                <button class="btn-outline" type="button" id="btnRefreshChats"><i class="fas fa-rotate"></i></button>
            </div>
            <div class="support-list" id="supportChatList">
                <div style="padding: 12px 14px; color: rgba(0,0,0,0.65);">Loading…</div>
            </div>
        </section>

        <section class="support-panel dark" aria-label="Messages">
            <div class="support-chat-area">
                <div class="support-head">
                    <div>
                        <div class="support-title" id="supportActiveTitle">Select a conversation</div>
                        <div class="support-sub" id="supportActiveSub">Messages appear here.</div>
                    </div>
                    <div style="display:flex; gap:8px;">
                        <button class="btn-outline" type="button" id="btnCloseChat" disabled><i class="fas fa-circle-xmark"></i> Close</button>
                    </div>
                </div>

                <div class="support-chat-messages" id="supportMessages">
                    <div class="support-chat-empty" style="margin: 12px;">No conversation selected.</div>
                </div>

                <div class="support-compose">
                    <input id="supportMsgInput" type="text" placeholder="Type a message…" maxlength="600" disabled>
                    <button class="btn-view-details" type="button" id="supportSendBtn" disabled>Send</button>
                </div>
            </div>
        </section>
    </div>
</main>

<script>
    window.NFASupportInbox = {
        userType: <?php echo json_encode($userType); ?>,
        userId: <?php echo json_encode((int)$user_id); ?>,
        branchId: <?php echo json_encode((int)$branch_id); ?>
    };
</script>
<script src="js/admin.js"></script>
<script src="js/support_inbox.js?v=<?php echo urlencode((string)@filemtime(__DIR__ . '/js/support_inbox.js')); ?>"></script>
</body>
</html>
