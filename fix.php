<?php
// fix_admin.php
require_once 'database/config.php';

if (!isset($pdo) && isset($conn)) {
    $pdo = $conn;
}

// Generate a clean hash for 'admin123' directly on your server
$password = 'admin123';
$hashedPassword = password_hash($password, PASSWORD_BCRYPT);

try {
    $stmt = $pdo->prepare("
        INSERT INTO users (id, full_name, phone_number, password, role, created_at, updated_at)
        VALUES (212, 'System Administrator', '255793085794', :pass, 'admin', NOW(), NOW())
        ON DUPLICATE KEY UPDATE 
            phone_number = '255793085794',
            password = :pass_update,
            role = 'admin',
            updated_at = NOW()
    ");

    $stmt->execute([
        'pass'        => $hashedPassword,
        'pass_update' => $hashedPassword
    ]);

    echo "<h2 style='color: green;'>Admin Account Successfully Fixed/Updated!</h2>";
    echo "<p><strong>Phone:</strong> 255793085794</p>";
    echo "<p><strong>ID:</strong> 212</p>";
    echo "<p><strong>Password:</strong> admin123</p>";
    echo "<p><a href='login.php'>Go to Login Page</a></p>";

} catch (PDOException $e) {
    echo "<h2 style='color: red;'>Database Error:</h2> " . $e->getMessage();
}
?>