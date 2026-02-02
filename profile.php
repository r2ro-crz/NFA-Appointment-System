<?php
session_start();
require_once 'php_helper/db_config.php';
require_once __DIR__ . '/php_helper/branding.php';

// Prevent caching of protected pages (helps prevent back-button access after logout)
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
header('Expires: 0');

if (!isset($_SESSION['loggedin'])) {
    header('location: login.php');
    exit;
}

$user_id = (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? 0);
$user_type = (string)($_SESSION['user_type'] ?? '');

if ($user_id <= 0) {
    header('location: login.php');
    exit;
}

// Fetch user + branch/region for display
$stmt = $pdo->prepare(
    "SELECT u.user_id, u.first_name, u.middle_name, u.last_name, u.suffix, u.employee_id,
            u.email_address, u.contact_number, u.gender, u.username, u.user_type, u.status,
            u.region_id, u.branch_id,
            b.branch_name,
            r.region_name
     FROM users u
     LEFT JOIN branch b ON u.branch_id = b.branch_id
     LEFT JOIN regions r ON u.region_id = r.region_id
     WHERE u.user_id = ?
     LIMIT 1"
);
$stmt->execute([$user_id]);
$u = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$u) {
    header('location: login.php');
    exit;
}

$first_name = (string)($u['first_name'] ?? '');
$last_name = (string)($u['last_name'] ?? '');
$middle_name = (string)($u['middle_name'] ?? '');
$suffix = (string)($u['suffix'] ?? '');
$user_email = (string)($u['email_address'] ?? '');
$username = (string)($u['username'] ?? '');
$role = (string)($u['user_type'] ?? $user_type);
$branch_name = (string)($u['branch_name'] ?? '');
$region_name = (string)($u['region_name'] ?? '');

$initials = strtoupper(substr($first_name, 0, 1) . substr($last_name, 0, 1));

$dashboardHref = 'processor_dashboard.php';
if (strcasecmp($role, 'Admin') === 0 || strcasecmp($user_type, 'Admin') === 0) {
    $dashboardHref = 'admin_dashboard.php';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(nfa_page_title('My Profile'), ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="icon" href="<?php echo htmlspecialchars(NFA_FAVICON, ENT_QUOTES, 'UTF-8'); ?>" type="image/png"/>
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/processor.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="dashboard profile-page">

    <nav class="top-nav">
        <div class="logo">
            <div class="brand-logos">
                <img src="<?php echo htmlspecialchars(NFA_SYSTEM_LOGO, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars(NFA_SYSTEM_NAME, ENT_QUOTES, 'UTF-8'); ?>" class="system-logo">
            </div>
            <div class="logo-text">
                <h1 class="nfa-title"><?php echo htmlspecialchars(NFA_BRAND_NAME, ENT_QUOTES, 'UTF-8'); ?></h1>
                <p class="nfa-subtitle"><span class="page-subtitle">My Profile</span></p>
            </div>
        </div>
        <div class="user-actions">
            <div class="user-profile">
                <div class="user-avatar"><?php echo htmlspecialchars($initials); ?></div>
                <div class="user-info">
                    <span class="user-name"><?php echo htmlspecialchars(trim($first_name . ' ' . $last_name)); ?></span>
                    <span class="user-role"><?php echo htmlspecialchars($role); ?></span>
                </div>
                <div class="user-dropdown" id="userDropdown">
                    <div class="user-dropdown-header">
                        <div class="user-dropdown-avatar"><?php echo htmlspecialchars($initials); ?></div>
                        <div class="user-dropdown-info">
                            <strong><?php echo htmlspecialchars(trim($first_name . ' ' . $last_name)); ?></strong>
                            <small><?php echo htmlspecialchars($user_email); ?></small>
                            <div class="user-role-badge"><?php echo htmlspecialchars($role); ?></div>
                        </div>
                    </div>
                    <div class="user-dropdown-menu">
                        <a href="profile.php" class="dropdown-item active">
                            <i class="fas fa-user-cog"></i>
                            <span class="dropdown-item-content">
                                <span class="dropdown-item-title">My Profile</span>
                                <span class="dropdown-item-desc">View and update your account</span>
                            </span>
                            <i class="fas fa-chevron-right dropdown-item-arrow"></i>
                        </a>
                        <a href="settings.php" class="dropdown-item">
                            <i class="fas fa-cog"></i>
                            <span class="dropdown-item-content">
                                <span class="dropdown-item-title">Settings</span>
                                <span class="dropdown-item-desc">Preferences and appearance</span>
                            </span>
                            <i class="fas fa-chevron-right dropdown-item-arrow"></i>
                        </a>
                        <div class="dropdown-divider"></div>
                        <a href="logout.php" class="dropdown-item logout">
                            <i class="fas fa-sign-out-alt"></i> Logout
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </nav>

    <div class="container-fluid">
        <div class="profile-hero">
            <div class="profile-hero-left">
                <h1>Account</h1>
                <p class="profile-subtitle">Keep your details up to date for secure access and accurate records.</p>
            </div>
            <div class="profile-hero-right">
                <a class="btn-view-details btn-inline-secondary" href="<?php echo htmlspecialchars($dashboardHref); ?>"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
            </div>
        </div>

        <div class="profile-grid">
            <div class="dashboard-card">
                <div class="card-header">
                    <h3><i class="fas fa-id-card"></i> Profile Details</h3>
                    <div class="profile-actions">
                        <button type="button" class="btn-view-details btn-inline-primary" id="btnEditProfile"><i class="fas fa-pen"></i> Edit</button>
                        <button type="button" class="btn-view-details btn-inline-success hidden" id="btnSaveProfile"><i class="fas fa-check"></i> Save</button>
                        <button type="button" class="btn-view-details btn-inline-secondary hidden" id="btnCancelEdit"><i class="fas fa-xmark"></i> Cancel</button>
                    </div>
                </div>
                <div class="card-body">
                    <form id="profileForm" class="profile-form" autocomplete="off">
                        <div class="form-row">
                            <div class="form-group">
                                <label>Username</label>
                                <input type="text" value="<?php echo htmlspecialchars($username); ?>" disabled>
                            </div>
                            <div class="form-group">
                                <label>Employee ID</label>
                                <input type="text" value="<?php echo htmlspecialchars((string)($u['employee_id'] ?? '')); ?>" disabled>
                            </div>
                            <div class="form-group">
                                <label>Role</label>
                                <input type="text" value="<?php echo htmlspecialchars($role); ?>" disabled>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="first_name">First Name</label>
                                <input id="first_name" name="first_name" type="text" value="<?php echo htmlspecialchars($first_name); ?>" disabled required>
                            </div>
                            <div class="form-group">
                                <label for="middle_name">Middle Name</label>
                                <input id="middle_name" name="middle_name" type="text" value="<?php echo htmlspecialchars($middle_name); ?>" disabled required>
                            </div>
                            <div class="form-group">
                                <label for="last_name">Last Name</label>
                                <input id="last_name" name="last_name" type="text" value="<?php echo htmlspecialchars($last_name); ?>" disabled required>
                            </div>
                            <div class="form-group span-3">
                                <label for="suffix">Suffix</label>
                                <input id="suffix" name="suffix" type="text" value="<?php echo htmlspecialchars($suffix); ?>" disabled>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group span-6">
                                <label for="email_address">Email</label>
                                <input id="email_address" name="email_address" type="email" value="<?php echo htmlspecialchars($user_email); ?>" autocomplete="email" disabled required>
                            </div>
                            <div class="form-group span-6">
                                <label for="contact_number">Contact Number</label>
                                <input id="contact_number" name="contact_number" type="tel" inputmode="numeric" value="<?php echo htmlspecialchars((string)($u['contact_number'] ?? '')); ?>" autocomplete="tel" placeholder="09XXXXXXXXX" disabled required>
                                <div class="form-hint">Format: 09XXXXXXXXX or +639XXXXXXXXX (auto-normalized)</div>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group span-6">
                                <label for="gender">Gender</label>
                                <select id="gender" name="gender" disabled required>
                                    <?php $g = strtolower((string)($u['gender'] ?? '')); ?>
                                    <option value="">Select</option>
                                    <option value="male" <?php echo $g==='male'?'selected':''; ?>>Male</option>
                                    <option value="female" <?php echo $g==='female'?'selected':''; ?>>Female</option>
                                    <option value="other" <?php echo $g==='other'?'selected':''; ?>>Other</option>
                                </select>
                            </div>
                            <div class="form-group span-6">
                                <label>Branch</label>
                                <input type="text" value="<?php echo htmlspecialchars($branch_name !== '' ? $branch_name : '—'); ?>" disabled>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group span-12">
                                <div class="profile-meta">
                                    <div class="pill"><i class="fas fa-location-dot"></i> <?php echo htmlspecialchars($region_name !== '' ? $region_name : 'Region'); ?></div>
                                    <div class="pill"><i class="fas fa-circle-check"></i> <?php echo htmlspecialchars((string)($u['status'] ?? '')); ?></div>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <div class="dashboard-card">
                <div class="card-header">
                    <h3><i class="fas fa-shield-halved"></i> Security</h3>
                </div>
                <div class="card-body">
                    <form id="passwordForm" class="profile-form" autocomplete="off">
                        <div class="form-row">
                            <div class="form-group span-12">
                                <label for="current_password">Current Password</label>
                                <input id="current_password" name="current_password" type="password" placeholder="Enter current password" required>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group span-6">
                                <label for="new_password">New Password</label>
                                <input id="new_password" name="new_password" type="password" placeholder="At least 8 characters" required>
                            </div>
                            <div class="form-group span-6">
                                <label for="confirm_password">Confirm New Password</label>
                                <input id="confirm_password" name="confirm_password" type="password" placeholder="Re-enter new password" required>
                            </div>
                        </div>
                        <div class="profile-actions">
                            <button type="submit" class="btn-view-details btn-inline-success" id="btnChangePassword"><i class="fas fa-key"></i> Change Password</button>
                        </div>
                        <div class="form-hint">Tip: Use a strong password with a mix of letters and numbers.</div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script>
        window.__profileUserId = <?php echo (int)$user_id; ?>;
    </script>
    <script src="js/processor.js?v=<?php echo urlencode((string)@filemtime(__DIR__ . '/js/processor.js')); ?>"></script>
    <script src="js/profile.js?v=<?php echo urlencode((string)@filemtime(__DIR__ . '/js/profile.js')); ?>"></script>
</body>
</html>
