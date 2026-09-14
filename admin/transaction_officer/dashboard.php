<?php
// admin/transaction_officer/dashboard.php

// 1. Initialize role-isolated session context to avoid collisions
session_name('TUNZA_FINANCE_OFFICER_SESSION');
session_start();

require_once '../../database/auth.php';
require_once '../../database/config.php';

// Normalize database connection handle
if (!isset($pdo) && isset($conn)) {
    $pdo = $conn;
}

// 2. Strict Role Authorization Guard
if (!isset($_SESSION['finance_id']) || ($_SESSION['finance_role'] ?? '') !== 'transaction_officer') {
    header("Location: login.php?role=transaction_officer");
    exit();
}

$officer_id   = $_SESSION['finance_id'];
$officer_name = $_SESSION['finance_name'] ?? 'Transaction Officer';

// 3. Fetch Branding Settings
$site_logo = 'assets/images/panta logo-07.jpg';
try {
    $stmtLogo = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'site_logo' LIMIT 1");
    if ($stmtLogo && $row = $stmtLogo->fetch(PDO::FETCH_ASSOC)) {
        if (!empty($row['setting_value'])) { 
            $site_logo = $row['setting_value']; 
        }
    }
} catch (PDOException $e) {
    // Fallback silently if settings table read fails
}

// 4. Calculate Financial Metrics & Recent Audit Logs
$total_deposits    = 0.00;
$total_withdrawals = 0.00;
$pending_count     = 0;
$escalated_count   = 0;
$recentTxns        = [];

try {
    // Total Successful Deposits
    $stmtDep = $pdo->query("SELECT COALESCE(SUM(amount), 0.00) FROM transactions WHERE type = 'deposit' AND status IN ('completed', 'success', 'successful')");
    $total_deposits = (float)$stmtDep->fetchColumn();

    // Total Successful Withdrawals
    $stmtWth = $pdo->query("SELECT COALESCE(SUM(amount), 0.00) FROM transactions WHERE type IN ('withdraw', 'payout') AND status IN ('completed', 'success', 'successful')");
    $total_withdrawals = (float)$stmtWth->fetchColumn();

    // Pending Gateway Transactions Queue
    $stmtPen = $pdo->query("SELECT COUNT(*) FROM transactions WHERE status IN ('pending', 'processing')");
    $pending_count = (int)$stmtPen->fetchColumn();

    // Financial Support Tickets Escalated by Customer Care
    $stmtEsc = $pdo->query("SELECT COUNT(*) FROM support_tickets WHERE status = 'escalated_to_finance'");
    $escalated_count = (int)$stmtEsc->fetchColumn();

    // Fetch Recent 5 Ledger Entries
    $stmtRecent = $pdo->prepare("
        SELECT t.*, u.full_name, u.phone_number 
        FROM transactions t 
        JOIN users u ON t.user_id = u.id 
        ORDER BY t.created_at DESC 
        LIMIT 5
    ");
    $stmtRecent->execute();
    $recentTxns = $stmtRecent->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) {
    // Fail safe with default values
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transaction Officer Dashboard | Tunza Waleti</title>
    <link rel="shortcut icon" href="../../<?= htmlspecialchars($site_logo) ?>" type="image/x-icon">
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
        .sidebar-menu li a:hover, .sidebar-menu li.active a { background: #510049; color: #fff; border-left: 4px solid #27ae60; }

        .main-content { flex-grow: 1; height: 100vh; padding: 30px; overflow-y: auto; }
        
        .metrics-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: #fff; padding: 22px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); border-left: 5px solid #27ae60; }
        .stat-card h4 { font-size: 13px; color: #666; text-transform: uppercase; margin-bottom: 8px; }
        .stat-card .value { font-size: 24px; font-weight: 700; color: #2c3e50; }

        .panel-section { background: #fff; padding: 25px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); }
        .data-table { width: 100%; border-collapse: collapse; font-size: 14px; text-align: left; }
        .data-table th { background: #f8f9fa; padding: 12px 15px; font-weight: 600; color: #555; border-bottom: 2px solid #eee; }
        .data-table td { padding: 12px 15px; border-bottom: 1px solid #eee; }

        .badge { padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; text-transform: uppercase; }
        .badge.completed, .badge.success, .badge.successful { background: #d4edda; color: #155724; }
        .badge.pending, .badge.processing { background: #fff3cd; color: #856404; }
        .badge.failed, .badge.rejected { background: #f8d7da; color: #721c24; }

        .btn-action { padding: 8px 16px; border-radius: 6px; background: #510049; color: #fff; text-decoration: none; font-size: 13px; font-weight: 600; display: inline-flex; align-items: center; gap: 8px; border: none; cursor: pointer; transition: 0.3s; }
        .btn-action:hover { background: #000; }
    </style>
</head>
<body>

<div class="admin-container">
    <div class="sidebar">
        <div class="sidebar-brand">
            <img src="../../<?= htmlspecialchars($site_logo) ?>" alt="Tunza Waleti Logo" style="width: 48px; height: 48px; border-radius: 50%; object-fit: cover;">
            <h2 style="font-size: 18px; margin-top: 8px;">Finance Officer</h2>
        </div>
        <ul class="sidebar-menu">
            <li class="active"><a href="dashboard.php"><i class="fas fa-chart-line"></i> Finance Dashboard</a></li>
            <li><a href="escalations.php"><i class="fas fa-exclamation-triangle"></i> Financial Escalations</a></li>
            <li><a href="transactions.php"><i class="fas fa-exchange-alt"></i> Transaction Ledger</a></li>
            <li><a href="../../pages/logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
        </ul>
    </div>

    <div class="main-content">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px;">
            <div>
                <h2>Financial Operations, <?= htmlspecialchars($officer_name) ?></h2>
                <p style="font-size: 13px; color: #666;">Ledger audits, manual adjustments, and escalated payment disputes</p>
            </div>
            <a href="escalations.php" class="btn-action" style="background:#2980b9;">
                <i class="fas fa-tasks"></i> Review Escalations (<?= $escalated_count ?>)
            </a>
        </div>

        <div class="metrics-grid">
            <div class="stat-card" style="border-color: #27ae60;">
                <h4>Total Completed Deposits</h4>
                <div class="value">TZS <?= number_format($total_deposits, 2) ?></div>
            </div>
            <div class="stat-card" style="border-color: #e74c3c;">
                <h4>Total Withdrawals</h4>
                <div class="value">TZS <?= number_format($total_withdrawals, 2) ?></div>
            </div>
            <div class="stat-card" style="border-color: #f39c12;">
                <h4>Pending Gateways</h4>
                <div class="value"><?= $pending_count ?></div>
            </div>
            <div class="stat-card" style="border-color: #2980b9;">
                <h4>Finance Escalations</h4>
                <div class="value"><?= $escalated_count ?></div>
            </div>
        </div>

        <div class="panel-section">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 18px;">
                <h3>Recent System Transactions</h3>
                <a href="transactions.php" class="btn-action"><i class="fas fa-list"></i> Full Ledger</a>
            </div>

            <table class="data-table">
                <thead>
                    <tr>
                        <th>Reference #</th>
                        <th>User</th>
                        <th>Type</th>
                        <th>Amount</th>
                        <th>Method</th>
                        <th>Status</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($recentTxns)): ?>
                        <?php foreach ($recentTxns as $t): ?>
                            <tr>
                                <td><strong>#<?= htmlspecialchars($t['reference_no']) ?></strong></td>
                                <td><?= htmlspecialchars($t['full_name']) ?><br><small style="color:#777;"><?= htmlspecialchars($t['phone_number']) ?></small></td>
                                <td><span style="text-transform: uppercase; font-weight: bold; font-size: 12px;"><?= htmlspecialchars($t['type']) ?></span></td>
                                <td style="font-weight: bold; color: <?= in_array(strtolower($t['type']), ['deposit', 'credit']) ? '#27ae60' : '#c0392b' ?>;">
                                    <?= in_array(strtolower($t['type']), ['deposit', 'credit']) ? '+' : '-' ?> TZS <?= number_format($t['amount'], 2) ?>
                                </td>
                                <td><?= htmlspecialchars($t['payment_method'] ?? 'N/A') ?></td>
                                <td>
                                    <span class="badge <?= strtolower($t['status']) ?>"><?= htmlspecialchars($t['status']) ?></span>
                                </td>
                                <td><?= date('d M Y, H:i', strtotime($t['created_at'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="7" style="text-align: center; color: #777;">No transaction ledger entries found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Cross-Tab Session Guard -->
<script>
    window.addEventListener('storage', function(event) {
        if (event.key === 'tunza_session_update') {
            const sessionData = JSON.parse(event.newValue);
            if (sessionData && sessionData.role !== 'transaction_officer') {
                window.location.reload();
            }
        }
    });
</script>

</body>
</html>