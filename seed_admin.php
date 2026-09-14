<?php
// seed_admin.php

require_once 'database/config.php';

if (!isset($pdo) && isset($conn)) {
    $pdo = $conn;
}

// 1. Define Default Main Admin Credentials
$full_name = "Main Administrator";
$phone     = "255700000000"; // Used as login identifier
$password  = "Admin@2026";    // Default login password
$role      = "admin";
$status    = "active";

// 2. Hash Password Securely
$hashed_password = password_hash($password, PASSWORD_BCRYPT);

try {
    // 3. Insert or Update Admin Record
    $stmt = $pdo->prepare("
        INSERT INTO users (full_name, phone_number, password, role, status)
        VALUES (:name, :phone, :password, :role, :status)
        ON DUPLICATE KEY UPDATE 
            password = VALUES(password),
            role     = VALUES(role),
            status   = VALUES(status)
    ");

    $stmt->execute([
        'name'     => $full_name,
        'phone'    => $phone,
        'password' => $hashed_password,
        'role'     => $role,
        'status'   => $status
    ]);

    // 4. Ensure Wallet Link Exists
    $userId = $pdo->lastInsertId();
    if (!$userId) {
        $stmtId = $pdo->prepare("SELECT id FROM users WHERE phone_number = :phone LIMIT 1");
        $stmtId->execute(['phone' => $phone]);
        $userId = $stmtId->fetchColumn();
    }

    if ($userId) {
        $stmtWallet = $pdo->prepare("
            INSERT INTO wallets (user_id, balance) 
            VALUES (:user_id, 0.00) 
            ON DUPLICATE KEY UPDATE user_id = user_id
        ");
        $stmtWallet->execute(['user_id' => $userId]);
    }

    echo "<h3>✅ Main Administrator initialized successfully!</h3>";
    echo "<p><strong>Phone Identifier:</strong> 0700000000 (or 255700000000)</p>";
    echo "<p><strong>Password:</strong> Admin@2026</p>";
    echo "<p><strong>Role:</strong> admin</p>";
    echo "<p><a href='login.php?role=admin'>Log In to Admin Panel</a></p>";

} catch (PDOException $e) {
    echo "<h3>❌ Seeding Failed:</h3> " . $e->getMessage();
}