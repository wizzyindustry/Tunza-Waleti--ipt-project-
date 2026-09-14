<?php
// admin/transaction_officer/escalations.php

session_name('TUNZA_FINANCE_OFFICER_SESSION');
session_start();

require_once '../../database/auth.php';
require_once '../../database/config.php';

if (file_exists('../../helpers/SmsService.php')) {
    require_once '../../helpers/SmsService.php';
}

if (!isset($pdo) && isset($conn)) {
    $pdo = $conn;
}

// Access Control Guard
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
// ACTION HANDLER: RESOLVE DISPUTE & ADJUST BALANCE
// ------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action    = $_POST['action'];
    $ticket_id = filter_var($_POST['ticket_id'] ?? 0, FILTER_VALIDATE_INT);
    $target_id = filter_var($_POST['target_user_id'] ?? 0, FILTER_VALIDATE_INT);

    if ($ticket_id && $target_id) {
        try {
            // A. ADJUST WALLET BALANCE AND RESOLVE TICKET
            if ($action === 'resolve_and_adjust') {
                $adj_amount = filter_var($_POST['adjustment_amount'] ?? 0, FILTER_VALIDATE_FLOAT);
                $adj_type   = $_POST['adjustment_type'] ?? 'add';
                $fin_notes  = trim($_POST['finance_notes'] ?? '');

                if ($adj_amount > 0) {
                    $pdo->beginTransaction();

                    if ($adj_type === 'add') {
                        $updateWallet = $pdo->prepare("UPDATE wallets SET balance = balance + :amt, updated_at = NOW() WHERE user_id = :id");
                        $txnType      = 'adjustment_credit';
                    } else {
                        $updateWallet = $pdo->prepare("UPDATE wallets SET balance = GREATEST(0, balance - :amt), updated_at = NOW() WHERE user_id = :id");
                        $txnType      = 'adjustment_debit';
                    }
                    $updateWallet->execute(['amt' => $adj_amount, 'id' => $target_id]);

                    // Insert audit transaction log
                    $orderRef = 'FIN' . date('YmdHis') . rand(10, 99);
                    $insertTxn = $pdo->prepare("
                        INSERT INTO transactions (reference_no, transaction_ref, user_id, type, amount, status, payment_method, description, created_at) 
                        VALUES (:ref, :ref, :user_id, :type, :amt, 'completed', 'Finance Audit Override', :desc, NOW())
                    ");
                    $insertTxn->execute([
                        'ref'     => $orderRef,
                        'user_id' => $target_id,
                        'type'    => $txnType,
                        'amt'     => $adj_amount,
                        'desc'    => "Escalation Audit: " . $fin_notes
                    ]);

                    // Update ticket status to resolved
                    $updateTicket = $pdo->prepare("
                        UPDATE support_tickets 
                        SET status = 'resolved', user_reply = :reply, updated_at = NOW() 
                        WHERE id = :id
                    ");
                    $replyMsg = "Financial audit completed. Balance adjusted by TZS " . number_format($adj_amount, 2) . ". Ref: {$orderRef}";
                    $updateTicket->execute(['reply' => $replyMsg, 'id' => $ticket_id]);

                    // Fetch updated wallet balance
                    $stmtBal = $pdo->prepare("SELECT balance FROM wallets WHERE user_id = :id LIMIT 1");
                    $stmtBal->execute(['id' => $target_id]);
                    $newBalance = (float)($stmtBal->fetchColumn() ?: 0.00);

                    $pdo->commit();

                    $_SESSION['finance_success'] = "Financial dispute resolved. User wallet updated by TZS " . number_format($adj_amount, 2);

                    // Dispatch SMS Notice
                    $stmtPhone = $pdo->prepare("SELECT phone_number, full_name FROM users WHERE id = :id LIMIT 1");
                    $stmtPhone->execute(['id' => $target_id]);
                    $usrData = $stmtPhone->fetch(PDO::FETCH_ASSOC);

                    if ($usrData && class_exists('SmsService')) {
                        $uName = $usrData['full_name'];
                        $formattedBal = number_format($newBalance, 2);
                        $formattedAdj = number_format($adj_amount, 2);

                        $smsText = "Habari {$uName}, ombi lako la kifedha limeshughulikiwa. Akaunti yako imerekebishwa kwa TZS {$formattedAdj}. Salio jipya: TZS {$formattedBal}.";
                        SmsService::sendSMS($pdo, $usrData['phone_number'], $smsText);
                    }
                } else {
                    $_SESSION['finance_error'] = "Invalid adjustment amount.";
                }
            }

            // B. REJECT/DISMISS ESCALATION
            elseif ($action === 'dismiss_escalation') {
                $fin_notes = trim($_POST['finance_notes'] ?? '');
                $stmt = $pdo->prepare("
                    UPDATE support_tickets 
                    SET status = 'resolved', user_reply = :reply, updated_at = NOW() 
                    WHERE id = :id
                ");
                $replyMsg = "Financial audit completed: " . $fin_notes;
                $stmt->execute(['reply' => $replyMsg, 'id' => $ticket_id]);

                $_SESSION['finance_success'] = "Financial escalation reviewed and resolved without balance changes.";
            }

        } catch (PDOException $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $_SESSION['finance_error'] = "Action failed: " . $e->getMessage();
        }
    }
    header("Location: escalations.php");
    exit();
}

// Fetch Escalated Support Tickets
$sql = "
    SELECT st.*, u.full_name, u.phone_number, COALESCE(w.balance, 0.00) AS current_balance 
    FROM support_tickets st 
    JOIN users u ON st.user_id = u.id 
    LEFT JOIN wallets w ON u.id = w.user_id 
    WHERE st.status = 'escalated_to_finance' 
    ORDER BY st.updated_at DESC
";
$stmt = $pdo->query($sql);
$escalations = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

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
    <title>Financial Escalations | Transaction Officer</title>
    <link rel="shortcut icon" href="../../<?= htmlspecialchars($site_logo) ?>" type="image/x-icon">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap');
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Poppins', sans-serif; }
        body { height: 100vh; overflow: hidden; background-color: #f4f7f6; color: #2c3e50; }

        .admin-container { display: flex; height: 100vh; width: 100vw; overflow: hidden; position: relative; }
        
        /* Sidebar Styling */
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

        /* Main Scrollable Content Area */
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

        .panel-section { background: #fff; padding: 25px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); }
        .table-responsive { width: 100%; overflow-x: auto; -webkit-overflow-scrolling: touch; }
        
        .data-table { width: 100%; border-collapse: collapse; font-size: 14px; text-align: left; min-width: 800px; }
        .data-table th { background: #f8f9fa; padding: 12px 15px; font-weight: 600; color: #555; border-bottom: 2px solid #eee; white-space: nowrap; }
        .data-table td { padding: 12px 15px; border-bottom: 1px solid #eee; vertical-align: middle; }

        .btn-action { padding: 8px 14px; border-radius: 6px; background: #27ae60; color: #fff; border: none; cursor: pointer; font-size: 12px; font-weight: 600; display: inline-flex; align-items: center; gap: 6px; white-space: nowrap; transition: 0.3s; }
        .btn-action:hover { background: #1e8449; }

        .notes-box { background: #fff3cd; color: #856404; padding: 8px 12px; border-radius: 6px; font-size: 12px; margin-top: 6px; }

        /* Modal Styling */
        .modal-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.55); backdrop-filter: blur(4px); display: flex; align-items: center; justify-content: center; z-index: 9999; opacity: 0; visibility: hidden; transition: all 0.3s ease; }
        .modal-overlay.active { opacity: 1; visibility: visible; }
        .modal-box { background: #fff; width: 90%; max-width: 480px; padding: 25px; border-radius: 16px; box-shadow: 0 15px 35px rgba(0,0,0,0.25); }
        .input-box { width: 100%; padding: 10px 12px; border: 1.5px solid #ddd; border-radius: 8px; margin: 10px 0; font-size: 14px; outline: none; transition: 0.3s; }
        .input-box:focus { border-color: #002b49; }

        /* Responsive Breakpoints */
        @media (max-width: 768px) {
            .menu-toggle { display: inline-block; }
            .sidebar { position: fixed; top: 0; left: -260px; }
            .sidebar.active { left: 0; }
            .main-content { padding: 20px 15px; width: 100vw; }
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
            <li class="active"><a href="escalations.php"><i class="fas fa-exclamation-triangle"></i> Financial Escalations</a></li>
            <li><a href="transactions.php"><i class="fas fa-exchange-alt"></i> Transaction Ledger</a></li>
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
                    <h2>Financial Escalation Audit Queue</h2>
                    <p style="font-size: 13px; color: #666;">Review issues forwarded by Customer Care requiring ledger adjustments</p>
                </div>
            </div>
        </div>

        <div class="panel-section">
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Ticket #</th>
                            <th>Customer</th>
                            <th>Current Balance</th>
                            <th>Care Officer Notes</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($escalations)): ?>
                            <?php foreach ($escalations as $esc): ?>
                                <tr>
                                    <td><strong>#<?= htmlspecialchars($esc['ticket_no']) ?></strong></td>
                                    <td>
                                        <strong><?= htmlspecialchars($esc['full_name']) ?></strong><br>
                                        <small style="color: #777;"><?= htmlspecialchars($esc['phone_number']) ?></small>
                                    </td>
                                    <td style="color: #27ae60; font-weight: bold; white-space: nowrap;">TZS <?= number_format($esc['current_balance'], 2) ?></td>
                                    <td>
                                        <strong><?= htmlspecialchars($esc['subject']) ?></strong>
                                        <div class="notes-box">
                                            <i class="fas fa-info-circle"></i> Care Note: <?= htmlspecialchars($esc['admin_notes'] ?? 'No notes added') ?>
                                        </div>
                                    </td>
                                    <td>
                                        <button class="btn-action" onclick="openAuditModal(<?= $esc['id'] ?>, <?= $esc['user_id'] ?>, '<?= htmlspecialchars(addslashes($esc['full_name'])) ?>', '<?= htmlspecialchars(addslashes($esc['ticket_no'])) ?>')">
                                            <i class="fas fa-coins"></i> Reconcile & Audit
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="5" style="text-align: center; color: #777; padding: 20px;">No pending financial escalations in queue.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Financial Audit Modal -->
<div class="modal-overlay" id="auditModal">
    <div class="modal-box">
        <h3><i class="fas fa-coins" style="color: #27ae60;"></i> Reconcile Financial Dispute</h3>
        <p style="font-size: 13px; color: #666; margin-top: 4px;">Ticket: <strong id="audTicketNo"></strong> | Customer: <strong id="audUserName"></strong></p>

        <form method="POST" action="escalations.php">
            <input type="hidden" name="action" value="resolve_and_adjust">
            <input type="hidden" name="ticket_id" id="audTicketId">
            <input type="hidden" name="target_user_id" id="audUserId">

            <select name="adjustment_type" class="input-box">
                <option value="add">Credit Funds (+ TZS)</option>
                <option value="subtract">Deduct Funds (- TZS)</option>
            </select>

            <input type="number" name="adjustment_amount" class="input-box" step="100" min="100" placeholder="Adjustment amount (e.g. 10000)" required>
            <textarea name="finance_notes" class="input-box" rows="3" placeholder="Audit resolution notes for ledger entry..." required></textarea>

            <div style="display: flex; gap: 10px; margin-top: 10px;">
                <button type="submit" class="btn-action" style="flex: 1; justify-content: center;">Confirm & Resolve</button>
                <button type="button" class="btn-action" style="background:#7f8c8d;" onclick="closeModal('auditModal')">Cancel</button>
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

    function openAuditModal(ticketId, userId, userName, ticketNo) {
        document.getElementById('audTicketId').value = ticketId;
        document.getElementById('audUserId').value = userId;
        document.getElementById('audTicketNo').innerText = '#' + ticketNo;
        document.getElementById('audUserName').innerText = userName;
        document.getElementById('auditModal').classList.add('active');
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