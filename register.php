<?php
session_start();
require_once __DIR__ . '/php_helper/db_config.php';

$flash = $_SESSION['flash'] ?? [];
unset($_SESSION['flash']);

$error_message = null;
$success_message = null;
$old = [];

if (!empty($flash['register_error'])) {
    $error_message = (string)$flash['register_error'];
}
if (!empty($flash['register_success'])) {
    $success_message = (string)$flash['register_success'];
}
if (!empty($flash['register_old']) && is_array($flash['register_old'])) {
    $old = $flash['register_old'];
}

$regions = [];
$branchesForOldRegion = [];
$oldRegionId = (int)($old['region_id'] ?? 0);
try {
    // Get regions from regions table
    $stmt = $pdo->query("SELECT region_id, region_name FROM regions ORDER BY region_name");
    $regions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // If user previously selected a region (after validation error), pre-load branches for that region
    if ($oldRegionId > 0) {
        $stmtBranches = $pdo->prepare("SELECT branch_id, branch_name FROM branch WHERE region_id = ? ORDER BY branch_name");
        $stmtBranches->execute([$oldRegionId]);
        $branchesForOldRegion = $stmtBranches->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    $regions = [];
    $branchesForOldRegion = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NFA Staff Registration - Account Request</title>
    <link rel="icon" href="img/nfa-logo.png" type="image/png"/>
    <link rel="stylesheet" href="css/login.css">
    <link rel="stylesheet" href="css/register.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="login-body">
    <video class="bg-video" autoplay muted loop playsinline>
        <source src="img/nfa-intro.mp4" type="video/mp4">
    </video>
    <div class="overlay"></div>

    <div class="registration-container">
        <!-- Left Panel: Information -->
        <div class="registration-info">
            <div class="info-content">
                <div class="logo-wrapper">
                    <img src="img/nfa-logo.png" alt="NFA Logo" class="info-logo">
                    <div class="logo-text">
                        <h1>National Food Authority</h1>
                        <p class="department-tag">Staff Account Registration</p>
                    </div>
                </div>
                
                <div class="info-features">
                    <div class="feature-item">
                        <div class="feature-icon">
                            <i class="fas fa-shield-alt"></i>
                        </div>
                        <div class="feature-content">
                            <h3>Secure & Verified</h3>
                            <p>All accounts undergo strict verification before approval</p>
                        </div>
                    </div>
                    
                    <div class="feature-item">
                        <div class="feature-icon">
                            <i class="fas fa-clock"></i>
                        </div>
                        <div class="feature-content">
                            <h3>Approval Required</h3>
                            <p>Accounts require admin approval before gaining access</p>
                        </div>
                    </div>
                    
                    <div class="feature-item">
                        <div class="feature-icon">
                            <i class="fas fa-user-check"></i>
                        </div>
                        <div class="feature-content">
                            <h3>Role-Based Access</h3>
                            <p>Different permissions for Processors and Administrators</p>
                        </div>
                    </div>
                    
                    <div class="feature-item">
                        <div class="feature-icon">
                            <i class="fas fa-headset"></i>
                        </div>
                        <div class="feature-content">
                            <h3>Need Help?</h3>
                            <p>Contact IT Support at <strong>(02) 8929-6701</strong></p>
                        </div>
                    </div>
                </div>
                
                <div class="registration-steps">
                    <h3><i class="fas fa-list-ol"></i> Registration Process</h3>
                    <ol>
                        <li>Complete the registration form</li>
                        <li>Submit for approval</li>
                        <li>Wait for admin verification</li>
                        <li>Receive activation email</li>
                        <li>Start using your account</li>
                    </ol>
                </div>
            </div>
        </div>

        <!-- Right Panel: Registration Form -->
        <div class="registration-form-container">
            <div class="registration-header">
                <div class="header-content">
                    <h2><i class="fas fa-user-plus"></i> Create Staff Account</h2>
                    <p class="subtitle">Submit your details for approval to access the NFA staff portal</p>
                </div>
                <div class="form-progress">
                    <div class="progress-step active">
                        <span class="step-number">1</span>
                        <span class="step-label">Personal Info</span>
                    </div>
                    <div class="progress-step">
                        <span class="step-number">2</span>
                        <span class="step-label">Account Details</span>
                    </div>
                    <div class="progress-step">
                        <span class="step-number">3</span>
                        <span class="step-label">Review</span>
                    </div>
                </div>
            </div>

            <div class="registration-form-wrapper">
                <?php if ($error_message): ?>
                    <div class="alert error" role="alert">
                        <div class="alert-icon">
                            <i class="fas fa-exclamation-triangle"></i>
                        </div>
                        <div class="alert-content">
                            <strong>Registration Failed</strong>
                            <p><?php echo nl2br(htmlspecialchars($error_message)); ?></p>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($success_message): ?>
                    <div class="alert success" role="alert">
                        <div class="alert-icon">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <div class="alert-content">
                            <strong>Registration Submitted</strong>
                            <p><?php echo nl2br(htmlspecialchars($success_message)); ?></p>
                        </div>
                        <div class="alert-actions">
                            <a href="login.php" class="btn-outline">
                                <i class="fas fa-sign-in-alt"></i> Go to Login
                            </a>
                        </div>
                    </div>
                <?php endif; ?>

                <form action="php_helper/register_user.php" method="POST" id="registerForm" novalidate class="<?php echo $success_message ? 'hidden' : ''; ?>">
                    <!-- Step 1: Personal Information -->
                    <div class="form-step active" id="step1">
                        <div class="step-header">
                            <div class="step-title">
                                <span class="step-badge">Step 1</span>
                                <h3><i class="fas fa-user-circle"></i> Personal Information</h3>
                            </div>
                            <p class="step-description">Please provide your official personal details for verification.</p>
                        </div>

                        <div class="step-content">
                            <div class="form-grid">
                                <div class="input-group">
                                    <label for="first_name" class="required">
                                        <i class="fas fa-user"></i> First Name
                                    </label>
                                    <div class="input-wrapper">
                                        <input type="text" id="first_name" name="first_name" 
                                               autocomplete="given-name" 
                                               placeholder="Enter your first name"
                                               value="<?php echo htmlspecialchars($old['first_name'] ?? ''); ?>"
                                               required>
                                        <div class="input-icon">
                                            <i class="fas fa-user"></i>
                                        </div>
                                    </div>
                                    <div class="input-feedback"></div>
                                    <div class="field-hint">As it appears in official documents</div>
                                </div>

                                <div class="input-group">
                                    <label for="middle_name">
                                        <i class="fas fa-user"></i> Middle Name
                                    </label>
                                    <div class="input-wrapper">
                                        <input type="text" id="middle_name" name="middle_name" 
                                               autocomplete="additional-name" 
                                               placeholder="Optional"
                                               value="<?php echo htmlspecialchars($old['middle_name'] ?? ''); ?>">
                                        <div class="input-icon">
                                            <i class="fas fa-user"></i>
                                        </div>
                                    </div>
                                    <div class="input-feedback"></div>
                                </div>

                                <div class="input-group">
                                    <label for="last_name" class="required">
                                        <i class="fas fa-user"></i> Last Name
                                    </label>
                                    <div class="input-wrapper">
                                        <input type="text" id="last_name" name="last_name" 
                                               autocomplete="family-name" 
                                               placeholder="Enter your last name"
                                               value="<?php echo htmlspecialchars($old['last_name'] ?? ''); ?>"
                                               required>
                                        <div class="input-icon">
                                            <i class="fas fa-user"></i>
                                        </div>
                                    </div>
                                    <div class="input-feedback"></div>
                                </div>

                                <div class="input-group">
                                    <label for="suffix">
                                        <i class="fas fa-tag"></i> Suffix
                                    </label>
                                    <div class="input-wrapper">
                                        <select id="suffix" name="suffix">
                                            <option value="">Select Suffix</option>
                                            <option value="Jr" <?php echo (($old['suffix'] ?? '') === 'Jr') ? 'selected' : ''; ?>>Jr.</option>
                                            <option value="Sr" <?php echo (($old['suffix'] ?? '') === 'Sr') ? 'selected' : ''; ?>>Sr.</option>
                                            <option value="II" <?php echo (($old['suffix'] ?? '') === 'II') ? 'selected' : ''; ?>>II</option>
                                            <option value="III" <?php echo (($old['suffix'] ?? '') === 'III') ? 'selected' : ''; ?>>III</option>
                                            <option value="IV" <?php echo (($old['suffix'] ?? '') === 'IV') ? 'selected' : ''; ?>>IV</option>
                                        </select>
                                        <div class="input-icon">
                                            <i class="fas fa-tag"></i>
                                        </div>
                                    </div>
                                    <div class="input-feedback"></div>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="input-group">
                                    <label for="employee_id" class="required">
                                        <i class="fas fa-id-card"></i> Employee ID
                                    </label>
                                    <div class="input-wrapper">
                                        <input type="text" id="employee_id" name="employee_id" 
                                               placeholder="e.g., 22-0522"
                                               value="<?php echo htmlspecialchars($old['employee_id'] ?? ''); ?>"
                                               required>
                                        <div class="input-icon">
                                            <i class="fas fa-id-card"></i>
                                        </div>
                                    </div>
                                    <div class="input-feedback"></div>
                                    <div class="field-hint">Your official NFA Employee Identification Number</div>
                                </div>
                            </div>

                            <div class="form-grid">
                                <div class="input-group">
                                    <label for="email_address" class="required">
                                        <i class="fas fa-envelope"></i> Email Address
                                    </label>
                                    <div class="input-wrapper">
                                        <input type="email" id="email_address" name="email_address" 
                                               autocomplete="email" 
                                               placeholder="name@example.com"
                                               value="<?php echo htmlspecialchars($old['email_address'] ?? ''); ?>"
                                               required>
                                        <div class="input-icon">
                                            <i class="fas fa-envelope"></i>
                                        </div>
                                    </div>
                                    <div class="input-feedback"></div>
                                    <div class="field-hint">Use your official NFA email address</div>
                                </div>

                                <div class="input-group">
                                    <label for="contact_number" class="required">
                                        <i class="fas fa-phone"></i> Contact Number
                                    </label>
                                    <div class="input-wrapper">
                                        <input type="tel" id="contact_number" name="contact_number" 
                                               inputmode="numeric" 
                                               placeholder="09XX XXX XXXX"
                                               value="<?php echo htmlspecialchars($old['contact_number'] ?? ''); ?>"
                                               required>
                                        <div class="input-icon">
                                            <i class="fas fa-phone"></i>
                                        </div>
                                    </div>
                                    <div class="input-feedback"></div>
                                    <div class="field-hint">Philippine mobile number format</div>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="input-group">
                                    <label for="gender" class="required">
                                        <i class="fas fa-venus-mars"></i> Gender
                                    </label>
                                    <div class="input-wrapper">
                                        <select id="gender" name="gender" required>
                                            <option value="">Select Gender</option>
                                            <option value="Male" <?php echo (($old['gender'] ?? '') === 'Male') ? 'selected' : ''; ?>>Male</option>
                                            <option value="Female" <?php echo (($old['gender'] ?? '') === 'Female') ? 'selected' : ''; ?>>Female</option>
                                            <option value="Other" <?php echo (($old['gender'] ?? '') === 'Other') ? 'selected' : ''; ?>>Prefer not to say</option>
                                        </select>
                                        <div class="input-icon">
                                            <i class="fas fa-venus-mars"></i>
                                        </div>
                                    </div>
                                    <div class="input-feedback"></div>
                                </div>
                            </div>
                        </div>

                        <div class="step-actions">
                            <button type="button" class="btn-next" onclick="nextStep()">
                                Next: Account Details <i class="fas fa-arrow-right"></i>
                            </button>
                        </div>
                    </div>

                    <!-- Step 2: Account Details -->
                    <div class="form-step" id="step2">
                        <div class="step-header">
                            <div class="step-title">
                                <span class="step-badge">Step 2</span>
                                <h3><i class="fas fa-user-lock"></i> Account Details</h3>
                            </div>
                            <p class="step-description">Configure your account type and login credentials.</p>
                        </div>

                        <div class="step-content">
                            <div class="form-row">
                                <div class="input-group">
                                    <label for="user_type" class="required">
                                        <i class="fas fa-user-tag"></i> Account Type
                                    </label>
                                    <div class="input-wrapper">
                                        <select id="user_type" name="user_type" required onchange="toggleBranchSelection()">
                                            <option value="">Select Account Type</option>
                                            <option value="Processor" <?php echo (($old['user_type'] ?? '') === 'Processor') ? 'selected' : ''; ?>>Processor</option>
                                            <option value="Admin" <?php echo (($old['user_type'] ?? '') === 'Admin') ? 'selected' : ''; ?>>Administrator</option>
                                        </select>
                                        <div class="input-icon">
                                            <i class="fas fa-user-tag"></i>
                                        </div>
                                    </div>
                                    <div class="input-feedback"></div>
                                    <div class="role-description">
                                        <div class="role-card processor" id="processorDesc">
                                            <h4><i class="fas fa-warehouse"></i> Processor</h4>
                                            <p>Manages appointments, capacity, and farmer interactions at assigned branch.</p>
                                        </div>
                                        <div class="role-card admin" id="adminDesc">
                                            <h4><i class="fas fa-cog"></i> Administrator</h4>
                                            <p>System-wide management, user approvals, reports, and configuration.</p>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="form-row" id="regionSection" style="display: none;">
                                <div class="input-group">
                                    <label for="region_id" class="required">
                                        <i class="fas fa-globe-asia"></i> Region
                                    </label>
                                    <div class="input-wrapper">
                                        <select id="region_id" name="region_id">
                                            <option value="">Select Region</option>
                                            <?php foreach ($regions as $r): ?>
                                                <option value="<?php echo (int)$r['region_id']; ?>" <?php echo ((string)($old['region_id'] ?? '') === (string)$r['region_id']) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($r['region_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <div class="input-icon">
                                            <i class="fas fa-globe-asia"></i>
                                        </div>
                                    </div>
                                    <div class="input-feedback"></div>
                                    <div class="field-hint">Required for Processor accounts. Select your assigned region first.</div>
                                </div>
                            </div>

                            <div class="form-row" id="branchSection" style="display: none;">
                                <div class="input-group">
                                    <label for="branch_id" class="required">
                                        <i class="fas fa-map-marker-alt"></i> Assigned Branch
                                    </label>
                                    <div class="input-wrapper">
                                        <select id="branch_id" name="branch_id" <?php echo empty($branchesForOldRegion) ? 'disabled' : ''; ?>>
                                            <?php if (!empty($branchesForOldRegion)): ?>
                                                <option value="">Select Branch</option>
                                                <?php foreach ($branchesForOldRegion as $b): ?>
                                                    <option value="<?php echo (int)$b['branch_id']; ?>" <?php echo ((string)($old['branch_id'] ?? '') === (string)$b['branch_id']) ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($b['branch_name']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <option value="">Select Region first</option>
                                            <?php endif; ?>
                                        </select>
                                        <div class="input-icon">
                                            <i class="fas fa-map-marker-alt"></i>
                                        </div>
                                    </div>
                                    <div class="input-feedback"></div>
                                    <div class="field-hint">Required for Processor accounts. This determines which branch you can manage.</div>
                                </div>
                            </div>

                            <div class="form-grid">
                                <div class="input-group">
                                    <label for="username" class="required">
                                        <i class="fas fa-user"></i> Username
                                    </label>
                                    <div class="input-wrapper">
                                        <input type="text" id="username" name="username" 
                                               autocomplete="username" 
                                               placeholder="e.g., juan.dcruz"
                                               value="<?php echo htmlspecialchars($old['username'] ?? ''); ?>"
                                               required>
                                        <div class="input-icon">
                                            <i class="fas fa-user"></i>
                                        </div>
                                    </div>
                                    <div class="input-feedback"></div>
                                    <div class="field-hint">Choose a unique username for login</div>
                                </div>

                                <div class="input-group">
                                    <label for="email_address_confirm" class="required">
                                        <i class="fas fa-envelope"></i> Confirm Email
                                    </label>
                                    <div class="input-wrapper">
                                        <input type="email" id="email_address_confirm" 
                                               placeholder="Re-enter your email"
                                               value="<?php echo htmlspecialchars($old['email_address'] ?? ''); ?>"
                                               required>
                                        <div class="input-icon">
                                            <i class="fas fa-envelope"></i>
                                        </div>
                                    </div>
                                    <div class="input-feedback"></div>
                                </div>
                            </div>

                            <div class="password-section">
                                <div class="form-grid">
                                    <div class="input-group">
                                        <label for="password" class="required">
                                            <i class="fas fa-key"></i> Password
                                        </label>
                                        <div class="input-wrapper">
                                            <input type="password" id="password" name="password" 
                                                   autocomplete="new-password" 
                                                   placeholder="Create a strong password"
                                                   required>
                                            <div class="input-icon">
                                                <i class="fas fa-key"></i>
                                            </div>
                                            <button type="button" id="togglePassword" class="password-toggle" aria-label="Show password">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                        </div>
                                        <div class="input-feedback"></div>
                                    </div>

                                    <div class="input-group">
                                        <label for="confirm_password" class="required">
                                            <i class="fas fa-key"></i> Confirm Password
                                        </label>
                                        <div class="input-wrapper">
                                            <input type="password" id="confirm_password" name="confirm_password" 
                                                   autocomplete="new-password" 
                                                   placeholder="Re-enter your password"
                                                   required>
                                            <div class="input-icon">
                                                <i class="fas fa-key"></i>
                                            </div>
                                            <button type="button" id="toggleConfirmPassword" class="password-toggle" aria-label="Show password">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                        </div>
                                        <div class="input-feedback"></div>
                                    </div>
                                </div>

                                <div class="password-requirements">
                                    <h4><i class="fas fa-clipboard-check"></i> Password Requirements</h4>
                                    <div class="requirements-list">
                                        <div class="requirement" data-rule="length">
                                            <i class="fas fa-circle"></i>
                                            <span>At least 8 characters</span>
                                        </div>
                                        <div class="requirement" data-rule="uppercase">
                                            <i class="fas fa-circle"></i>
                                            <span>One uppercase letter</span>
                                        </div>
                                        <div class="requirement" data-rule="lowercase">
                                            <i class="fas fa-circle"></i>
                                            <span>One lowercase letter</span>
                                        </div>
                                        <div class="requirement" data-rule="number">
                                            <i class="fas fa-circle"></i>
                                            <span>One number</span>
                                        </div>
                                        <div class="requirement" data-rule="special">
                                            <i class="fas fa-circle"></i>
                                            <span>One special character</span>
                                        </div>
                                        <div class="requirement" data-rule="match">
                                            <i class="fas fa-circle"></i>
                                            <span>Passwords match</span>
                                        </div>
                                    </div>
                                    <div class="password-strength">
                                        <div class="strength-label">Password Strength:</div>
                                        <div class="strength-meter">
                                            <div class="strength-bar"></div>
                                            <div class="strength-text" id="strengthText">Weak</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="step-actions">
                            <button type="button" class="btn-back" onclick="prevStep()">
                                <i class="fas fa-arrow-left"></i> Back to Personal Info
                            </button>
                            <button type="button" class="btn-next" onclick="nextStep()">
                                Next: Review <i class="fas fa-arrow-right"></i>
                            </button>
                        </div>
                    </div>

                    <!-- Step 3: Review and Submit -->
                    <div class="form-step" id="step3">
                        <div class="step-header">
                            <div class="step-title">
                                <span class="step-badge">Step 3</span>
                                <h3><i class="fas fa-clipboard-check"></i> Review & Submit</h3>
                            </div>
                            <p class="step-description">Please review your information before submission.</p>
                        </div>

                        <div class="step-content">
                            <div class="review-section">
                                <h4><i class="fas fa-user"></i> Personal Information</h4>
                                <div class="review-grid">
                                    <div class="review-item">
                                        <strong>Full Name:</strong>
                                        <span id="reviewName">--</span>
                                    </div>
                                    <div class="review-item">
                                        <strong>Employee ID:</strong>
                                        <span id="reviewEmployeeId">--</span>
                                    </div>
                                    <div class="review-item">
                                        <strong>Email:</strong>
                                        <span id="reviewEmail">--</span>
                                    </div>
                                    <div class="review-item">
                                        <strong>Contact:</strong>
                                        <span id="reviewContact">--</span>
                                    </div>
                                    <div class="review-item">
                                        <strong>Gender:</strong>
                                        <span id="reviewGender">--</span>
                                    </div>
                                </div>
                            </div>

                            <div class="review-section">
                                <h4><i class="fas fa-user-cog"></i> Account Details</h4>
                                <div class="review-grid">
                                    <div class="review-item">
                                        <strong>Account Type:</strong>
                                        <span id="reviewUserType">--</span>
                                    </div>
                                    <div class="review-item" id="reviewRegionItem">
                                        <strong>Region:</strong>
                                        <span id="reviewRegion">--</span>
                                    </div>
                                    <div class="review-item" id="reviewBranchItem">
                                        <strong>Assigned Branch:</strong>
                                        <span id="reviewBranch">--</span>
                                    </div>
                                    <div class="review-item">
                                        <strong>Username:</strong>
                                        <span id="reviewUsername">--</span>
                                    </div>
                                </div>
                            </div>

                            <div class="terms-section">
                                <div class="terms-card">
                                    <div class="terms-header">
                                        <i class="fas fa-file-contract"></i>
                                        <h4>Terms & Conditions</h4>
                                    </div>
                                    <div class="terms-content">
                                        <p>By submitting this registration, you agree to:</p>
                                        <ul>
                                            <li>Provide accurate and truthful information</li>
                                            <li>Maintain the confidentiality of your account credentials</li>
                                            <li>Use the system only for official NFA business</li>
                                            <li>Comply with all NFA policies and procedures</li>
                                            <li>Accept that account approval is at the discretion of NFA administrators</li>
                                        </ul>
                                    </div>
                                    <div class="terms-checkbox">
                                        <label class="checkbox-label">
                                            <input type="checkbox" id="agreeTerms" name="agree_terms" required>
                                            <span class="checkmark"></span>
                                            <span>I have read and agree to the terms and conditions</span>
                                        </label>
                                    </div>
                                </div>
                            </div>

                            <div class="submission-info">
                                <div class="info-card">
                                    <div class="info-icon">
                                        <i class="fas fa-clock"></i>
                                    </div>
                                    <div class="info-content">
                                        <h5>Approval Process</h5>
                                        <p>Your account will be reviewed by an administrator. You will receive an email notification once your account has been approved.</p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="step-actions">
                            <button type="button" class="btn-back" onclick="prevStep()">
                                <i class="fas fa-arrow-left"></i> Back to Account Details
                            </button>
                            <button type="submit" class="btn-submit" id="submitBtn">
                                <i class="fas fa-paper-plane"></i> Submit Registration
                            </button>
                        </div>
                    </div>
                </form>
            </div>

            <div class="registration-footer">
                <div class="footer-links">
                    <a href="login.php" class="footer-link">
                        <i class="fas fa-sign-in-alt"></i> Already have an account? Login
                    </a>
                    <a href="landing.html" class="footer-link">
                        <i class="fas fa-home"></i> Return to Main Portal
                    </a>
                </div>
                <div class="footer-info">
                    <p><i class="fas fa-info-circle"></i> For assistance, contact IT Support: (02) 8929-6701</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Loading Overlay -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="loading-content">
            <div class="spinner"></div>
            <p>Processing your registration...</p>
        </div>
    </div>

    <script src="js/register_app.js"></script>
</body>
</html>