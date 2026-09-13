<?php
// admin/manage_role.php

// 1. Set isolated administrator session name BEFORE starting session to prevent cross-tab collision
session_name('TUNZA_ADMIN_SESSION');
session_start();

require_once '../database/auth.php';
require_once '../database/config.php';

// Normalize database handle
if (!isset($pdo) && isset($conn)) {
    $pdo = $conn;
}

// 2. Fetch system logo dynamically safely
$site_logo = 'assets/images/panta logo-07.jpg'; // Default fallback
if (isset($pdo) && $pdo !== null) {
    try {
        $stmtLogo = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'site_logo' LIMIT 1");
        if ($stmtLogo && $row = $stmtLogo->fetch(PDO::FETCH_ASSOC)) {
            if (!empty($row['setting_value'])) {
                $site_logo = $row['setting_value'];
            }
        }
    } catch (PDOException $e) {
        // Fallback silently if table does not exist
    }
}

// Ensure user is logged in as an Administrator
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
    header("Location: ../login.php");
    exit();
}

$user_id_to_edit = filter_var($_GET['id'] ?? 0, FILTER_VALIDATE_INT);
$msg = '';
$msg_type = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_role'])) {
    $new_role = trim($_POST['role'] ?? '');
    
    if (in_array($new_role, ['user', 'officer', 'admin'])) {
        try {
            $updateStmt = $pdo->prepare("UPDATE users SET role = :role WHERE id = :id");
            $updateStmt->execute(['role' => $new_role, 'id' => $user_id_to_edit]);
            $msg = "User role successfully updated to " . strtoupper($new_role) . ".";
            $msg_type = 'success';
        } catch (PDOException $e) {
            $msg = "Error updating user role: " . $e->getMessage();
            $msg_type = 'error';
        }
    } else {
        $msg = "Invalid role selection.";
        $msg_type = 'error';
    }
}

// Fetch user details
$stmt = $pdo->prepare("SELECT id, full_name, phone_number, role FROM users WHERE id = :id");
$stmt->execute(['id' => $user_id_to_edit]);
$targetUser = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$targetUser) {
    header("Location: users.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage User Role | Tunza Admin</title>
    <link rel="shortcut icon" href="../<?= htmlspecialchars($site_logo) ?>" type="image/x-icon">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap');

        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Poppins', sans-serif; }
        html, body { height: 100%; overflow: hidden; background-color: #f4f7f6; color: #2c3e50; }

        .admin-container { display: flex; height: 100vh; width: 100vw; overflow: hidden; position: relative; }

        /* Static / Fixed Sidebar Navigation */
        .sidebar { 
            width: 260px; 
            height: 100vh;
            background: #3d0037; 
            color: #fff; 
            padding: 25px 0; 
            flex-shrink: 0; 
            transition: all 0.3s ease; 
            z-index: 1000;
            overflow-y: auto;
        }
        .sidebar-brand { text-align: center; padding-bottom: 25px; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .sidebar-brand h2 { font-size: 20px; color: #fff; }
        .sidebar-menu { list-style: none; margin-top: 20px; }
        .sidebar-menu li a { display: flex; align-items: center; gap: 12px; padding: 14px 25px; color: #d8c2d5; text-decoration: none; font-size: 14px; transition: 0.3s; }
        .sidebar-menu li a:hover, .sidebar-menu li.active a { background: #510049; color: #fff; border-left: 4px solid #e74c3c; }

        /* Scrollable Main Content */
        .main-content { 
            flex-grow: 1; 
            height: 100vh;
            padding: 30px; 
            overflow-y: auto; 
            width: calc(100vw - 260px); 
        }

        .header-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; gap: 15px; flex-wrap: wrap; }
        .header-title-container { display: flex; align-items: center; gap: 15px; }
        
        /* Hamburger Toggle Button */
        .menu-toggle { 
            display: none; 
            background: #510049; 
            color: #fff; 
            border: none; 
            font-size: 18px; 
            padding: 10px 14px; 
            border-radius: 8px; 
            cursor: pointer; 
            transition: 0.3s;
        }
        .menu-toggle:hover { background: #000; }

        /* Sidebar Backdrop Overlay on Mobile */
        .sidebar-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 999;
            backdrop-filter: blur(2px);
        }
        .sidebar-overlay.active { display: block; }

        /* Form Card Layout */
        .role-card {
            background: #fff;
            max-width: 520px;
            margin: 20px 0;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
        }

        .role-card h3 {
            font-size: 18px;
            color: #3d0037;
            margin-bottom: 20px;
            border-bottom: 2px solid #f0f0f0;
            padding-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert-box {
            padding: 12px 16px;
            border-radius: 8px;
            font-size: 13px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .alert-box.success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-box.error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }

        .form-group { margin-bottom: 20px; text-align: left; }
        .form-group label { display: block; margin-bottom: 8px; font-weight: 600; font-size: 13px; color: #444; }
        .form-group input, .form-group select { 
            width: 100%; 
            padding: 11px 14px; 
            border-radius: 8px; 
            border: 1.5px solid #ddd; 
            font-size: 14px; 
            outline: none;
            transition: 0.3s;
        }
        .form-group input:disabled {
            background: #f8f9fa;
            color: #777;
            cursor: not-allowed;
        }
        .form-group select:focus {
            border-color: #510049;
        }

        .btn-action { 
            padding: 12px 20px; 
            border: none; 
            border-radius: 8px; 
            background: #510049; 
            color: #fff; 
            cursor: pointer; 
            font-size: 14px; 
            font-weight: 600; 
            transition: 0.3s; 
            text-decoration: none; 
            display: inline-flex; 
            align-items: center; 
            justify-content: center;
            gap: 8px; 
            width: 100%;
        }
        .btn-action:hover { background: #000; }

        .btn-secondary {
            background: #7f8c8d;
            margin-top: 10px;
        }
        .btn-secondary:hover { background: #626d6e; }

        /* Responsive Breakpoints */
        @media (max-width: 768px) {
            .menu-toggle { display: inline-block; }
            .sidebar {
                position: fixed;
                top: 0;
                left: -260px;
                height: 100vh;
                box-shadow: 4px 0 15px rgba(0,0,0,0.2);
            }
            .sidebar.active {
                left: 0;
            }
            .main-content { 
                padding: 20px 15px; 
                width: 100vw;
            }
        }
    </style>
</head>
<body>

<div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

<div class="admin-container">
    <!-- Static / Fixed Sidebar Navigation -->
    <div class="sidebar" id="adminSidebar">
        <div class="sidebar-brand">
            <img src="../<?= htmlspecialchars($site_logo) ?>" alt="Tunza Logo" style="width: 50px; height: 50px; border-radius: 50%; object-fit: cover;">
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

    <!-- Scrollable Main Content -->
    <div class="main-content">
        <div class="header-bar">
            <div class="header-title-container">
                <button class="menu-toggle" id="menuToggleBtn" onclick="toggleSidebar()" aria-label="Toggle Sidebar">
                    <i class="fas fa-bars"></i>
                </button>
                <div>
                    <h2>Manage Role & Permissions</h2>
                    <p style="font-size: 13px; color: #666;">Assign system access permissions for selected user accounts</p>
                </div>
            </div>
            <a href="users.php" class="btn-action btn-secondary" style="width: auto;">
                <i class="fas fa-arrow-left"></i> Return to Users
            </a>
        </div>

        <div class="role-card">
            <h3><i class="fas fa-user-shield"></i> User Account Settings</h3>

            <?php if (!empty($msg)): ?>
                <div class="alert-box <?= $msg_type === 'success' ? 'success' : 'error' ?>">
                    <i class="fas <?= $msg_type === 'success' ? 'fa-check-circle' : 'fa-exclamation-triangle' ?>"></i>
                    <?= htmlspecialchars($msg) ?>
                </div>
            <?php endif; ?>

            <form method="POST">
                <div class="form-group">
                    <label>Full Name</label>
                    <input type="text" value="<?= htmlspecialchars($targetUser['full_name']) ?>" disabled>
                </div>

                <div class="form-group">
                    <label>Phone Number</label>
                    <input type="text" value="<?= htmlspecialchars($targetUser['phone_number'] ?? 'N/A') ?>" disabled>
                </div>

                <div class="form-group">
                    <label>Assigned System Role</label>
                    <select name="role" required>
                        <option value="user" <?= $targetUser['role'] === 'user' ? 'selected' : '' ?>>User (Customer)</option>
                        <option value="officer" <?= $targetUser['role'] === 'officer' ? 'selected' : '' ?>>Officer (Auditor)</option>
                        <option value="admin" <?= $targetUser['role'] === 'admin' ? 'selected' : '' ?>>Administrator</option>
                    </select>
                </div>

                <button type="submit" name="update_role" class="btn-action">
                    <i class="fas fa-save"></i> Save Role Settings
                </button>
            </form>
        </div>

    </div>
</div>

<script>
    function toggleSidebar() {
        const sidebar = document.getElementById('adminSidebar');
        const overlay = document.getElementById('sidebarOverlay');
        sidebar.classList.toggle('active');
        overlay.classList.toggle('active');
    }

    // Cross-tab session state listener
    window.addEventListener('storage', function(event) {
        if (event.key === 'tunza_session_update') {
            const sessionData = JSON.parse(event.newValue);
            if (sessionData && sessionData.role === 'user') {
                window.location.href = '../pages/dashboard.php';
            }
        }
    });
</script>

</body>
</html>