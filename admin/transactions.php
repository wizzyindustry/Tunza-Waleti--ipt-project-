<?php
// admin/transactions.php

// 1. Set isolated administrator session name BEFORE starting session to prevent cross-tab collision
session_name('TUNZA_MAIN_ADMIN_SESSION');
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

// Ensure logged-in user is an Administrator
if (!isset($_SESSION['admin_id']) || ($_SESSION['admin_role'] ?? '') !== 'admin') {
    header("Location: ../login.php?role=admin");
    exit();
}

$current_admin_id = $_SESSION['admin_id'];
$user_name        = $_SESSION['admin_name'] ?? 'Administrator';

// ------------------------------------------------------------------
// SEARCH & FILTER PARAMETERS
// ------------------------------------------------------------------
$searchQuery  = trim($_GET['search'] ?? '');
$typeFilter   = strtolower(trim($_GET['type'] ?? ''));
$statusFilter = strtolower(trim($_GET['status'] ?? ''));
$fromDate     = trim($_GET['from_date'] ?? '');
$toDate       = trim($_GET['to_date'] ?? '');
$exportFormat = strtolower(trim($_GET['export'] ?? ''));

$sql = "
    SELECT t.*, u.full_name, u.phone_number 
    FROM transactions t 
    JOIN users u ON t.user_id = u.id 
    WHERE 1=1
";

$params = [];

if (!empty($searchQuery)) {
    $sql .= " AND (t.reference_no LIKE :search OR u.full_name LIKE :search OR u.phone_number LIKE :search OR CAST(t.user_id AS CHAR) = :exact_search)";
    $params['search'] = "%{$searchQuery}%";
    $params['exact_search'] = $searchQuery;
}

// Support all variations of withdrawal keywords (withdraw, withdrawal, payout)
if (!empty($typeFilter)) {
    if ($typeFilter === 'deposit') {
        $sql .= " AND LOWER(t.type) = 'deposit'";
    } elseif (in_array($typeFilter, ['withdraw', 'withdrawal', 'payout'])) {
        $sql .= " AND LOWER(t.type) IN ('withdraw', 'withdrawal', 'payout')";
    } elseif ($typeFilter === 'transfer') {
        $sql .= " AND LOWER(t.type) = 'transfer'";
    }
}

// Case-insensitive status filtering
if (!empty($statusFilter)) {
    if ($statusFilter === 'completed') {
        $sql .= " AND LOWER(t.status) IN ('completed', 'success', 'successful')";
    } elseif (in_array($statusFilter, ['pending', 'processing'])) {
        $sql .= " AND LOWER(t.status) IN ('pending', 'processing')";
    } elseif (in_array($statusFilter, ['failed', 'rejected'])) {
        $sql .= " AND LOWER(t.status) IN ('failed', 'rejected')";
    }
}

if (!empty($fromDate)) {
    $sql .= " AND DATE(t.created_at) >= :from_date";
    $params['from_date'] = $fromDate;
}

if (!empty($toDate)) {
    $sql .= " AND DATE(t.created_at) <= :to_date";
    $params['to_date'] = $toDate;
}

$sql .= " ORDER BY t.created_at DESC";

// If NOT exporting to CSV, limit browser table results to 150 rows
if ($exportFormat !== 'csv') {
    $sql .= " LIMIT 150";
}

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // ------------------------------------------------------------------
    // CSV EXPORT HANDLER
    // ------------------------------------------------------------------
    if ($exportFormat === 'csv') {
        $fileName = 'transactions_ledger_' . date('Y-m-d_H-i-s') . '.csv';

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $fileName . '"');

        $output = fopen('php://output', 'w');

        // Write CSV Header Row
        fputcsv($output, [
            'Reference No', 
            'User Full Name', 
            'Phone Number', 
            'Transaction Type', 
            'Amount (TZS)', 
            'Payment Method', 
            'Status', 
            'Transaction Date'
        ]);

        // Write Transaction Rows
        foreach ($transactions as $txn) {
            $rawType    = strtolower($txn['type']);
            $isWithdraw = in_array($rawType, ['withdraw', 'withdrawal', 'payout']);
            $amountVal  = ($isWithdraw ? '-' : '+') . number_format($txn['amount'], 2, '.', '');

            fputcsv($output, [
                $txn['reference_no'],
                $txn['full_name'],
                $txn['phone_number'] ?? 'N/A',
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

    // Aggregate summary numbers for the top metric cards
    $totalDepositsSum = 0;
    $totalWithdrawsSum = 0;

    foreach ($transactions as $row) {
        $rType   = strtolower($row['type']);
        $rStatus = strtolower($row['status']);
        $isSuccess = in_array($rStatus, ['completed', 'success', 'successful']);

        if ($isSuccess) {
            if ($rType === 'deposit') {
                $totalDepositsSum += (float)$row['amount'];
            } elseif (in_array($rType, ['withdraw', 'withdrawal', 'payout'])) {
                $totalWithdrawsSum += (float)$row['amount'];
            }
        }
    }

} catch (PDOException $e) {
    $transactions = [];
    $error_msg = "Database Query Error: " . $e->getMessage();
}

// Build URL query string for Export link while retaining active filters
$exportQueryParams = $_GET;
$exportQueryParams['export'] = 'csv';
$exportUrl = 'transactions.php?' . http_build_query($exportQueryParams);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transaction Audit Ledger | Tunza Admin</title>
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

        /* Summary Stat Row */
        .summary-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 20px; margin-bottom: 25px; }
        .summary-card { background: #fff; padding: 18px 20px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); display: flex; align-items: center; gap: 15px; }
        .summary-icon { width: 46px; height: 46px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 20px; flex-shrink: 0; }
        .summary-icon.deposit { background: #e8f8f0; color: #27ae60; }
        .summary-icon.withdraw { background: #fdedec; color: #e74c3c; }
        .summary-icon.count { background: #eef2fe; color: #3498db; }
        .summary-info h4 { font-size: 16px; font-weight: 700; color: #1a252f; }
        .summary-info p { font-size: 12px; color: #7f8c8d; }

        /* Filter Controls */
        .filter-card { background: #fff; padding: 20px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); margin-bottom: 25px; }
        .filter-form { display: flex; gap: 12px; flex-wrap: wrap; align-items: center; }
        .filter-form input, .filter-form select { padding: 9px 12px; border: 1.5px solid #ddd; border-radius: 8px; font-size: 13px; outline: none; }
        .filter-form input[type="text"] { flex-grow: 1; min-width: 200px; }

        .panel-section { background: #fff; padding: 25px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); margin-bottom: 30px; }
        .table-responsive { width: 100%; overflow-x: auto; }

        .data-table { width: 100%; border-collapse: collapse; font-size: 14px; text-align: left; }
        .data-table th { background: #f8f9fa; padding: 12px 15px; font-weight: 600; color: #555; border-bottom: 2px solid #eee; white-space: nowrap; }
        .data-table td { padding: 12px 15px; border-bottom: 1px solid #eee; white-space: nowrap; }

        .badge { padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; text-transform: uppercase; }
        .badge.completed, .badge.success, .badge.successful { background: #d4edda; color: #155724; }
        .badge.processing, .badge.pending { background: #fff3cd; color: #856404; }
        .badge.failed, .badge.rejected { background: #f8d7da; color: #721c24; }

        .badge-type { padding: 3px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; text-transform: uppercase; display: inline-flex; align-items: center; gap: 4px; }
        .badge-type.deposit { background: #e8f8f0; color: #27ae60; }
        .badge-type.withdraw { background: #fdedec; color: #e74c3c; }

        .btn-action { padding: 8px 16px; border: none; border-radius: 6px; background: #510049; color: #fff; cursor: pointer; font-size: 13px; font-weight: 600; transition: 0.3s; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; }
        .btn-action:hover { background: #000; }
        .btn-export { background: #27ae60; }
        .btn-export:hover { background: #1e8449; }

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
            <li><a href="users.php"><i class="fas fa-users-cog"></i> Manage Users</a></li>
            <li class="active"><a href="transactions.php"><i class="fas fa-exchange-alt"></i> Transactions</a></li>
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
                    <h2>System Transaction Audit Ledger</h2>
                    <p style="font-size: 13px; color: #666;">View and audit all deposit, withdrawal, and transfer records</p>
                </div>
            </div>
            <!-- Export Transactions to CSV File -->
            <a href="<?= htmlspecialchars($exportUrl) ?>" class="btn-action btn-export">
                <i class="fas fa-file-csv"></i> Export CSV Ledger
            </a>
        </div>

        <!-- Summary Stat Row -->
        <div class="summary-grid">
            <div class="summary-card">
                <div class="summary-icon count"><i class="fas fa-list-check"></i></div>
                <div class="summary-info">
                    <h4><?= count($transactions) ?> Records</h4>
                    <p>Transactions Listed</p>
                </div>
            </div>
            <div class="summary-card">
                <div class="summary-icon deposit"><i class="fas fa-arrow-down"></i></div>
                <div class="summary-info">
                    <h4>TZS <?= number_format($totalDepositsSum, 2) ?></h4>
                    <p>Filtered Deposits Sum</p>
                </div>
            </div>
            <div class="summary-card">
                <div class="summary-icon withdraw"><i class="fas fa-arrow-up"></i></div>
                <div class="summary-info">
                    <h4>TZS <?= number_format($totalWithdrawsSum, 2) ?></h4>
                    <p>Filtered Withdrawals Sum</p>
                </div>
            </div>
        </div>

        <!-- Filter Controls -->
        <div class="filter-card">
            <form method="GET" action="transactions.php" class="filter-form">
                <input type="text" name="search" placeholder="Reference, User Name, or Phone..." value="<?= htmlspecialchars($searchQuery) ?>">
                
                <select name="type">
                    <option value="">All Types</option>
                    <option value="deposit" <?= $typeFilter === 'deposit' ? 'selected' : '' ?>>Deposits</option>
                    <option value="withdraw" <?= in_array($typeFilter, ['withdraw', 'withdrawal', 'payout']) ? 'selected' : '' ?>>Withdrawals</option>
                </select>

                <select name="status">
                    <option value="">All Statuses</option>
                    <option value="completed" <?= $statusFilter === 'completed' ? 'selected' : '' ?>>Completed</option>
                    <option value="pending" <?= in_array($statusFilter, ['pending', 'processing']) ? 'selected' : '' ?>>Pending / Processing</option>
                    <option value="failed" <?= in_array($statusFilter, ['failed', 'rejected']) ? 'selected' : '' ?>>Failed</option>
                </select>

                <input type="date" name="from_date" value="<?= htmlspecialchars($fromDate) ?>">
                <input type="date" name="to_date" value="<?= htmlspecialchars($toDate) ?>">

                <button type="submit" class="btn-action"><i class="fas fa-filter"></i> Filter</button>
                <a href="transactions.php" class="btn-action" style="background:#7f8c8d;">Reset</a>
            </form>
        </div>

        <!-- Transactions Data Table -->
        <div class="panel-section">
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Reference</th>
                            <th>User</th>
                            <th>Phone</th>
                            <th>Type</th>
                            <th>Amount (TZS)</th>
                            <th>Method</th>
                            <th>Status</th>
                            <th>Date & Time</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($transactions)): ?>
                            <?php foreach ($transactions as $txn): 
                                $rawType    = strtolower($txn['type']);
                                $isWithdraw = in_array($rawType, ['withdraw', 'withdrawal', 'payout']);
                            ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($txn['reference_no']) ?></strong></td>
                                    <td><?= htmlspecialchars($txn['full_name']) ?></td>
                                    <td><?= htmlspecialchars($txn['phone_number'] ?? 'N/A') ?></td>
                                    <td>
                                        <span class="badge-type <?= $isWithdraw ? 'withdraw' : 'deposit' ?>">
                                            <i class="fas fa-arrow-<?= $isWithdraw ? 'up' : 'down' ?>"></i>
                                            <?= strtoupper($txn['type']) ?>
                                        </span>
                                    </td>
                                    <td style="font-weight: 700; color: <?= $isWithdraw ? '#e74c3c' : '#27ae60' ?>;">
                                        <?= $isWithdraw ? '-' : '+' ?> TZS <?= number_format($txn['amount'], 2) ?>
                                    </td>
                                    <td><?= htmlspecialchars($txn['payment_method']) ?></td>
                                    <td><span class="badge <?= strtolower($txn['status']) ?>"><?= htmlspecialchars($txn['status']) ?></span></td>
                                    <td><?= date('d M Y, H:i:s', strtotime($txn['created_at'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="8" style="text-align: center; padding: 20px;">No transaction records match your filters.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
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