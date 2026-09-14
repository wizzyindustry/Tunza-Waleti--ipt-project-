<?php
// seed_staff.php

require_once 'database/config.php';

if (!isset($pdo) && isset($conn)) {
    $pdo = $conn;
}

// 1. Define Default Customer Care Credentials
$full_name = "Customer Care Officer";
$phone     = "255700000001"; // Used as login identifier
$password  = "Care@2026";    // Default login password
$role      = "customer_care";
$status    = "active";

// 2. Hash Password Securely
$hashed_password = password_hash($password, PASSWORD_BCRYPT);

try {
    // 3. Insert or Update Customer Care User Record
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

    echo "<h3>✅ Customer Care Officer initialized successfully!</h3>";
    echo "<p><strong>Phone Number:</strong> 0700000001 (or 255700000001)</p>";
    echo "<p><strong>Password:</strong> Care@2026</p>";
    echo "<p><strong>Role:</strong> customer_care</p>";
    echo "<p><a href='login.php?role=customer_care'>Go to Login Page</a></p>";

} catch (PDOException $e) {
    echo "<h3>❌ Seeding Failed:</h3> " . $e->getMessage();
}