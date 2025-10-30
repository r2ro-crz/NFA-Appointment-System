<?php
// php/db_config.php

// Database connection parameters
define('DB_SERVER', 'localhost');
define('DB_USERNAME', 'root'); // Default XAMPP/WAMP username
define('DB_PASSWORD', '');     // Default XAMPP/WAMP password (usually blank)
define('DB_NAME', 'nfa_appointment'); // The database name you created

try {
    // Create a new PDO instance
    $pdo = new PDO("mysql:host=" . DB_SERVER . ";dbname=" . DB_NAME, DB_USERNAME, DB_PASSWORD);

    // Set the PDO error mode to exception
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Optional: Set character set for proper handling of special characters
    $pdo->exec("set names utf8");

} catch(PDOException $e) {
    // If connection fails, display error and stop script execution
    die("ERROR: Could not connect to database. " . $e->getMessage());
}
?>