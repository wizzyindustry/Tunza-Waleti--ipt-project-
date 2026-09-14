<?php
// admin/dashboard.php

// 1. Set isolated administrator session name BEFORE starting session to prevent cross-tab collision
session_name('TUNZA_MAIN_ADMIN_SESSION');
session_start();

require_once '../database/auth.php';
require_once '../database/config.php';

// Normalize PDO connection handle
if (!isset($pdo) && isset($conn)) {
    $pdo = $conn;
}

// 2. Fetch system logo dynamically
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

// Ensure the user is authenticated and holds Administrator privileges
if (!isset($_SESSION['admin_id']) || ($_SESSION['admin_role'] ?? '') !== 'admin') {
    header("Location: ../login.php?role=admin");
    exit();
}

$current_admin_id = $_SESSION['admin_id'];
$user_name        = $_SESSION['admin_name'] ?? 'Administrator';

// Feedback Messages
$error_msg   = $_SESSION['admin_error'] ?? '';
$success_msg = $_SESSION['admin_success'] ?? '';
unset($_SESSION['admin_error'], $_SESSION['admin_success']);

// ------------------------------------------------------------------
// 3. ACTION HANDLER: DELETE USER ACCOUNT
// ------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'remove_user') {
    $target_user_id = filter_var($_POST['target_user_id'] ?? 0, FILTER_VALIDATE_INT);

    if (!$target_user_id) {
        $_SESSION['admin_error'] = "Invalid user account specified.";
    } elseif ($target_user_id === $current_admin_id) {
        $_SESSION['admin_error'] = "Security Warning: You cannot remove your own active administrator account.";
    } else {
        try {
            // Verify target user details before proceeding
            $checkStmt = $pdo->prepare("SELECT role, full_name FROM users WHERE id = :id");
            $checkStmt->execute(['id' => $target_user_id]);
            $targetUser = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if (!$targetUser) {
                $_SESSION['admin_error'] = "User record not found.";
            } elseif ($targetUser['role'] === 'admin') {
                $_SESSION['admin_error'] = "Action Denied: Administrator accounts cannot be removed directly.";
            } else {
                $pdo->beginTransaction();

                // Clean up related records
                $pdo->prepare("DELETE FROM transactions WHERE user_id = :id")->execute(['id' => $target_user_id]);
                $pdo->prepare("DELETE FROM savings_goals WHERE user_id = :id")->execute(['id' => $target_user_id]);
                $pdo->prepare("DELETE FROM wallets WHERE user_id = :id")->execute(['id' => $target_user_id]);

                // Delete main user record
                $deleteStmt = $pdo->prepare("DELETE FROM users WHERE id = :id");
                $deleteStmt->execute(['id' => $target_user_id]);

                $pdo->commit();

                $_SESSION['admin_success'] = "Account for " . htmlspecialchars($targetUser['full_name']) . " (#{$target_user_id}) was successfully removed.";
            }
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $_SESSION['admin_error'] = "Failed to remove user account: " . $e->getMessage();
        }
    }

    header("Location: dashboard.php#users");
    exit();
}

// ------------------------------------------------------------------
// 4. AGGREGATE SYSTEM METRICS
// ------------------------------------------------------------------
$totalUsers         = 0;
$totalOfficers      = 0;
$totalDeposits      = 0;
$totalWithdrawals   = 0;
$totalWalletBal     = 0;
$totalSavings       = 0;
$recentTransactions = [];
$systemUsers        = [];

try {
    // Total registered regular users
    $totalUsers = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'user'")->fetchColumn() ?: 0;

    // Total system officers & administrators
    $totalOfficers = $pdo->query("SELECT COUNT(*) FROM users WHERE role IN ('admin', 'officer')")->fetchColumn() ?: 0;

    // Total completed deposits collected
    $totalDeposits = $pdo->query("
        SELECT SUM(amount) 
        FROM transactions 
        WHERE LOWER(type) = 'deposit' 
        AND LOWER(status) IN ('completed', 'success', 'successful')
    ")->fetchColumn() ?: 0.00;

    // Total completed withdrawals disbursed
    $totalWithdrawals = $pdo->query("
        SELECT SUM(amount) 
        FROM transactions 
        WHERE LOWER(type) IN ('withdraw', 'withdrawal', 'payout') 
        AND LOWER(status) IN ('completed', 'success', 'successful')
    ")->fetchColumn() ?: 0.00;

    // Total active circulating funds in user wallets
    $totalWalletBal = $pdo->query("SELECT SUM(balance) FROM wallets")->fetchColumn() ?: 0.00;

    // Total active goal savings locked
    $totalSavings = $pdo->query("SELECT SUM(current_amount) FROM savings_goals")->fetchColumn() ?: 0.00;

    // Fetch recent system-wide transactions (Deposits & Withdrawals)
    $txnStmt = $pdo->query("
        SELECT t.*, u.full_name 
        FROM transactions t 
        JOIN users u ON t.user_id = u.id 
        ORDER BY t.created_at DESC 
        LIMIT 15
    ");
    if ($txnStmt) {
        $recentTransactions = $txnStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    // Fetch user list for account management
    $userStmt = $pdo->query("SELECT id, full_name, phone_number, role, created_at FROM users ORDER BY created_at DESC LIMIT 30");
    if ($userStmt) {
        $systemUsers = $userStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

} catch (PDOException $e) {
    $error_msg = "System metric error: " . $e->getMessage();
}

// ------------------------------------------------------------------
// 5. ACTION HANDLER: GENERATE SYSTEM REPORT (CSV EXPORT)
// ------------------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] === 'export_report') {
    $fileName = 'system_report_' . date('Y-m-d_H-i-s') . '.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');

    $output = fopen('php://output', 'w');

    // Section 1: Report Title
    fputcsv($output, ['TUNZA WALETI - EXECUTIVE SYSTEM REPORT']);
    fputcsv($output, ['Generated On', date('Y-m-d H:i:s')]);
    fputcsv($output, ['Generated By', $user_name . ' (Admin ID #' . $current_admin_id . ')']);
    fputcsv($output, []); // Blank space

    // Section 2: Key Financial & User Metrics
    fputcsv($output, ['SYSTEM EXECUTIVE METRICS']);
    fputcsv($output, ['Metric Name', 'Value']);
    fputcsv($output, ['Total Registered Users', $totalUsers]);
    fputcsv($output, ['Total Officers & Admins', $totalOfficers]);
    fputcsv($output, ['Total Completed Deposits (TZS)', number_format($totalDeposits, 2, '.', '')]);
    fputcsv($output, ['Total Completed Withdrawals (TZS)', number_format($totalWithdrawals, 2, '.', '')]);
    fputcsv($output, ['Active Wallet Balance (TZS)', number_format($totalWalletBal, 2, '.', '')]);
    fputcsv($output, ['Locked Goal Savings (TZS)', number_format($totalSavings, 2, '.', '')]);
    fputcsv($output, []); // Blank space

    // Section 3: Recent Transactions Audit Ledger
    fputcsv($output, ['RECENT SYSTEM TRANSACTIONS AUDIT LEDGER']);
    fputcsv($output, ['Reference No', 'User Name', 'Type', 'Amount (TZS)', 'Payment Method', 'Status', 'Date']);

    foreach ($recentTransactions as $txn) {
        $rawType    = strtolower($txn['type']);
        $isWithdraw = in_array($rawType, ['withdraw', 'withdrawal', 'payout']);
        $amountVal  = ($isWithdraw ? '-' : '+') . number_format($txn['amount'], 2, '.', '');

        fputcsv($output, [
            $txn['reference_no'],
            $txn['full_name'],
            strtoupper($txn['type']),
            $amountVal,
            $txn['payment_method'],
            strtoupper($txn['status']),
            date('Y-m-d H:i:s', strtotime($txn['created_at']))
        ]);
    }

    fclose($output);
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Control Panel | Tunza Waleti</title>
    <link rel="shortcut icon" href="../<?= htmlspecialchars($site_logo) ?>" type="image/x-icon">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap');

        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Poppins', sans-serif; }
        html, body { height: 100%; overflow: hidden; background-color: #f4f7f6; color: #2c3e50; }

        .admin-container { display: flex; height: 100vh; width: 100vw; overflow: hidden; position: relative; }

        /* Fixed Sidebar Navigation */
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

        /* Scrollable Main Content Area */
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

        /* Stat Cards */
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: #fff; padding: 20px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); display: flex; align-items: center; gap: 15px; }
        .stat-icon { width: 50px; height: 50px; border-radius: 10px; background: #faf4f9; color: #510049; display: flex; align-items: center; justify-content: center; font-size: 22px; flex-shrink: 0; }
        .stat-icon.deposit { background: #e8f8f0; color: #27ae60; }
        .stat-icon.withdraw { background: #fdeaea; color: #e74c3c; }
        .stat-icon.wallet { background: #eef2fe; color: #3498db; }
        .stat-info h3 { font-size: 18px; font-weight: 700; color: #1a252f; word-break: break-word; }
        .stat-info p { font-size: 12px; color: #7f8c8d; }

        /* Content Sections / Tables */
        .panel-section { background: #fff; padding: 25px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); margin-bottom: 30px; }
        .panel-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .panel-header h3 { font-size: 18px; color: #3d0037; }

        .table-responsive { width: 100%; overflow-x: auto; }

        .data-table { width: 100%; border-collapse: collapse; font-size: 14px; text-align: left; }
        .data-table th { background: #f8f9fa; padding: 12px 15px; font-weight: 600; color: #555; border-bottom: 2px solid #eee; white-space: nowrap; }
        .data-table td { padding: 12px 15px; border-bottom: 1px solid #eee; white-space: nowrap; }
        
        .badge { padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; text-transform: uppercase; }
        .badge.completed, .badge.success, .badge.successful { background: #d4edda; color: #155724; }
        .badge.processing, .badge.pending { background: #fff3cd; color: #856404; }
        .badge.failed, .badge.rejected { background: #f8d7da; color: #721c24; }
        .badge.admin { background: #cce5ff; color: #004085; }
        .badge.officer { background: #e2d9f3; color: #510049; }
        .badge.user { background: #e2e3e5; color: #383d41; }

        .type-tag { font-weight: 700; font-size: 12px; display: inline-flex; align-items: center; gap: 4px; }
        .type-tag.deposit { color: #27ae60; }
        .type-tag.withdraw { color: #e74c3c; }

        /* Action Buttons */
        .btn-action { padding: 8px 16px; border: none; border-radius: 6px; background: #510049; color: #fff; cursor: pointer; font-size: 12px; font-weight: 600; transition: 0.3s; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; }
        .btn-action:hover { background: #000; }
        .btn-report { background: #27ae60; }
        .btn-report:hover { background: #1e8449; }
        .btn-danger { background: #c0392b; }
        .btn-danger:hover { background: #962d22; }
        .btn-secondary { background: #7f8c8d; }
        .btn-secondary:hover { background: #616e6f; }

        .action-cell { display: flex; gap: 8px; align-items: center; }

        /* Popup Modal Styles */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.55);
            backdrop-filter: blur(4px);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 9999;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
        }

        .modal-overlay.active {
            opacity: 1;
            visibility: visible;
        }

        .modal-box {
            background: #ffffff;
            width: 90%;
            max-width: 420px;
            padding: 28px;
            border-radius: 16px;
            box-shadow: 0 15px 35px rgba(0,0,0,0.25);
            text-align: center;
            transform: scale(0.8);
            transition: all 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        }

        .modal-overlay.active .modal-box {
            transform: scale(1);
        }

        .modal-icon {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            margin: 0 auto 15px auto;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
        }

        .modal-icon.success { background: #e8f8f0; color: #27ae60; }
        .modal-icon.error { background: #fdeaea; color: #e74c3c; }
        .modal-icon.warning { background: #fef5e7; color: #f39c12; }

        .modal-box h3 { font-size: 20px; color: #2c3e50; margin-bottom: 8px; }
        .modal-box p { font-size: 14px; color: #666; margin-bottom: 22px; line-height: 1.5; }

        .modal-buttons { display: flex; gap: 10px; justify-content: center; }
        .modal-buttons button { padding: 10px 20px; font-size: 14px; font-weight: 600; border-radius: 8px; }

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
            <li class="active"><a href="dashboard.php"><i class="fas fa-chart-line"></i> Dashboard</a></li>
            <li><a href="users.php"><i class="fas fa-users-cog"></i> Manage Users</a></li>
            <li><a href="transactions.php"><i class="fas fa-exchange-alt"></i> Transactions</a></li>
            <li><a href="settings.php"><i class="fas fa-cog"></i> Settings</a></li>
            <li><a href="../pages/dashboard.php"><i class="fas fa-user-circle"></i> User Panel</a></li>
            <li><a href="../pages/logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
        </ul>
    </div>

    <!-- Scrollable Main Content Area -->
    <div class="main-content">
        <div class="header-bar">
            <div class="header-title-container">
                <button class="menu-toggle" id="menuToggleBtn" onclick="toggleSidebar()" aria-label="Toggle Sidebar">
                    <i class="fas fa-bars"></i>
                </button>
                <div>
                    <h2>Administrator Control Center</h2>
                    <p style="font-size: 13px; color: #666;">Welcome back, <?= htmlspecialchars($user_name) ?></p>
                </div>
            </div>
            <!-- Dynamic System Report Export Button -->
            <a href="dashboard.php?action=export_report" class="btn-action btn-report">
                <i class="fas fa-file-csv"></i> Generate System Report
            </a>
        </div>

        <!-- Metric Stat Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-users"></i></div>
                <div class="stat-info">
                    <h3><?= number_format($totalUsers) ?></h3>
                    <p>Total Registered Users</p>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon deposit"><i class="fas fa-arrow-down"></i></div>
                <div class="stat-info">
                    <h3>TZS <?= number_format($totalDeposits, 2) ?></h3>
                    <p>Total Deposits Collected</p>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon withdraw"><i class="fas fa-arrow-up"></i></div>
                <div class="stat-info">
                    <h3>TZS <?= number_format($totalWithdrawals, 2) ?></h3>
                    <p>Total Withdrawals Disbursed</p>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon wallet"><i class="fas fa-wallet"></i></div>
                <div class="stat-info">
                    <h3>TZS <?= number_format($totalWalletBal, 2) ?></h3>
                    <p>Active Wallet Balance</p>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-piggy-bank"></i></div>
                <div class="stat-info">
                    <h3>TZS <?= number_format($totalSavings, 2) ?></h3>
                    <p>Locked Goal Savings</p>
                </div>
            </div>
        </div>

        <!-- Recent System Transactions Panel -->
        <div class="panel-section" id="transactions">
            <div class="panel-header">
                <h3><i class="fas fa-list-alt"></i> Live System Transactions (Deposits & Withdrawals)</h3>
            </div>
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Reference</th>
                            <th>User</th>
                            <th>Type</th>
                            <th>Amount (TZS)</th>
                            <th>Method</th>
                            <th>Status</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($recentTransactions)): ?>
                            <?php foreach ($recentTransactions as $txn): 
                                $txnType = strtolower($txn['type']);
                                $isWithdraw = in_array($txnType, ['withdraw', 'withdrawal', 'payout']);
                            ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($txn['reference_no']) ?></strong></td>
                                    <td><?= htmlspecialchars($txn['full_name']) ?></td>
                                    <td>
                                        <span class="type-tag <?= $isWithdraw ? 'withdraw' : 'deposit' ?>">
                                            <i class="fas fa-arrow-<?= $isWithdraw ? 'up' : 'down' ?>"></i>
                                            <?= strtoupper($txn['type']) ?>
                                        </span>
                                    </td>
                                    <td><strong><?= number_format($txn['amount'], 2) ?></strong></td>
                                    <td><?= htmlspecialchars($txn['payment_method']) ?></td>
                                    <td><span class="badge <?= strtolower($txn['status']) ?>"><?= htmlspecialchars($txn['status']) ?></span></td>
                                    <td><?= date('d M Y, H:i', strtotime($txn['created_at'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="7" style="text-align: center;">No system transactions recorded yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- User Accounts & Roles Panel -->
        <div class="panel-section" id="users">
            <div class="panel-header">
                <h3><i class="fas fa-users"></i> System Accounts & Privileges</h3>
            </div>
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Full Name</th>
                            <th>Phone Number</th>
                            <th>Role</th>
                            <th>Registered Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($systemUsers)): ?>
                            <?php foreach ($systemUsers as $usr): ?>
                                <tr>
                                    <td>#<?= htmlspecialchars($usr['id']) ?></td>
                                    <td><?= htmlspecialchars($usr['full_name']) ?></td>
                                    <td><?= htmlspecialchars($usr['phone_number'] ?? 'N/A') ?></td>
                                    <td><span class="badge <?= strtolower($usr['role'] ?? 'user') ?>"><?= htmlspecialchars($usr['role'] ?? 'user') ?></span></td>
                                    <td><?= date('d M Y', strtotime($usr['created_at'])) ?></td>
                                    <td>
                                        <div class="action-cell">
                                            <a href="manage_role.php?id=<?= $usr['id'] ?>" class="btn-action">Manage Role</a>
                                            
                                            <?php if ($usr['id'] !== $current_admin_id && $usr['role'] !== 'admin'): ?>
                                                <button type="button" class="btn-action btn-danger" onclick="confirmDeleteUser(<?= $usr['id'] ?>, '<?= htmlspecialchars(addslashes($usr['full_name'])) ?>')">
                                                    <i class="fas fa-trash-alt"></i> Remove
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" style="text-align: center;">No user accounts found.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</div>

<!-- DYNAMIC POPUP MODAL OVERLAYS -->

<!-- 1. Notification Popup Modal -->
<div class="modal-overlay" id="notificationModal">
    <div class="modal-box">
        <div class="modal-icon" id="notifIcon">
            <i class="fas fa-check"></i>
        </div>
        <h3 id="notifTitle">Notification</h3>
        <p id="notifMessage">Message details will appear here.</p>
        <div class="modal-buttons">
            <button type="button" class="btn-action" onclick="closeNotifModal()">OK, Got It</button>
        </div>
    </div>
</div>

<!-- 2. Delete Confirmation Popup Modal -->
<div class="modal-overlay" id="confirmDeleteModal">
    <div class="modal-box">
        <div class="modal-icon warning">
            <i class="fas fa-exclamation-triangle"></i>
        </div>
        <h3>Confirm Account Removal</h3>
        <p>Are you sure you want to remove <strong id="deleteUserName"></strong>? All associated wallets, goals, and history will be permanently deleted.</p>
        
        <form method="POST" action="dashboard.php" id="deleteUserForm">
            <input type="hidden" name="action" value="remove_user">
            <input type="hidden" name="target_user_id" id="deleteUserId">
            
            <div class="modal-buttons">
                <button type="submit" class="btn-action btn-danger">Yes, Remove Account</button>
                <button type="button" class="btn-action btn-secondary" onclick="closeConfirmModal()">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
    function toggleSidebar() {
        const sidebar = document.getElementById('adminSidebar');
        const overlay = document.getElementById('sidebarOverlay');
        sidebar.classList.toggle('active');
        overlay.classList.toggle('active');
    }

    function showNotification(type, title, message) {
        const modal = document.getElementById('notificationModal');
        const iconDiv = document.getElementById('notifIcon');
        const titleElem = document.getElementById('notifTitle');
        const msgElem = document.getElementById('notifMessage');

        titleElem.innerText = title;
        msgElem.innerText = message;

        if (type === 'success') {
            iconDiv.className = 'modal-icon success';
            iconDiv.innerHTML = '<i class="fas fa-check-circle"></i>';
        } else {
            iconDiv.className = 'modal-icon error';
            iconDiv.innerHTML = '<i class="fas fa-times-circle"></i>';
        }

        modal.classList.add('active');
    }

    function closeNotifModal() {
        document.getElementById('notificationModal').classList.remove('active');
    }

    function confirmDeleteUser(userId, userName) {
        document.getElementById('deleteUserId').value = userId;
        document.getElementById('deleteUserName').innerText = userName;
        document.getElementById('confirmDeleteModal').classList.add('active');
    }

    function closeConfirmModal() {
        document.getElementById('confirmDeleteModal').classList.remove('active');
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

    window.addEventListener('DOMContentLoaded', () => {
        <?php if (!empty($error_msg)): ?>
            showNotification('error', 'Action Failed', '<?= htmlspecialchars(addslashes($error_msg)) ?>');
        <?php endif; ?>

        <?php if (!empty($success_msg)): ?>
            showNotification('success', 'Success', '<?= htmlspecialchars(addslashes($success_msg)) ?>');
        <?php endif; ?>
    });
</script>

</body>
</html>