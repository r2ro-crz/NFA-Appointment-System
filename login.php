<?php
// Check for login error messages from authenticate.php
$error_message = null;
if (isset($_GET['error']) && $_GET['error'] == 1) {
    $error_message = "Invalid Username or Password.\nPlease try again.";
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
                <a href="#" class="forgot-link">
                    <i class="fas fa-question-circle"></i> Forgot Password?
                </a>
                <a href="landing.html" class="back-link">
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
                    <a href="#"><i class="fas fa-user-shield"></i> Privacy Policy</a>
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
    
    <script src="js/login_app.js"></script>
</body>
</html>