<?php
// Check for login error messages from authenticate.php
$error_message = null;
if (isset($_GET['error']) && $_GET['error'] == 1) {
    $error_message = "Invalid Username or Password.\nPlease try again.";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NFA Staff Login</title>
    <link rel="stylesheet" href="css/login.css">
</head>
<body class="login-body">
    <div class="login-container">
        <h1>NFA Staff Access</h1>
        <p>Admin and Processor Operator Sign-In</p>
        
        <?php if ($error_message): ?>
            <div class="error"><?php echo nl2br(htmlspecialchars($error_message)); ?></div>
        <?php endif; ?>

        <form action="authenticate.php" method="POST">
            <div class="form-group">
                <label for="username">Username:</label>
                <input type="text" id="username" name="username">
            </div>
            <div class="form-group password-group"> 
                <label for="password">Password:</label>
                <div style="position: relative;">
                    <input type="password" id="password" name="password">
                    <span id="togglePassword" class="password-toggle">👁️</span>
                </div>
            </div>
            <button type="submit" class="login-button">Log In</button>
        </form>

        <p class="back-link">
            <a href="index.html">← Back to Main Page</a>
        </p>
    </div>
    
    <script src="js/login_app.js"></script>
</body>
</html>