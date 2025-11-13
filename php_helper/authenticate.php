<?php
session_start();

// Include database configuration file
require_once 'db_config.php'; 

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    
    $username = trim($_POST["username"]);
    $password = $_POST["password"];
    
    // Simple validation check
    if (empty($username) || empty($password)) {
        // redirect back to top-level login.php (authenticate.php is inside php_helper)
        header("Location: ../login.php?error=1"); 
        exit;
    }

    // SQL to fetch user data and the hashed password
    $sql = "SELECT user_id, username, password_hash, user_type, branch_id FROM users WHERE username = :username";
    
    if($stmt = $pdo->prepare($sql)) {
        $stmt->bindParam(":username", $param_username, PDO::PARAM_STR);
        $param_username = $username;
        
        if($stmt->execute()) {
            if($stmt->rowCount() == 1) {
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                $hashed_password = $row['password_hash'];

                // Verify the password against the stored hash
                if (password_verify($password, $hashed_password)) {
                    
                    // Authentication successful: Store session variables
                    $_SESSION["loggedin"] = true;
                    $_SESSION["id"] = $row["user_id"];
                    $_SESSION["username"] = $row["username"];
                    $_SESSION["user_type"] = $row["user_type"];
                    $_SESSION["branch_id"] = $row["branch_id"]; 

                    // Redirect based on user role
                    if ($_SESSION["user_type"] == 'Admin') {
                        header("location: ../admin.html"); 
                    } else { // Processor/Operator
                        header("location: ../operator.html");
                    }
                    exit;
                }
            }
        }
    }
    
    // Login failed (Invalid credentials)
    header("Location: ../login.php?error=1");
    exit;

} else {
    // If accessed without POST, redirect to login page
    header("location: ../login.php");
    exit;
}
?>