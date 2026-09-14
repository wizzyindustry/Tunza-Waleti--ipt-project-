<?php
// admin/transaction_officer/transactions.php

session_name('TUNZA_FINANCE_OFFICER_SESSION');
session_start();

require_once '../../database/auth.php';
require_once '../../database/config.php';

if (!isset($pdo) && isset($conn)) {
    $pdo = $conn;
}

// 1. Strict Role Authorization Guard (Transaction Officer Only)
if (!isset($_SESSION['finance_id']) || ($_SESSION['finance_role'] ?? '') !== 'transaction_officer') {
    header("Location: ../../login.php?role=transaction_officer");
    exit();
}

$officer_id   = $_SESSION['finance_id'];
$officer_name = $_SESSION['finance_name'] ?? 'Transaction Officer';

$error_msg   = $_SESSION['finance_error'] ?? '';
$success_msg = $_SESSION['finance_success'] ?? '';
unset($_SESSION['finance_error'], $_SESSION['finance_success']);

// ------------------------------------------------------------------
// ACTION HANDLER: PROCESS MANUAL BALANCE ADJUSTMENT
// ------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'adjust_balance') {
    $user_id         = filter_var($_POST['user_id'] ?? 0, FILTER_VALIDATE_INT);
    $adjustment_type = trim($_POST['adjustment_type'] ?? '');
    $amount          = filter_var($_POST['amount'] ?? 0, FILTER_VALIDATE_FLOAT);
    $notes           = trim($_POST['notes'] ?? '');

    if ($user_id && in_array($adjustment_type, ['credit', 'debit']) && $amount > 0 && !empty($notes)) {
        try {
            $pdo->beginTransaction();

            // Fetch User Wallet
            $stmtW = $pdo->prepare("SELECT id, balance FROM wallets WHERE user_id = :uid FOR UPDATE");
            $stmtW->execute(['uid' => $user_id]);
            $wallet = $stmtW->fetch(PDO::FETCH_ASSOC);

            if (!$wallet) {
                // Create wallet if absent
                $stmtInsW = $pdo->prepare("INSERT INTO wallets (user_id, balance, created_at, updated_at) VALUES (:uid, 0.00, NOW(), NOW())");
                $stmtInsW->execute(['uid' => $user_id]);
                $wallet_id = $pdo->lastInsertId();
                $current_balance = 0.00;
            } else {
                $wallet_id = $wallet['id'];
                $current_balance = (float)$wallet['balance'];
            }

            // Verify sufficient funds for debit adjustments
            if ($adjustment_type === 'debit' && $current_balance < $amount) {
                throw new Exception("Insufficient customer wallet balance for debit adjustment.");
            }

            $new_balance = ($adjustment_type === 'credit') ? ($current_balance + $amount) : ($current_balance - $amount);

            // Update Wallet Balance
            $stmtUpW = $pdo->prepare("UPDATE wallets SET balance = :bal, updated_at = NOW() WHERE id = :wid");
            $stmtUpW->execute(['bal' => $new_balance, 'wid' => $wallet_id]);

            // Record Adjustment Entry in Ledger
            $ref_no = 'ADJ' . date('YmdHis') . rand(100, 999);
            $trans_type = ($adjustment_type === 'credit') ? 'adjustment_credit' : 'adjustment_debit';

            $stmtT = $pdo->prepare("
                INSERT INTO transactions (reference_no, transaction_ref, user_id, type, amount, status, payment_method, description, created_at) 
                VALUES (:ref, :ref, :uid, :type, :amt, 'completed', 'Manual Financial Audit', :desc, NOW())
            ");
            $stmtT->execute([
                'ref'  => $ref_no,
                'uid'  => $user_id,
                'type' => $trans_type,
                'amt'  => $amount,
                'desc' => "Officer Adjustment (" . ucfirst($adjustment_type) . "): " . $notes
            ]);

            $pdo->commit();
            $_SESSION['finance_success'] = "Successfully applied " . strtoupper($adjustment_type) . " adjustment of TZS " . number_format($amount, 2) . ".";

        } catch (Exception $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $_SESSION['finance_error'] = "Adjustment Failed: " . $e->getMessage();
        }
    } else {
        $_SESSION['finance_error'] = "Please fill in all required fields accurately for the balance adjustment.";
    }

    header("Location: transactions.php");
    exit();
}

// ------------------------------------------------------------------
// FETCH TRANSACTIONS & ADJUSTMENT LEDGER WITH UNIQUE PDO PARAMETERS
// ------------------------------------------------------------------
$searchQuery  = trim($_GET['search'] ?? '');
$typeFilter   = trim($_GET['type'] ?? '');

$sql = "
    SELECT t.*, u.full_name, u.phone_number 
    FROM transactions t 
    JOIN users u ON t.user_id = u.id 
    WHERE 1=1
";
$params = [];

if (!empty($typeFilter)) {
    $sql .= " AND t.type = :type";
    $params['type'] = $typeFilter;
}

if (!empty($searchQuery)) {
    // Unique parameter bindings to prevent PDO HY093 errors
    $sql .= " AND (t.transaction_ref LIKE :s1 OR t.reference_no LIKE :s2 OR u.full_name LIKE :s3 OR u.phone_number LIKE :s4 OR t.description LIKE :s5)";
    $params['s1'] = "%{$searchQuery}%";
    $params['s2'] = "%{$searchQuery}%";
    $params['s3'] = "%{$searchQuery}%";
    $params['s4'] = "%{$searchQuery}%";
    $params['s5'] = "%{$searchQuery}%";
}

$sql .= " ORDER BY t.created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$transactions = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

// Fetch Customer List for Adjustment Selector
$usersList = [];
try {
    $stmtU = $pdo->query("SELECT id, full_name, phone_number FROM users WHERE role = 'user' ORDER BY full_name ASC");
    $usersList = $stmtU->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) {}

// Fetch System Logo
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
    <title>Transaction Ledger | Financial Operations</title>
    <link rel="shortcut icon" href="../../<?= htmlspecialchars($site_logo) ?>" type="image/x-icon">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap');
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Poppins', sans-serif; }
        body { height: 100vh; overflow: hidden; background-color: #f4f7f6; color: #2c3e50; }

        .admin-container { display: flex; height: 100vh; width: 100vw; overflow: hidden; position: relative; }
        
        /* Sidebar Navigation Styling */
        .sidebar { 
            width: 260px; 
            height: 100vh; 
            background: #002b49; 
            color: #fff; 
            padding: 25px 0; 
            flex-shrink: 0; 
            transition: all 0.3s ease; 
            z-index: 1000;
        }
        .sidebar-brand { text-align: center; padding-bottom: 25px; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .sidebar-menu { list-style: none; margin-top: 20px; }
        .sidebar-menu li a { display: flex; align-items: center; gap: 12px; padding: 14px 25px; color: #b0c4de; text-decoration: none; font-size: 14px; transition: 0.3s; }
        .sidebar-menu li a:hover, .sidebar-menu li.active a { background: #003d6b; color: #fff; border-left: 4px solid #27ae60; }

        .sidebar-overlay {
            display: none;
            position: fixed;
            top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 999;
            backdrop-filter: blur(2px);
        }
        .sidebar-overlay.active { display: block; }

        /* Main Scrollable Content Container */
        .main-content { 
            flex-grow: 1; 
            height: 100vh; 
            padding: 30px; 
            overflow-y: auto; 
            width: calc(100vw - 260px); 
        }

        .header-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; gap: 15px; flex-wrap: wrap; }
        .header-title-container { display: flex; align-items: center; gap: 15px; }

        .menu-toggle { 
            display: none; 
            background: #002b49; 
            color: #fff; 
            border: none; 
            font-size: 18px; 
            padding: 10px 14px; 
            border-radius: 8px; 
            cursor: pointer; 
            transition: 0.3s;
        }
        .menu-toggle:hover { background: #000; }

        /* Filters Bar */
        .filter-card { background: #fff; padding: 18px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); margin-bottom: 22px; display: flex; justify-content: space-between; gap: 15px; flex-wrap: wrap; }
        .filter-form { display: flex; gap: 12px; flex-wrap: wrap; flex-grow: 1; }
        .filter-form input, .filter-form select { padding: 10px 14px; border: 1.5px solid #ddd; border-radius: 8px; font-size: 14px; outline: none; transition: 0.3s; }
        .filter-form input:focus, .filter-form select:focus { border-color: #002b49; }

        /* Table & Responsive Container */
        .panel-section { background: #fff; padding: 25px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); }
        .table-responsive { width: 100%; overflow-x: auto; -webkit-overflow-scrolling: touch; }
        
        .data-table { width: 100%; border-collapse: collapse; font-size: 14px; text-align: left; min-width: 850px; }
        .data-table th { background: #f8f9fa; padding: 12px 15px; font-weight: 600; color: #555; border-bottom: 2px solid #eee; white-space: nowrap; }
        .data-table td { padding: 12px 15px; border-bottom: 1px solid #eee; vertical-align: middle; }

        .badge { padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; text-transform: uppercase; display: inline-block; }
        .badge.deposit, .badge.adjustment_credit, .badge.credit { background: #d4edda; color: #155724; }
        .badge.withdrawal, .badge.withdraw, .badge.adjustment_debit, .badge.debit { background: #f8d7da; color: #721c24; }
        .badge.savings { background: #e2e3e5; color: #383d41; }

        .btn-action { padding: 9px 16px; border-radius: 8px; background: #002b49; color: #fff; border: none; cursor: pointer; font-size: 13px; font-weight: 600; display: inline-flex; align-items: center; gap: 6px; white-space: nowrap; transition: 0.3s; text-decoration: none; }
        .btn-action:hover { background: #000; }
        .btn-adjust { background: #f39c12; }
        .btn-adjust:hover { background: #d35400; }

        /* Modal Overlays */
        .modal-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.55); backdrop-filter: blur(4px); display: flex; align-items: center; justify-content: center; z-index: 9999; opacity: 0; visibility: hidden; transition: all 0.3s ease; }
        .modal-overlay.active { opacity: 1; visibility: visible; }
        .modal-box { background: #fff; width: 90%; max-width: 480px; padding: 25px; border-radius: 16px; box-shadow: 0 15px 35px rgba(0,0,0,0.25); }

        .form-group { margin-bottom: 15px; text-align: left; }
        .form-group label { display: block; font-size: 13px; font-weight: 600; color: #444; margin-bottom: 5px; }
        .form-group input, .form-group select, .form-group textarea { width: 100%; padding: 10px 12px; border: 1.5px solid #ddd; border-radius: 8px; font-size: 14px; outline: none; transition: 0.3s; }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus { border-color: #002b49; }

        /* Mobile Breakpoints */
        @media (max-width: 768px) {
            .menu-toggle { display: inline-block; }
            .sidebar { position: fixed; top: 0; left: -260px; }
            .sidebar.active { left: 0; }
            .main-content { padding: 20px 15px; width: 100vw; }
            .filter-card { flex-direction: column; }
            .filter-form input, .filter-form select, .filter-form button, .btn-adjust { width: 100%; }
        }
    </style>
</head>
<body>

<div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

<div class="admin-container">
    <div class="sidebar" id="transSidebar">
        <div class="sidebar-brand">
            <img src="../../<?= htmlspecialchars($site_logo) ?>" alt="Logo" style="width: 48px; height: 48px; border-radius: 50%; object-fit: cover;">
            <h2 style="font-size: 18px; margin-top: 8px;">Finance Officer</h2>
        </div>
        <ul class="sidebar-menu">
            <li><a href="dashboard.php"><i class="fas fa-chart-line"></i> Finance Dashboard</a></li>
            <li><a href="escalations.php"><i class="fas fa-exclamation-triangle"></i> Financial Escalations</a></li>
            <li class="active"><a href="transactions.php"><i class="fas fa-exchange-alt"></i> Transaction Ledger</a></li>
            <li><a href="../../pages/logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
        </ul>
    </div>

    <div class="main-content">
        <div class="header-bar">
            <div class="header-title-container">
                <button class="menu-toggle" id="menuToggleBtn" onclick="toggleSidebar()" aria-label="Toggle Sidebar">
                    <i class="fas fa-bars"></i>
                </button>
                <div>
                    <h2>Transaction Ledger & Adjustments</h2>
                    <p style="font-size: 13px; color: #666;">Inspect system-wide ledger logs and execute balance adjustments</p>
                </div>
            </div>
        </div>

        <div class="filter-card">
            <form method="GET" action="transactions.php" class="filter-form">
                <input type="text" name="search" placeholder="Search by Ref #, Customer Name, Phone..." value="<?= htmlspecialchars($searchQuery) ?>" style="flex-grow:1;">
                <select name="type">
                    <option value="">All Transaction Types</option>
                    <option value="deposit" <?= $typeFilter === 'deposit' ? 'selected' : '' ?>>Deposits</option>
                    <option value="withdrawal" <?= $typeFilter === 'withdrawal' ? 'selected' : '' ?>>Withdrawals</option>
                    <option value="savings" <?= $typeFilter === 'savings' ? 'selected' : '' ?>>Savings Goal</option>
                    <option value="adjustment_credit" <?= $typeFilter === 'adjustment_credit' ? 'selected' : '' ?>>Adjustment (Credit)</option>
                    <option value="adjustment_debit" <?= $typeFilter === 'adjustment_debit' ? 'selected' : '' ?>>Adjustment (Debit)</option>
                </select>
                <button type="submit" class="btn-action"><i class="fas fa-search"></i> Filter</button>
            </form>
            <button type="button" class="btn-action btn-adjust" onclick="openAdjustmentModal()">
                <i class="fas fa-sliders-h"></i> Manual Balance Adjustment
            </button>
        </div>

        <div class="panel-section">
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Reference #</th>
                            <th>Customer</th>
                            <th>Type</th>
                            <th>Amount</th>
                            <th>Status</th>
                            <th>Description / Audit Notes</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($transactions)): ?>
                            <?php foreach ($transactions as $tx): ?>
                                <?php 
                                    $refNo = $tx['transaction_ref'] ?? ($tx['reference_no'] ?? 'N/A');
                                    $txType = strtolower($tx['type']);
                                    $isCredit = in_array($txType, ['deposit', 'credit', 'adjustment_credit']);
                                ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($refNo) ?></strong></td>
                                    <td>
                                        <strong><?= htmlspecialchars($tx['full_name']) ?></strong><br>
                                        <small style="color: #777;"><?= htmlspecialchars($tx['phone_number']) ?></small>
                                    </td>
                                    <td>
                                        <span class="badge <?= $txType ?>">
                                            <?= str_replace('_', ' ', $txType) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <strong style="color: <?= $isCredit ? '#27ae60' : '#c0392b' ?>; white-space: nowrap;">
                                            <?= $isCredit ? '+' : '-' ?> TZS <?= number_format($tx['amount'], 2) ?>
                                        </strong>
                                    </td>
                                    <td>
                                        <span style="text-transform: capitalize; font-weight: 600; color: #27ae60;">
                                            <?= htmlspecialchars($tx['status']) ?>
                                        </span>
                                    </td>
                                    <td style="max-width: 280px; font-size: 13px; color: #555;"><?= htmlspecialchars($tx['description'] ?? 'N/A') ?></td>
                                    <td style="white-space: nowrap;"><?= date('d M Y, H:i', strtotime($tx['created_at'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="7" style="text-align: center; color: #777; padding: 20px;">No transaction records found matching criteria.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Manual Balance Adjustment Modal -->
<div class="modal-overlay" id="adjustmentModal">
    <div class="modal-box">
        <h3><i class="fas fa-sliders-h" style="color: #f39c12;"></i> Manual Balance Adjustment</h3>
        <p style="font-size: 12px; color: #666; margin-bottom: 15px;">Perform manual credit or debit corrections directly on user wallets.</p>

        <form method="POST" action="transactions.php">
            <input type="hidden" name="action" value="adjust_balance">

            <div class="form-group">
                <label>Select Customer</label>
                <select name="user_id" required>
                    <option value="">-- Choose Customer --</option>
                    <?php foreach ($usersList as $u): ?>
                        <option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['full_name']) ?> (<?= htmlspecialchars($u['phone_number']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label>Adjustment Action</label>
                <select name="adjustment_type" required>
                    <option value="credit">Credit Balance (Add Money)</option>
                    <option value="debit">Debit Balance (Deduct Money)</option>
                </select>
            </div>

            <div class="form-group">
                <label>Amount (TZS)</label>
                <input type="number" step="0.01" min="1" name="amount" placeholder="e.g. 50000.00" required>
            </div>

            <div class="form-group">
                <label>Audit Reason / Notes</label>
                <textarea name="notes" rows="3" placeholder="Provide a reason for this manual adjustment (e.g., Ticket #TCK-10023 resolution)..." required></textarea>
            </div>

            <div style="display: flex; gap: 10px; margin-top: 20px;">
                <button type="submit" class="btn-action btn-adjust" style="flex: 1; justify-content: center;">Apply Adjustment</button>
                <button type="button" class="btn-action" style="background:#7f8c8d;" onclick="closeModal('adjustmentModal')">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Notification Modal -->
<div class="modal-overlay" id="notificationModal">
    <div class="modal-box" style="text-align: center;">
        <h3 id="notifTitle">Notification</h3>
        <p id="notifMessage" style="font-size: 14px; color: #555; margin: 15px 0;">Details...</p>
        <button type="button" class="btn-action" style="width: 100%; justify-content: center;" onclick="closeModal('notificationModal')">OK</button>
    </div>
</div>

<script>
    function toggleSidebar() {
        document.getElementById('transSidebar').classList.toggle('active');
        document.getElementById('sidebarOverlay').classList.toggle('active');
    }

    function openAdjustmentModal() {
        document.getElementById('adjustmentModal').classList.add('active');
    }

    function closeModal(id) {
        document.getElementById(id).classList.remove('active');
    }

    window.addEventListener('DOMContentLoaded', () => {
        <?php if (!empty($error_msg)): ?>
            document.getElementById('notifTitle').innerText = 'Action Failed';
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