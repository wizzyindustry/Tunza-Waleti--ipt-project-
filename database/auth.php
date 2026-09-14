<?php
// database/auth.php

// 1. Detect and load session context based on active session cookies or current path
if (session_status() === PHP_SESSION_NONE) {
    if (isset($_COOKIE['TUNZA_MAIN_ADMIN_SESSION'])) {
        session_name('TUNZA_MAIN_ADMIN_SESSION');
    } elseif (isset($_COOKIE['TUNZA_CARE_OFFICER_SESSION'])) {
        session_name('TUNZA_CARE_OFFICER_SESSION');
    } elseif (isset($_COOKIE['TUNZA_FINANCE_OFFICER_SESSION'])) {
        session_name('TUNZA_FINANCE_OFFICER_SESSION');
    } else {
        session_name('TUNZA_USER_SESSION');
    }
    session_start();
}

require_once __DIR__ . '/config.php';

// Normalize PDO handle safely
if (!isset($pdo) && isset($conn)) {
    $pdo = $conn;
}

// 2. Identify authenticated entity across standard user and staff sessions
$active_user_id = $_SESSION['user_id'] ?? $_SESSION['admin_id'] ?? $_SESSION['care_id'] ?? $_SESSION['finance_id'] ?? null;
$active_role    = $_SESSION['user_role'] ?? $_SESSION['admin_role'] ?? $_SESSION['care_role'] ?? $_SESSION['finance_role'] ?? null;

// Determine relative path depth to build clean login redirect URLs
$currentPath = $_SERVER['SCRIPT_NAME'] ?? '';
$loginRedirect = (strpos($currentPath, '/admin/') !== false) ? '../login.php' : '../login.php';
if (strpos($currentPath, '/admin/customer_care/') !== false || strpos($currentPath, '/admin/transaction_officer/') !== false) {
    $loginRedirect = '../../admin/login.php';
} elseif (strpos($currentPath, '/admin/') !== false) {
    $loginRedirect = 'login.php';
}

// Redirect unauthenticated users
if (!$active_user_id) {
    header("Location: " . $loginRedirect);
    exit();
}

// 3. Global Account Status Check (Suspension Enforcement for standard users)
if ($active_role === 'user' && $pdo !== null) {
    try {
        $hasStatusCol = false;
        $colCheck = $pdo->query("SHOW COLUMNS FROM users LIKE 'status'");
        if ($colCheck && $colCheck->rowCount() > 0) {
            $hasStatusCol = true;
        }

        if ($hasStatusCol) {
            $stmtStatus = $pdo->prepare("SELECT status FROM users WHERE id = :user_id LIMIT 1");
            $stmtStatus->execute(['user_id' => $active_user_id]);
            $userStatus = strtolower($stmtStatus->fetchColumn() ?: 'active');

            if ($userStatus === 'suspended') {
                $currentScript = basename($_SERVER['SCRIPT_NAME'] ?? '');

                // Allow suspended user to view dashboard.php (with suspension modal) or logout.php cleanly
                if (!in_array($currentScript, ['dashboard.php', 'logout.php'])) {
                    $_SESSION['error_msg'] = "Your account has been suspended. Access to this feature is restricted.";
                    header("Location: dashboard.php");
                    exit();
                }
            }
        }
    } catch (PDOException $e) {
        // Fail-safe in case of DB glitch
    }
}

/**
 * 4. Helper Function: Check explicit role authorization for protected pages
 * Example usage: checkRoleAccess(['admin', 'customer_care']);
 */
function checkRoleAccess(array $allowedRoles = []) {
    global $active_role, $loginRedirect;

    if (!in_array($active_role, $allowedRoles)) {
        $_SESSION['admin_error'] = "Access denied: You do not have permission to view this section.";
        
        switch ($active_role) {
            case 'admin':
                header("Location: ../admin/dashboard.php");
                break;
            case 'customer_care':
                header("Location: ../admin/customer_care/dashboard.php");
                break;
            case 'transaction_officer':
                header("Location: ../admin/transaction_officer/dashboard.php");
                break;
            default:
                header("Location: ../pages/dashboard.php");
                break;
        }
        exit();
    }
}