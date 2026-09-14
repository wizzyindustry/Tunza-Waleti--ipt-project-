<?php
// admin/manage_role.php

session_name('TUNZA_MAIN_ADMIN_SESSION');
session_start();


require_once '../database/auth.php';
require_once '../database/config.php';

if (!isset($pdo) && isset($conn)) {
    $pdo = $conn;
}

// Strictly enforce Main Administrator access only
if (!isset($_SESSION['admin_id']) || ($_SESSION['admin_role'] ?? '') !== 'admin') {
    header("Location: ../login.php?role=admin");
    exit();
}

$current_admin_id = $_SESSION['admin_id'];
$user_name        = $_SESSION['admin_name'] ?? 'Administrator';

$error_msg   = $_SESSION['admin_error'] ?? '';
$success_msg = $_SESSION['admin_success'] ?? '';
unset($_SESSION['admin_error'], $_SESSION['admin_success']);

$target_id = filter_var($_GET['id'] ?? 0, FILTER_VALIDATE_INT);
$targetUser = null;

if ($target_id) {
    $stmtUser = $pdo->prepare("SELECT id, full_name, phone_number, role, status FROM users WHERE id = :id LIMIT 1");
    $stmtUser->execute(['id' => $target_id]);
    $targetUser = $stmtUser->fetch(PDO::FETCH_ASSOC);
}

// ------------------------------------------------------------------
// ROLE UPDATE HANDLER
// ------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_user_role'])) {
    $user_id  = filter_var($_POST['target_user_id'] ?? 0, FILTER_VALIDATE_INT);
    $new_role = trim($_POST['new_role'] ?? '');

    $allowed_roles = ['user', 'customer_care', 'transaction_officer', 'admin'];

    if ($user_id && in_array($new_role, $allowed_roles)) {
        // Prevent admin from revoking their own admin privileges by mistake
        if ($user_id === $current_admin_id && $new_role !== 'admin') {
            $_SESSION['admin_error'] = "Security Protection: You cannot revoke your own Administrator role.";
        } else {
            try {
                $stmt = $pdo->prepare("UPDATE users SET role = :role, updated_at = NOW() WHERE id = :id");
                $stmt->execute(['role' => $new_role, 'id' => $user_id]);

                $_SESSION['admin_success'] = "Role updated successfully to " . strtoupper(str_replace('_', ' ', $new_role)) . ".";
            } catch (PDOException $e) {
                $_SESSION['admin_error'] = "Failed to update role: " . $e->getMessage();
            }
        }
    } else {
        $_SESSION['admin_error'] = "Invalid role selected.";
    }

    header("Location: " . ($user_id ? "manage_role.php?id=" . $user_id : "users.php"));
    exit();
}

// Fetch Logo
$site_logo = 'assets/images/panta logo-07.jpg';
try {
    $stmtLogo = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'site_logo' LIMIT 1");
    if ($stmtLogo && $row = $stmtLogo->fetch(PDO::FETCH_ASSOC)) {
        if (!empty($row['setting_value'])) { $site_logo = $row['setting_value']; }
    }
} catch (PDOException $e) {}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage User Role | Main Admin</title>
    <link rel="shortcut icon" href="../<?= htmlspecialchars($site_logo) ?>" type="image/x-icon">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap');
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Poppins', sans-serif; }
        body { height: 100vh; overflow: hidden; background-color: #f4f7f6; color: #2c3e50; }

        .admin-container { display: flex; height: 100vh; width: 100vw; overflow: hidden; }
        .sidebar { width: 260px; height: 100vh; background: #3d0037; color: #fff; padding: 25px 0; flex-shrink: 0; }
        .sidebar-brand { text-align: center; padding-bottom: 25px; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .sidebar-menu { list-style: none; margin-top: 20px; }
        .sidebar-menu li a { display: flex; align-items: center; gap: 12px; padding: 14px 25px; color: #d8c2d5; text-decoration: none; font-size: 14px; transition: 0.3s; }
        .sidebar-menu li a:hover, .sidebar-menu li.active a { background: #510049; color: #fff; border-left: 4px solid #e74c3c; }

        .main-content { flex-grow: 1; height: 100vh; padding: 30px; overflow-y: auto; }
        .card-panel { background: #fff; padding: 25px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); max-width: 550px; }
        
        .form-group { margin-bottom: 18px; }
        .form-group label { display: block; font-weight: 600; font-size: 13px; color: #444; margin-bottom: 6px; }
        .form-group select { width: 100%; padding: 12px; border-radius: 8px; border: 1.5px solid #ddd; font-size: 14px; outline: none; }

        .btn-action { padding: 12px 24px; border-radius: 8px; background: #510049; color: #fff; border: none; cursor: pointer; font-size: 14px; font-weight: 600; display: inline-flex; align-items: center; justify-content: center; gap: 8px; text-decoration: none; width: 100%; }
        .btn-action:hover { background: #000; }

        .modal-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.55); backdrop-filter: blur(4px); display: flex; align-items: center; justify-content: center; z-index: 9999; opacity: 0; visibility: hidden; transition: all 0.3s ease; }
        .modal-overlay.active { opacity: 1; visibility: visible; }
        .modal-box { background: #fff; width: 90%; max-width: 400px; padding: 25px; border-radius: 16px; text-align: center; }
    </style>
</head>
<body>

<div class="admin-container">
    <div class="sidebar">
        <div class="sidebar-brand">
            <img src="../<?= htmlspecialchars($site_logo) ?>" style="width: 50px; height: 50px; border-radius: 50%;">
            <h2>Tunza Admin</h2>
        </div>
        <ul class="sidebar-menu">
            <li><a href="dashboard.php"><i class="fas fa-chart-line"></i> Dashboard</a></li>
            <li class="active"><a href="users.php"><i class="fas fa-users-cog"></i> Manage Users</a></li>
            <li><a href="transactions.php"><i class="fas fa-exchange-alt"></i> Transactions</a></li>
            <li><a href="settings.php"><i class="fas fa-cog"></i> Settings</a></li>
            <li><a href="../pages/dashboard.php"><i class="fas fa-user-circle"></i> User Panel</a></li>
            <li><a href="../pages/logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
        </ul>
    </div>

    <div class="main-content">
        <div style="margin-bottom: 25px;">
            <h2>Assign User Privileges & Roles</h2>
            <p style="font-size: 13px; color: #666;">Elevate accounts to Customer Care, Transaction Officer, or Main Administrator</p>
        </div>

        <?php if ($targetUser): ?>
            <div class="card-panel">
                <form method="POST">
                    <input type="hidden" name="update_user_role" value="1">
                    <input type="hidden" name="target_user_id" value="<?= $targetUser['id'] ?>">

                    <div style="background: #f8f9fa; padding: 15px; border-radius: 8px; margin-bottom: 20px;">
                        <p style="font-size: 14px;"><strong>Target User:</strong> <?= htmlspecialchars($targetUser['full_name']) ?></p>
                        <p style="font-size: 13px; color: #666; margin-top: 4px;">Phone: <?= htmlspecialchars($targetUser['phone_number']) ?> | ID: #<?= $targetUser['id'] ?></p>
                    </div>

                    <div class="form-group">
                        <label>Select Designated Access Role</label>
                        <select name="new_role" required>
                            <option value="user" <?= $targetUser['role'] === 'user' ? 'selected' : '' ?>>Customer (Standard User)</option>
                            <option value="customer_care" <?= $targetUser['role'] === 'customer_care' ? 'selected' : '' ?>>Customer Care Officer</option>
                            <option value="transaction_officer" <?= $targetUser['role'] === 'transaction_officer' ? 'selected' : '' ?>>Transaction Officer (Finance)</option>
                            <option value="admin" <?= $targetUser['role'] === 'admin' ? 'selected' : '' ?>>Main Administrator</option>
                        </select>
                    </div>

                    <button type="submit" class="btn-action">
                        <i class="fas fa-user-shield"></i> Apply Role Update
                    </button>
                    <a href="users.php" class="btn-action" style="background:#7f8c8d; margin-top: 10px;">Cancel & Return</a>
                </form>
            </div>
        <?php else: ?>
            <div class="card-panel">
                <p style="color: #c0392b;">No user specified for role adjustment. Please select a user from the account list.</p>
                <a href="users.php" class="btn-action" style="margin-top: 15px;">Go to User Management</a>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="modal-overlay" id="notificationModal">
    <div class="modal-box">
        <h3 id="notifTitle">Notification</h3>
        <p id="notifMessage" style="font-size: 14px; color: #555; margin: 15px 0;">Details...</p>
        <button type="button" class="btn-action" onclick="document.getElementById('notificationModal').classList.remove('active')">OK</button>
    </div>
</div>

<script>
    window.addEventListener('DOMContentLoaded', () => {
        <?php if (!empty($error_msg)): ?>
            document.getElementById('notifTitle').innerText = 'Action Error';
            document.getElementById('notifMessage').innerText = '<?= htmlspecialchars(addslashes($error_msg)) ?>';
            document.getElementById('notificationModal').classList.add('active');
        <?php endif; ?>

        <?php if (!empty($success_msg)): ?>
            document.getElementById('notifTitle').innerText = 'Success';
            document.getElementById('notifMessage').innerText = '<?= htmlspecialchars(addslashes($success_msg)) ?>';
            document.getElementById('notificationModal').classList.add('active');
        <?php endif; ?>
    });
</script>

</body>
</html>