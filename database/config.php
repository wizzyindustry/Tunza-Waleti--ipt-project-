<?php
// db.php - TUNZA WALETI Database Connection

$host     = 'localhost';
$db_name  = 'tunza_waleti';
$username = 'root';        // Default XAMPP username
$password = '';            // Default XAMPP password is empty

try {
    $dsn = "mysql:host=$host;dbname=$db_name;charset=utf8mb4";
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    $conn = new PDO($dsn, $username, $password, $options);
    
} catch (PDOException $e) {
    // Stop script execution on failure and display error
    die("Database Connection Failed: " . $e->getMessage());
}
?>