<?php
// admin/users.php

// 1. Set isolated administrator session name BEFORE starting session to prevent cross-tab collision
session_name('TUNZA_MAIN_ADMIN_SESSION');
session_start();

require_once '../database/auth.php';
require_once '../database/config.php';

// Include Infobip SMS Service Helper
if (file_exists('../api/sms.php')) {
    require_once '../api/sms.php';
}

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
// 3. ACTION HANDLER: STATUS TOGGLE & BALANCE ADJUSTMENT WITH SMS
// ------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action    = $_POST['action'];
    $target_id = filter_var($_POST['target_user_id'] ?? 0, FILTER_VALIDATE_INT);

    if ($target_id) {
        try {
            // Fetch target user details for SMS notification
            $stmtUser = $pdo->prepare("SELECT full_name, phone_number FROM users WHERE id = :id LIMIT 1");
            $stmtUser->execute(['id' => $target_id]);
            $targetUser = $stmtUser->fetch(PDO::FETCH_ASSOC);

            // A. TOGGLE ACCOUNT STATUS (Active / Suspended)
            if ($action === 'toggle_status') {
                $new_status = trim($_POST['new_status'] ?? 'active');
                if (in_array($new_status, ['active', 'suspended'])) {
                    $stmt = $pdo->prepare("UPDATE users SET status = :status WHERE id = :id AND role != 'admin'");
                    $stmt->execute(['status' => $new_status, 'id' => $target_id]);
                    
                    $_SESSION['admin_success'] = "User account status updated to " . strtoupper($new_status) . ".";

                    // Send SMS notification regarding account status update
                    if ($targetUser && !empty($targetUser['phone_number']) && class_exists('SmsService')) {
                        $uName = $targetUser['full_name'];
                        if ($new_status === 'suspended') {
                            $smsText = "Habari {$uName}, akaunti yako ya Tunza Waleti imesitishwa kwa muda na Msimamizi. Tafadhali wasiliana na huduma kwa wateja kwa msaada zaidi.";
                        } else {
                            $smsText = "Habari {$uName}, akaunti yako ya Tunza Waleti imerejeshwa na ipo tayari kutumika. Ahsante!";
                        }
                        SmsService::sendSMS($pdo, $targetUser['phone_number'], $smsText);
                    }
                }
            }

            // B. ADJUST WALLET BALANCE MANUALLY
            elseif ($action === 'adjust_balance') {
                $adj_amount = filter_var($_POST['adjustment_amount'] ?? 0, FILTER_VALIDATE_FLOAT);
                $adj_type   = $_POST['adjustment_type'] ?? 'add';

                if ($adj_amount > 0) {
                    $pdo->beginTransaction();

                    if ($adj_type === 'add') {
                        $updateWallet = $pdo->prepare("UPDATE wallets SET balance = balance + :amt WHERE user_id = :id");
                        $txnType      = 'deposit';
                    } else {
                        $updateWallet = $pdo->prepare("UPDATE wallets SET balance = GREATEST(0, balance - :amt) WHERE user_id = :id");
                        $txnType      = 'withdraw';
                    }
                    $updateWallet->execute(['amt' => $adj_amount, 'id' => $target_id]);

                    // Log manual adjustment transaction record
                    $orderRef = 'ADJ' . date('YmdHis') . rand(100, 999);
                    $insertTxn = $pdo->prepare("
                        INSERT INTO transactions (reference_no, user_id, type, amount, status, payment_method, description) 
                        VALUES (:ref, :user_id, :type, :amt, 'completed', 'Admin Adjustment', :desc)
                    ");
                    $insertTxn->execute([
                        'ref'     => $orderRef,
                        'user_id' => $target_id,
                        'type'    => $txnType,
                        'amt'     => $adj_amount,
                        'desc'    => "Manual Balance " . ucfirst($adj_type) . " by Admin #" . $current_admin_id
                    ]);

                    // Fetch updated wallet balance for SMS disclosure
                    $stmtBal = $pdo->prepare("SELECT balance FROM wallets WHERE user_id = :id LIMIT 1");
                    $stmtBal->execute(['id' => $target_id]);
                    $newBalance = (float)($stmtBal->fetchColumn() ?: 0.00);

                    $pdo->commit();

                    $_SESSION['admin_success'] = "Successfully adjusted user balance by TZS " . number_format($adj_amount, 2) . ".";

                    // Send Infobip SMS Notification
                    if ($targetUser && !empty($targetUser['phone_number']) && class_exists('SmsService')) {
                        $uName    = $targetUser['full_name'];
                        $formattedAdj = number_format($adj_amount, 2);
                        $formattedBal = number_format($newBalance, 2);

                        if ($adj_type === 'add') {
                            $smsText = "Habari {$uName}, akaunti yako imeongezewa TZS {$formattedAdj} na Msimamizi. Salio lako jipya ni TZS {$formattedBal}. Ahsante!";
                        } else {
                            $smsText = "Habari {$uName}, TZS {$formattedAdj} zimekatwa kwenye akaunti yako na Msimamizi. Salio lako jipya ni TZS {$formattedBal}.";
                        }

                        SmsService::sendSMS($pdo, $targetUser['phone_number'], $smsText);
                    }
                } else {
                    $_SESSION['admin_error'] = "Invalid adjustment amount.";
                }
            }
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $_SESSION['admin_error'] = "Action failed: " . $e->getMessage();
        }
    }
    header("Location: users.php");
    exit();
}

// ------------------------------------------------------------------
// 4. FETCH USERS & SEARCH FILTER
// ------------------------------------------------------------------
$searchQuery  = trim($_GET['search'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');
$exportFormat = strtolower(trim($_GET['export'] ?? ''));

// Check if status column exists in table to prevent crashes
$hasStatusCol = false;
try {
    $colCheck = $pdo->query("SHOW COLUMNS FROM users LIKE 'status'");
    if ($colCheck && $colCheck->rowCount() > 0) {
        $hasStatusCol = true;
    }
} catch (PDOException $e) {}

$statusSelect = $hasStatusCol ? "u.status" : "'active' AS status";

$sql = "
    SELECT u.id, u.full_name, u.phone_number, u.role, {$statusSelect}, u.created_at,
           COALESCE(w.balance, 0.00) AS wallet_balance,
           (SELECT COUNT(*) FROM savings_goals sg WHERE sg.user_id = u.id) AS total_goals
    FROM users u
    LEFT JOIN wallets w ON u.id = w.user_id
    WHERE u.role = 'user'
";

$params = [];

if (!empty($searchQuery)) {
    $sql .= " AND (u.full_name LIKE :search OR u.phone_number LIKE :search OR CAST(u.id AS CHAR) = :exact_search)";
    $params['search'] = "%{$searchQuery}%";
    $params['exact_search'] = $searchQuery;
}

if ($hasStatusCol && !empty($statusFilter) && in_array($statusFilter, ['active', 'suspended'])) {
    $sql .= " AND u.status = :status";
    $params['status'] = $statusFilter;
}

$sql .= " ORDER BY u.created_at DESC";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $userList = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // ------------------------------------------------------------------
    // CSV EXPORT HANDLER
    // ------------------------------------------------------------------
    if ($exportFormat === 'csv') {
        $fileName = 'user_accounts_' . date('Y-m-d_H-i-s') . '.csv';

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $fileName . '"');

        $output = fopen('php://output', 'w');

        // CSV Headers
        fputcsv($output, ['User ID', 'Full Name', 'Phone Number', 'Role', 'Wallet Balance (TZS)', 'Total Goals', 'Status', 'Registered Date']);

        // Data Rows
        foreach ($userList as $usr) {
            fputcsv($output, [
                '#' . $usr['id'],
                $usr['full_name'],
                $usr['phone_number'] ?? 'N/A',
                strtoupper($usr['role'] ?? 'USER'),
                number_format($usr['wallet_balance'], 2, '.', ''),
                $usr['total_goals'],
                strtoupper($usr['status'] ?? 'ACTIVE'),
                date('Y-m-d H:i:s', strtotime($usr['created_at']))
            ]);
        }

        fclose($output);
        exit();
    }

} catch (PDOException $e) {
    $userList = [];
    $error_msg = "Database Error: " . $e->getMessage();
}

// Build URL query string for Export link while retaining active filters
$exportQueryParams = $_GET;
$exportQueryParams['export'] = 'csv';
$exportUrl = 'users.php?' . http_build_query($exportQueryParams);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management | Tunza Admin</title>
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

        .filter-card { background: #fff; padding: 20px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); margin-bottom: 25px; }
        .filter-form { display: flex; gap: 15px; flex-wrap: wrap; }
        .filter-form input, .filter-form select { padding: 10px 14px; border: 1.5px solid #ddd; border-radius: 8px; font-size: 14px; outline: none; }
        .filter-form input[type="text"] { flex-grow: 1; min-width: 220px; }

        .panel-section { background: #fff; padding: 25px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); margin-bottom: 30px; }
        .table-responsive { width: 100%; overflow-x: auto; }
        
        .data-table { width: 100%; border-collapse: collapse; font-size: 14px; text-align: left; }
        .data-table th { background: #f8f9fa; padding: 12px 15px; font-weight: 600; color: #555; border-bottom: 2px solid #eee; white-space: nowrap; }
        .data-table td { padding: 12px 15px; border-bottom: 1px solid #eee; white-space: nowrap; }

        .badge { padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; text-transform: uppercase; }
        .badge.active { background: #d4edda; color: #155724; }
        .badge.suspended { background: #f8d7da; color: #721c24; }

        .btn-action { padding: 8px 16px; border: none; border-radius: 6px; background: #510049; color: #fff; cursor: pointer; font-size: 13px; font-weight: 600; transition: 0.3s; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; }
        .btn-action:hover { background: #000; }
        .btn-warning { background: #f39c12; }
        .btn-warning:hover { background: #d68910; }
        .btn-export { background: #27ae60; }
        .btn-export:hover { background: #1e8449; }

        .action-cell { display: flex; gap: 6px; align-items: center; }

        /* Modal Styles */
        .modal-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.55); backdrop-filter: blur(4px); display: flex; align-items: center; justify-content: center; z-index: 9999; opacity: 0; visibility: hidden; transition: all 0.3s ease; }
        .modal-overlay.active { opacity: 1; visibility: visible; }
        .modal-box { background: #fff; width: 90%; max-width: 440px; padding: 28px; border-radius: 16px; box-shadow: 0 15px 35px rgba(0,0,0,0.25); text-align: center; }
        .modal-box h3 { margin-bottom: 12px; color: #2c3e50; }
        .input-box { width: 100%; padding: 10px; border: 1.5px solid #ddd; border-radius: 8px; margin: 12px 0; font-size: 14px; }

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
                    <h2>User Account Management</h2>
                    <p style="font-size: 13px; color: #666;">Inspect customer balances, status, and account permissions</p>
                </div>
            </div>
            <!-- Export Users CSV Link -->
            <a href="<?= htmlspecialchars($exportUrl) ?>" class="btn-action btn-export">
                <i class="fas fa-file-csv"></i> Export Users CSV
            </a>
        </div>

        <div class="filter-card">
            <form method="GET" action="users.php" class="filter-form">
                <input type="text" name="search" placeholder="Search by name, phone, or User ID..." value="<?= htmlspecialchars($searchQuery) ?>">
                <?php if ($hasStatusCol): ?>
                    <select name="status">
                        <option value="">All Account Statuses</option>
                        <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active Only</option>
                        <option value="suspended" <?= $statusFilter === 'suspended' ? 'selected' : '' ?>>Suspended Only</option>
                    </select>
                <?php endif; ?>
                <button type="submit" class="btn-action"><i class="fas fa-search"></i> Search</button>
                <a href="users.php" class="btn-action" style="background:#7f8c8d;">Reset</a>
            </form>
        </div>

        <div class="panel-section">
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Customer Name</th>
                            <th>Phone Number</th>
                            <th>Wallet Balance</th>
                            <th>Goals</th>
                            <th>Status</th>
                            <th>Registered Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($userList)): ?>
                            <?php foreach ($userList as $usr): ?>
                                <tr>
                                    <td>#<?= htmlspecialchars($usr['id']) ?></td>
                                    <td><strong><?= htmlspecialchars($usr['full_name']) ?></strong></td>
                                    <td><?= htmlspecialchars($usr['phone_number'] ?? 'N/A') ?></td>
                                    <td style="color: #27ae60; font-weight: bold;">TZS <?= number_format($usr['wallet_balance'], 2) ?></td>
                                    <td><?= $usr['total_goals'] ?> Goal(s)</td>
                                    <td><span class="badge <?= strtolower($usr['status'] ?? 'active') ?>"><?= htmlspecialchars($usr['status'] ?? 'active') ?></span></td>
                                    <td><?= date('d M Y', strtotime($usr['created_at'])) ?></td>
                                    <td>
                                        <div class="action-cell">
                                            <button class="btn-action" onclick="openBalanceModal(<?= $usr['id'] ?>, '<?= htmlspecialchars(addslashes($usr['full_name'])) ?>', <?= $usr['wallet_balance'] ?>)">
                                                <i class="fas fa-coins"></i> Balance
                                            </button>

                                            <?php if ($hasStatusCol): ?>
                                                <form method="POST" action="users.php" style="display:inline;">
                                                    <input type="hidden" name="action" value="toggle_status">
                                                    <input type="hidden" name="target_user_id" value="<?= $usr['id'] ?>">
                                                    <input type="hidden" name="new_status" value="<?= ($usr['status'] ?? 'active') === 'active' ? 'suspended' : 'active' ?>">
                                                    
                                                    <?php if (($usr['status'] ?? 'active') === 'active'): ?>
                                                        <button type="submit" class="btn-action btn-warning"><i class="fas fa-ban"></i> Suspend</button>
                                                    <?php else: ?>
                                                        <button type="submit" class="btn-action" style="background: #27ae60;"><i class="fas fa-check-circle"></i> Activate</button>
                                                    <?php endif; ?>
                                                </form>
                                            <?php endif; ?>

                                            <a href="manage_role.php?id=<?= $usr['id'] ?>" class="btn-action" style="background:#2980b9;">Role</a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="8" style="text-align: center;">No customer accounts matching your search criteria.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</div>

<!-- Balance Adjustment Modal -->
<div class="modal-overlay" id="balanceModal">
    <div class="modal-box">
        <h3>Adjust Wallet Balance</h3>
        <p>User: <strong id="adjUserName"></strong></p>
        <p>Current Balance: <span id="adjCurrBal" style="color: #27ae60; font-weight: bold;"></span></p>

        <form method="POST" action="users.php">
            <input type="hidden" name="action" value="adjust_balance">
            <input type="hidden" name="target_user_id" id="adjUserId">

            <select name="adjustment_type" class="input-box">
                <option value="add">Add Funds (+ TZS)</option>
                <option value="subtract">Deduct Funds (- TZS)</option>
            </select>

            <input type="number" name="adjustment_amount" class="input-box" step="100" min="100" placeholder="Enter amount (e.g. 5000)" required>

            <div style="display: flex; gap: 10px; margin-top: 15px;">
                <button type="submit" class="btn-action" style="flex: 1;">Confirm Adjustment</button>
                <button type="button" class="btn-action" style="background:#7f8c8d;" onclick="closeBalanceModal()">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Notification Modal -->
<div class="modal-overlay" id="notificationModal">
    <div class="modal-box">
        <h3 id="notifTitle">Notification</h3>
        <p id="notifMessage">Message details will appear here.</p>
        <button type="button" class="btn-action" onclick="document.getElementById('notificationModal').classList.remove('active')">OK</button>
    </div>
</div>

<script>
    function toggleSidebar() {
        const sidebar = document.getElementById('adminSidebar');
        const overlay = document.getElementById('sidebarOverlay');
        sidebar.classList.toggle('active');
        overlay.classList.toggle('active');
    }

    function openBalanceModal(userId, userName, currentBal) {
        document.getElementById('adjUserId').value = userId;
        document.getElementById('adjUserName').innerText = userName;
        document.getElementById('adjCurrBal').innerText = 'TZS ' + parseFloat(currentBal).toLocaleString();
        document.getElementById('balanceModal').classList.add('active');
    }

    function closeBalanceModal() {
        document.getElementById('balanceModal').classList.remove('active');
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