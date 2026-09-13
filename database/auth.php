<?php
// database/auth.php

// 1. Ensure user session is started
if (session_status() === PHP_SESSION_NONE) {
    session_name('TUNZA_USER_SESSION');
    session_start();
}

require_once __DIR__ . '/config.php';

// Normalize PDO handle
if (!isset($pdo) && isset($conn)) {
    $pdo = $conn;
}

// 2. Redirect unauthenticated users
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// 3. Global Account Status Check (Suspension Enforcement)
try {
    $hasStatusCol = false;
    $colCheck = $pdo->query("SHOW COLUMNS FROM users LIKE 'status'");
    if ($colCheck && $colCheck->rowCount() > 0) {
        $hasStatusCol = true;
    }

    if ($hasStatusCol) {
        $stmtStatus = $pdo->prepare("SELECT status FROM users WHERE id = :user_id LIMIT 1");
        $stmtStatus->execute(['user_id' => $user_id]);
        $userStatus = strtolower($stmtStatus->fetchColumn() ?: 'active');

        if ($userStatus === 'suspended') {
            // Determine current requesting script name
            $currentScript = basename($_SERVER['SCRIPT_NAME'] ?? '');

            // Allow the user to stay on dashboard.php to view the suspension modal,
            // or hit logout.php to leave cleanly. Block access to all other subpages.
            if (!in_array($currentScript, ['dashboard.php', 'logout.php'])) {
                $_SESSION['error_msg'] = "Your account has been suspended. Access to this feature is restricted.";
                header("Location: dashboard.php");
                exit();
            }
        }
    }
} catch (PDOException $e) {
    // Fail safe in case of DB glitch
}