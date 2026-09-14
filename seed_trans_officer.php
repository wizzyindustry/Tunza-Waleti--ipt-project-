<?php
// seed_transaction_officer.php

require_once 'database/config.php';

if (!isset($pdo) && isset($conn)) {
    $pdo = $conn;
}

// 1. Define Default Transaction Officer Credentials
$full_name = "Transaction Officer";
$phone     = "255700000002"; // Login identifier
$password  = "Finance@2026"; // Default login password
$role      = "transaction_officer";
$status    = "active";

// 2. Hash Password Securely
$hashed_password = password_hash($password, PASSWORD_BCRYPT);

try {
    // 3. Insert or Update Transaction Officer User Record
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

    // 4. Ensure a corresponding wallet record exists
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

    echo "<h3>✅ Transaction Officer initialized successfully!</h3>";
    echo "<p><strong>Phone Number:</strong> 0700000002 (or 255700000002)</p>";
    echo "<p><strong>Password:</strong> Finance@2026</p>";
    echo "<p><strong>Role:</strong> transaction_officer</p>";
    echo "<p><a href='login.php?role=transaction_officer'>Go to Login Page</a></p>";

} catch (PDOException $e) {
    echo "<h3>❌ Seeding Failed:</h3> " . $e->getMessage();
}