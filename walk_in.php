<?php
session_start();
require_once 'php_helper/db_config.php';

if (!isset($_SESSION["loggedin"]) || ($_SESSION["user_type"] ?? '') !== 'Processor') {
    header("location: login.php");
    exit;
}

$branch_id = (int)($_SESSION['branch_id'] ?? 0);
$user_id = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;

// Fetch user + branch details for header
$first_name = $_SESSION['username'] ?? 'Processor';
$last_name = '';
$branch_name = 'Branch';

if ($user_id) {
    $user_stmt = $pdo->prepare("SELECT first_name, last_name FROM users WHERE user_id = ?");
    $user_stmt->execute([(int)$user_id]);
    if ($user = $user_stmt->fetch(PDO::FETCH_ASSOC)) {
        $first_name = $user['first_name'] ?: $first_name;
        $last_name = $user['last_name'] ?: $last_name;
    }
}

if ($branch_id > 0) {
    $b_stmt = $pdo->prepare("SELECT branch_name FROM branch WHERE branch_id = ? LIMIT 1");
    $b_stmt->execute([$branch_id]);
    $b = $b_stmt->fetch(PDO::FETCH_ASSOC);
    if ($b && !empty($b['branch_name'])) {
        $branch_name = $b['branch_name'];
    }
}

$today = date('Y-m-d');

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Walk-in Process</title>
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/processor.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="dashboard walkin-page">

    <nav class="top-nav">
        <div class="logo">
            <img src="img/nfa-logo.png" alt="NFA" class="nfa-logo">
            <div class="logo-text">
                <h1 class="nfa-title">National Food Authority</h1>
                <p class="nfa-subtitle">Walk-in Process</p>
            </div>
        </div>
        <div class="user-actions">
            <div class="user-profile">
                <div class="user-avatar"><?php echo htmlspecialchars(strtoupper(substr($first_name, 0, 1) . substr($last_name, 0, 1))); ?></div>
                <div class="user-info">
                    <span class="user-name"><?php echo htmlspecialchars(trim($first_name . ' ' . $last_name)); ?></span>
                    <span class="user-role"><?php echo htmlspecialchars($branch_name); ?></span>
                </div>
            </div>
        </div>
    </nav>

    <div class="container walkin-container">
        <div class="dashboard-card">
            <div class="card-header">
                <h3><i class="fas fa-person-walking"></i> Record Walk-in Farmer</h3>
                <div class="walkin-subtitle">
                    Mode: <strong>Walk-in</strong> • Saved as: <strong>Completed</strong>
                </div>
            </div>
            <div class="card-body">

                <div class="walkin-methods">
                    <button type="button" class="walkin-method active" data-method="manual">
                        <i class="fas fa-pen"></i> Manual Entry
                    </button>
                    <button type="button" class="walkin-method" data-method="lookup">
                        <i class="fas fa-magnifying-glass"></i> Lookup by Farmer ID
                    </button>
                </div>

                <div class="walkin-lookup hidden" id="walkinLookup">
                    <div class="form-row">
                        <div class="form-group span-9">
                            <label for="lookupFarmerId">Farmer ID</label>
                            <input id="lookupFarmerId" type="text" placeholder="Enter Farmer ID">
                        </div>
                        <div class="form-group span-3">
                            <button class="btn-primary" id="btnLookup" type="button">
                                <i class="fas fa-search"></i> Lookup
                            </button>
                        </div>
                    </div>
                    <div class="form-hint" id="lookupHint">Lookup uses the latest record for this Farmer ID (branch-scoped) and auto-fills the form.</div>
                </div>

                <form id="walkinForm" class="walkin-form" autocomplete="off">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="date">Date</label>
                            <input id="date" name="date" type="date" value="<?php echo htmlspecialchars($today); ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="time_slot">Session</label>
                            <select id="time_slot" name="time_slot" required>
                                <option value="">Select</option>
                                <option value="AM">AM (Morning)</option>
                                <option value="PM">PM (Afternoon)</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="volume">Volume (bags)</label>
                            <input id="volume" name="volume" type="number" min="1" step="1" placeholder="e.g. 100" required>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="farmer_id">Farmer ID</label>
                            <input id="farmer_id" name="farmer_id" type="text" required>
                        </div>
                        <div class="form-group">
                            <label for="farmer_type_id">Farmer Type</label>
                            <select id="farmer_type_id" name="farmer_type_id" required>
                                <option value="">Loading...</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="gender">Gender</label>
                            <select id="gender" name="gender" required>
                                <option value="">Select</option>
                                <option value="male">Male</option>
                                <option value="female">Female</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="first_name">First Name</label>
                            <input id="first_name" name="first_name" type="text" required>
                        </div>
                        <div class="form-group">
                            <label for="middle_name">Middle Name</label>
                            <input id="middle_name" name="middle_name" type="text" required>
                        </div>
                        <div class="form-group">
                            <label for="last_name">Last Name</label>
                            <input id="last_name" name="last_name" type="text" required>
                        </div>
                        <div class="form-group span-3">
                            <label for="suffix">Suffix</label>
                            <input id="suffix" name="suffix" type="text" placeholder="Jr / Sr / II">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group span-6">
                            <label for="email">Email</label>
                            <input id="email" name="email" type="email" placeholder="name@gmail.com" autocomplete="email" required>
                        </div>
                        <div class="form-group span-6">
                            <label for="contact_number">Contact Number</label>
                            <input
                                id="contact_number"
                                name="contact_number"
                                type="tel"
                                inputmode="numeric"
                                autocomplete="tel"
                                placeholder="09xxxxxxxxx"
                                pattern="^(09\d{9}|\+639\d{9})$"
                                title="Use 09XXXXXXXXX or +639XXXXXXXXX"
                                required>
                        </div>
                    </div>

                    <div class="walkin-actions">
                        <button class="btn-outline" type="button" id="btnClose">
                            <i class="fas fa-xmark"></i> Cancel
                        </button>
                        <button class="btn-primary" type="submit" id="btnSubmit">
                            <i class="fas fa-check"></i> Submit Walk-in
                        </button>
                    </div>

                    <div class="form-hint">This will be saved as <strong>mode: walk-in</strong> and <strong>status: completed</strong>.</div>
                </form>

            </div>
        </div>
    </div>

    <!-- Confirmation Modal -->
    <div class="modal" id="walkinConfirmModal">
        <div class="modal-content walkin-confirm">
            <div class="modal-header">
                <h3><i class="fas fa-circle-question"></i> Confirm Walk-in</h3>
                <button class="close-modal" type="button" id="confirmCloseBtn">&times;</button>
            </div>
            <div class="modal-body" id="confirmBody"></div>
            <div class="modal-footer walkin-confirm-actions">
                <button class="btn-outline" type="button" id="confirmCancelBtn">Cancel</button>
                <button class="btn-primary" type="button" id="confirmOkBtn">Confirm & Save</button>
            </div>
        </div>
    </div>

    <script>
        window.branchId = <?php echo (int)$branch_id; ?>;
        window.userId = <?php echo $user_id ? (int)$user_id : 'null'; ?>;
    </script>
    <script src="js/processor.js?v=<?php echo urlencode((string)@filemtime(__DIR__ . '/js/processor.js')); ?>"></script>
    <script src="js/walk_in.js?v=<?php echo urlencode((string)@filemtime(__DIR__ . '/js/walk_in.js')); ?>"></script>
</body>
</html>
