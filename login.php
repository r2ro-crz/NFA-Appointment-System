<?php
// Check for login error messages from authenticate.php
$error_message = null;
if (isset($_GET['error']) && $_GET['error'] == 1) {
    $error_message = "Invalid Username or Password.\nPlease try again.";
}

// Account pending approval
$pending_message = null;
if (isset($_GET['pending']) && $_GET['pending'] == 1) {
    $pending_message = "Your account is not yet approved.\nPlease wait for an administrator to approve your registration.";
}

// Registration success
$registered_message = null;
if (isset($_GET['registered']) && $_GET['registered'] == 1) {
    $registered_message = "Registration submitted successfully.\nYour account is pending approval.";
}

// Check if account is locked from too many attempts
$lock_message = null;
if (isset($_GET['locked']) && $_GET['locked'] == 1) {
    $lock_message = "Account temporarily locked due to too many failed attempts. Please try again in 30 minutes.";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NFA Staff Login - Secure Access Portal</title>
    <link rel="icon" href="img/nfa-logo.png" type="image/png"/>
    <link rel="stylesheet" href="css/login.css">
    <link rel="stylesheet" href="css/legal_modal.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="login-body">
    <!-- Animated background video -->
    <video class="bg-video" autoplay muted loop playsinline>
        <source src="img/nfa-intro.mp4" type="video/mp4">
    </video>
    
    <!-- Gradient overlay -->
    <div class="overlay"></div>
    
    <!-- Main login container -->
    <div class="login-container">
        <!-- Header with logo -->
        <div class="login-header">
            <div class="logo-wrapper">
                <img src="img/nfa-logo.png" alt="NFA Logo" class="login-logo">
                <div class="logo-text">
                    <h1>National Food Authority</h1>
                    <p class="department-tag">Staff Access Portal</p>
                </div>
            </div>
        </div>
        
        <!-- Login card -->
        <div class="login-card">
            <div class="card-header">
                <h2><i class="fas fa-lock"></i> Secure Sign In</h2>
                <p class="subtitle">Enter your credentials to access the NFA system</p>
            </div>
            
            <!-- Error messages -->
            <?php if ($error_message): ?>
                <div id="serverError" class="alert error" role="alert">
                    <i class="fas fa-exclamation-triangle alert-icon"></i>
                    <div class="alert-content">
                        <strong>Authentication Failed</strong>
                        <p><?php echo nl2br(htmlspecialchars($error_message)); ?></p>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($registered_message): ?>
                <div class="alert" role="alert" style="border-left: 4px solid var(--nfa-green); background: rgba(0, 122, 51, 0.08);">
                    <i class="fas fa-check-circle alert-icon" style="color: var(--nfa-green);"></i>
                    <div class="alert-content">
                        <strong>Registration Received</strong>
                        <p><?php echo nl2br(htmlspecialchars($registered_message)); ?></p>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($pending_message): ?>
                <div class="alert lockout" role="alert">
                    <i class="fas fa-user-clock alert-icon"></i>
                    <div class="alert-content">
                        <strong>Account Pending Approval</strong>
                        <p><?php echo nl2br(htmlspecialchars($pending_message)); ?></p>
                    </div>
                </div>
            <?php endif; ?>
            
            <?php if ($lock_message): ?>
                <div id="accountLocked" class="alert lockout" role="alert">
                    <i class="fas fa-clock alert-icon"></i>
                    <div class="alert-content">
                        <strong>Account Temporarily Locked</strong>
                        <p><?php echo nl2br(htmlspecialchars($lock_message)); ?></p>
                    </div>
                </div>
            <?php endif; ?>
            
            <!-- Lockout message (client-side) -->
            <div id="lockoutMessage" class="alert lockout" style="display: none;" role="alert">
                <i class="fas fa-hourglass-half alert-icon"></i>
                <div class="alert-content">
                    <strong>Too Many Failed Attempts</strong>
                    <p id="lockoutTimer">Please wait <span id="countdown">30</span> seconds before trying again.</p>
                </div>
            </div>
            
            <!-- Login form -->
            <form action="php_helper/authenticate.php" method="POST" id="loginForm">
                <div class="input-group">
                    <label for="username">
                        <i class="fas fa-user"></i> Username
                    </label>
                    <div class="input-wrapper">
                        <input type="text" id="username" name="username" placeholder="Enter your username" autocomplete="username" required>
                        <div class="input-feedback"></div>
                    </div>
                </div>
                
                <div class="input-group">
                    <label for="password">
                        <i class="fas fa-key"></i> Password
                    </label>
                    <div class="input-wrapper">
                        <input type="password" id="password" name="password" placeholder="Enter your password" autocomplete="current-password" required>
                        <button type="button" id="togglePassword" class="password-toggle" aria-label="Show password">
                            <i class="fas fa-eye"></i>
                        </button>
                        <div class="input-feedback"></div>
                    </div>
                </div>
                
                <!-- Remember me option -->
                <div class="remember-group">
                    <label class="checkbox-label">
                        <input type="checkbox" name="remember" id="remember">
                        <span class="checkmark"></span>
                        <span>Remember this device for 7 days</span>
                    </label>
                </div>
                
                <!-- Security indicators -->
                <div class="security-indicators">
                    <div class="security-item">
                        <i class="fas fa-shield-alt secure-icon"></i>
                        <span>Secure Connection</span>
                    </div>
                    <div class="security-item">
                        <i class="fas fa-user-check secure-icon"></i>
                        <span>Two-Factor Ready</span>
                    </div>
                </div>
                
                <button type="submit" class="login-button" id="submitBtn">
                    <span class="button-text">Log In</span>
                    <i class="fas fa-arrow-right button-icon"></i>
                </button>
            </form>
            
            <!-- Additional options -->
            <div class="login-options">
                <a href="#" class="forgot-link option-link" id="forgotPasswordLink">
                    <i class="fas fa-question-circle"></i> Forgot Password?
                </a>
                <a href="register.php" class="forgot-link option-link">
                    <i class="fas fa-user-plus"></i> Create Account
                </a>
                <a href="landing.html" class="back-link option-link">
                    <i class="fas fa-arrow-left"></i> Back to Main Portal
                </a>
            </div>
            
            <!-- Footer with system info -->
            <div class="login-footer">
                <p class="system-info">
                    <i class="fas fa-info-circle"></i> 
                    This is a secure system. All login attempts are logged and monitored.
                </p>
                <div class="footer-links">
                    <a href="#" data-legal-modal="privacy"><i class="fas fa-user-shield"></i> Privacy Policy</a>
                    <a href="#"><i class="fas fa-headset"></i> IT Support</a>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Loading overlay -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="loading-content">
            <div class="spinner"></div>
            <p>Authenticating...</p>
            <p class="loading-subtext">Please wait while we verify your credentials</p>
        </div>
    </div>

    <!-- OTP 2FA Modal -->
    <div class="otp-modal-backdrop" id="otpModalBackdrop" hidden>
        <div class="otp-modal" role="dialog" aria-modal="true" aria-labelledby="otpModalTitle">
            <div class="otp-modal-header">
                <h2 class="otp-modal-title" id="otpModalTitle"><i class="fas fa-key"></i> Enter Verification Code</h2>
                <button type="button" class="otp-modal-close" id="otpModalClose" aria-label="Close">&times;</button>
            </div>
            <div class="otp-modal-body">
                <p class="otp-hint">We sent a 6-digit OTP code to your email. Enter it below to continue.</p>
                <div class="otp-error" id="otpError" role="alert" style="display:none;"></div>

                <form id="otpForm" autocomplete="one-time-code">
                    <div class="otp-inputs" id="otpInputs">
                        <input class="otp-box" inputmode="numeric" pattern="[0-9]*" maxlength="1" aria-label="Digit 1" />
                        <input class="otp-box" inputmode="numeric" pattern="[0-9]*" maxlength="1" aria-label="Digit 2" />
                        <input class="otp-box" inputmode="numeric" pattern="[0-9]*" maxlength="1" aria-label="Digit 3" />
                        <input class="otp-box" inputmode="numeric" pattern="[0-9]*" maxlength="1" aria-label="Digit 4" />
                        <input class="otp-box" inputmode="numeric" pattern="[0-9]*" maxlength="1" aria-label="Digit 5" />
                        <input class="otp-box" inputmode="numeric" pattern="[0-9]*" maxlength="1" aria-label="Digit 6" />
                    </div>

                    <button type="submit" class="otp-submit" id="otpSubmitBtn">
                        <i class="fas fa-check"></i> Verify
                    </button>

                    <div class="otp-actions">
                        <button type="button" class="otp-link" id="otpResendBtn">Resend code</button>
                        <button type="button" class="otp-link danger" id="otpCancelBtn">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Forgot Password Modal (Email -> OTP -> New Password) -->
    <div class="fp-modal-backdrop" id="fpModalBackdrop" hidden>
        <div class="fp-modal" role="dialog" aria-modal="true" aria-labelledby="fpModalTitle">
            <div class="fp-modal-header">
                <h2 class="fp-modal-title" id="fpModalTitle"><i class="fas fa-unlock-alt"></i> Reset Password</h2>
                <button type="button" class="fp-modal-close" id="fpModalClose" aria-label="Close">&times;</button>
            </div>

            <div class="fp-modal-body">
                <div class="fp-alert" id="fpAlert" role="alert" style="display:none;"></div>

                <!-- Step 1: Email -->
                <div class="fp-step" id="fpStepEmail">
                    <p class="fp-hint">Enter your account email address. We will send a verification code to continue.</p>
                    <form id="fpEmailForm">
                        <div class="fp-field">
                            <label for="fpEmail"><i class="fas fa-envelope"></i> Email Address</label>
                            <input type="email" id="fpEmail" name="fpEmail" placeholder="name@example.com" autocomplete="email" required />
                        </div>
                        <button type="submit" class="fp-primary" id="fpEmailSubmit">
                            <i class="fas fa-paper-plane"></i> Send Code
                        </button>
                    </form>
                </div>

                <!-- Step 2: OTP -->
                <div class="fp-step" id="fpStepOtp" hidden>
                    <p class="fp-hint">Enter the 6-digit code sent to your email.</p>
                    <form id="fpOtpForm" autocomplete="one-time-code">
                        <div class="otp-inputs" id="fpOtpInputs">
                            <input class="otp-box" inputmode="numeric" pattern="[0-9]*" maxlength="1" aria-label="Digit 1" />
                            <input class="otp-box" inputmode="numeric" pattern="[0-9]*" maxlength="1" aria-label="Digit 2" />
                            <input class="otp-box" inputmode="numeric" pattern="[0-9]*" maxlength="1" aria-label="Digit 3" />
                            <input class="otp-box" inputmode="numeric" pattern="[0-9]*" maxlength="1" aria-label="Digit 4" />
                            <input class="otp-box" inputmode="numeric" pattern="[0-9]*" maxlength="1" aria-label="Digit 5" />
                            <input class="otp-box" inputmode="numeric" pattern="[0-9]*" maxlength="1" aria-label="Digit 6" />
                        </div>
                        <button type="submit" class="fp-primary" id="fpOtpSubmit">
                            <i class="fas fa-check"></i> Verify Code
                        </button>
                        <div class="fp-actions">
                            <button type="button" class="fp-link" id="fpResendBtn">Resend code</button>
                            <button type="button" class="fp-link danger" id="fpCancelBtn">Cancel</button>
                        </div>
                    </form>
                </div>

                <!-- Step 3: New Password -->
                <div class="fp-step" id="fpStepPassword" hidden>
                    <p class="fp-hint">Create a new password that meets the rules below.</p>
                    <ul class="fp-rules">
                        <li>At least 8 characters</li>
                        <li>At least 1 uppercase letter</li>
                        <li>At least 1 lowercase letter</li>
                        <li>At least 1 number</li>
                    </ul>

                    <form id="fpPasswordForm">
                        <div class="fp-field">
                            <label for="fpNewPassword"><i class="fas fa-key"></i> New Password</label>
                            <div class="fp-input-wrap">
                                <input type="password" id="fpNewPassword" autocomplete="new-password" required />
                                <button type="button" class="fp-toggle" id="fpToggleNew" aria-label="Show password"><i class="fas fa-eye"></i></button>
                            </div>
                        </div>

                        <div class="fp-field">
                            <label for="fpConfirmPassword"><i class="fas fa-key"></i> Confirm Password</label>
                            <div class="fp-input-wrap">
                                <input type="password" id="fpConfirmPassword" autocomplete="new-password" required />
                                <button type="button" class="fp-toggle" id="fpToggleConfirm" aria-label="Show password"><i class="fas fa-eye"></i></button>
                            </div>
                        </div>

                        <button type="submit" class="fp-primary" id="fpPasswordSubmit">
                            <i class="fas fa-save"></i> Update Password
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    
    <script src="js/login_app.js"></script>
    <script src="js/legal_modal.js"></script>
</body>
</html>