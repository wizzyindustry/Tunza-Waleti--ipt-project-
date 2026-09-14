<?php
// admin/customer_care/tickets.php

session_name('TUNZA_CARE_OFFICER_SESSION');
session_start();

require_once '../../database/auth.php';
require_once '../../database/config.php';

if (file_exists('../../helpers/SmsService.php')) {
    require_once '../../helpers/SmsService.php';
}

if (!isset($pdo) && isset($conn)) {
    $pdo = $conn;
}

// Check Customer Care Authorization
if (!isset($_SESSION['care_id']) || ($_SESSION['care_role'] ?? '') !== 'customer_care') {
    header("Location: ../../login.php?role=customer_care");
    exit();
}

$officer_id   = $_SESSION['care_id'];
$officer_name = $_SESSION['care_name'] ?? 'Customer Care Officer';

$error_msg   = $_SESSION['care_error'] ?? '';
$success_msg = $_SESSION['care_success'] ?? '';
unset($_SESSION['care_error'], $_SESSION['care_success']);

// ------------------------------------------------------------------
// ACTION HANDLER: REPLY, ESCALATE & RESOLVE TICKETS
// ------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action    = $_POST['action'];
    $ticket_id = filter_var($_POST['ticket_id'] ?? 0, FILTER_VALIDATE_INT);

    if ($ticket_id) {
        try {
            // A. SEND INSTRUCTIONS REPLY TO USER
            if ($action === 'reply_user') {
                $user_reply = trim($_POST['user_reply'] ?? '');
                $send_sms   = isset($_POST['send_sms_notice']);

                if (!empty($user_reply)) {
                    $stmt = $pdo->prepare("
                        UPDATE support_tickets 
                        SET user_reply = :reply, assigned_to = :assigned, updated_at = NOW() 
                        WHERE id = :id
                    ");
                    $stmt->execute(['reply' => $user_reply, 'assigned' => $officer_id, 'id' => $ticket_id]);

                    $_SESSION['care_success'] = "Reply & instructions sent to customer.";

                    // Optional SMS Notice
                    if ($send_sms && class_exists('SmsService')) {
                        $stmtPhone = $pdo->prepare("
                            SELECT u.phone_number, st.ticket_no 
                            FROM support_tickets st 
                            JOIN users u ON st.user_id = u.id 
                            WHERE st.id = :id LIMIT 1
                        ");
                        $stmtPhone->execute(['id' => $ticket_id]);
                        $tData = $stmtPhone->fetch(PDO::FETCH_ASSOC);

                        if ($tData) {
                            $smsText = "Habari, tumetuma majibu ya Tiketi yako #{$tData['ticket_no']} kwenye akaunti yako ya Tunza Waleti. Tafadhali ingia kusoma maelekezo.";
                            SmsService::sendSMS($pdo, $tData['phone_number'], $smsText);
                        }
                    }
                }
            }

            // B. ESCALATE TICKET TO FINANCIAL TRANSACTION OFFICER
            elseif ($action === 'escalate_finance') {
                $admin_notes = trim($_POST['admin_notes'] ?? '');
                $stmt = $pdo->prepare("
                    UPDATE support_tickets 
                    SET status = 'escalated_to_finance', admin_notes = :notes, assigned_to = :assigned, updated_at = NOW() 
                    WHERE id = :id
                ");
                $stmt->execute(['notes' => $admin_notes, 'assigned' => $officer_id, 'id' => $ticket_id]);

                $_SESSION['care_success'] = "Ticket successfully escalated to Transaction Officer for financial audit.";
            }

            // C. MARK TICKET AS RESOLVED
            elseif ($action === 'resolve_ticket') {
                $stmt = $pdo->prepare("UPDATE support_tickets SET status = 'resolved', updated_at = NOW() WHERE id = :id");
                $stmt->execute(['id' => $ticket_id]);

                $_SESSION['care_success'] = "Ticket marked as Resolved.";
            }

        } catch (PDOException $e) {
            $_SESSION['care_error'] = "Action failed: " . $e->getMessage();
        }
    }
    header("Location: tickets.php");
    exit();
}

// ------------------------------------------------------------------
// FETCH TICKETS FILTER WITH UNIQUE PDO PARAMETERS (FIXED HY093)
// ------------------------------------------------------------------
$statusFilter = trim($_GET['status'] ?? '');
$search       = trim($_GET['search'] ?? '');

$sql = "
    SELECT st.*, u.full_name, u.phone_number, u.role 
    FROM support_tickets st 
    JOIN users u ON st.user_id = u.id 
    WHERE 1=1
";
$params = [];

if (!empty($statusFilter)) {
    $sql .= " AND st.status = :status";
    $params['status'] = $statusFilter;
}

if (!empty($search)) {
    // Unique parameter names prevent PDO "Invalid parameter number" (HY093) exception
    $sql .= " AND (st.ticket_no LIKE :search1 OR u.full_name LIKE :search2 OR u.phone_number LIKE :search3 OR CAST(st.id AS CHAR) = :search4)";
    $params['search1'] = "%{$search}%";
    $params['search2'] = "%{$search}%";
    $params['search3'] = "%{$search}%";
    $params['search4'] = $search;
}

$sql .= " ORDER BY st.created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$ticketsList = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

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
    <title>Support Tickets | Customer Care</title>
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
            background: #3d0037; 
            color: #fff; 
            padding: 25px 0; 
            flex-shrink: 0; 
            transition: all 0.3s ease; 
            z-index: 1000;
        }
        .sidebar-brand { text-align: center; padding-bottom: 25px; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .sidebar-menu { list-style: none; margin-top: 20px; }
        .sidebar-menu li a { display: flex; align-items: center; gap: 12px; padding: 14px 25px; color: #d8c2d5; text-decoration: none; font-size: 14px; transition: 0.3s; }
        .sidebar-menu li a:hover, .sidebar-menu li.active a { background: #510049; color: #fff; border-left: 4px solid #27ae60; }

        .sidebar-overlay {
            display: none;
            position: fixed;
            top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 999;
            backdrop-filter: blur(2px);
        }
        .sidebar-overlay.active { display: block; }

        /* Main Content Container */
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

        /* Filters */
        .filter-card { background: #fff; padding: 18px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); margin-bottom: 22px; }
        .filter-form { display: flex; gap: 12px; flex-wrap: wrap; width: 100%; }
        .filter-form input, .filter-form select { padding: 10px 14px; border: 1.5px solid #ddd; border-radius: 8px; font-size: 14px; outline: none; }

        /* Responsive Table Container */
        .panel-section { background: #fff; padding: 25px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); }
        .table-responsive { width: 100%; overflow-x: auto; -webkit-overflow-scrolling: touch; }
        
        .data-table { width: 100%; border-collapse: collapse; font-size: 14px; text-align: left; min-width: 720px; }
        .data-table th { background: #f8f9fa; padding: 12px 15px; font-weight: 600; color: #555; border-bottom: 2px solid #eee; white-space: nowrap; }
        .data-table td { padding: 12px 15px; border-bottom: 1px solid #eee; vertical-align: middle; }

        .badge { padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; text-transform: uppercase; display: inline-block; }
        .badge.open { background: #fff3cd; color: #856404; }
        .badge.escalated { background: #cce5ff; color: #004085; }
        .badge.resolved { background: #d4edda; color: #155724; }

        .btn-action { padding: 8px 12px; border-radius: 6px; background: #510049; color: #fff; border: none; cursor: pointer; font-size: 12px; font-weight: 600; display: inline-flex; align-items: center; gap: 6px; white-space: nowrap; transition: 0.3s; }
        .btn-action:hover { background: #000; }
        .btn-view { background: #34495e; }
        .btn-view:hover { background: #1a252f; }
        .btn-escalate { background: #2980b9; }
        .btn-escalate:hover { background: #1c5980; }
        .btn-resolve { background: #27ae60; }
        .btn-resolve:hover { background: #1e8449; }

        /* Modal Overlays */
        .modal-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.55); backdrop-filter: blur(4px); display: flex; align-items: center; justify-content: center; z-index: 9999; opacity: 0; visibility: hidden; transition: all 0.3s ease; }
        .modal-overlay.active { opacity: 1; visibility: visible; }
        .modal-box { background: #fff; width: 90%; max-width: 520px; padding: 25px; border-radius: 16px; box-shadow: 0 15px 35px rgba(0,0,0,0.25); max-height: 90vh; overflow-y: auto; }
        .modal-box h3 { margin-bottom: 12px; color: #2c3e50; }
        .textarea-box { width: 100%; padding: 12px; border: 1.5px solid #ddd; border-radius: 8px; margin: 10px 0; font-size: 14px; outline: none; }

        .message-view-content { background: #f8f9fa; border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px; margin: 12px 0; max-height: 250px; overflow-y: auto; font-size: 14px; color: #333; line-height: 1.5; white-space: pre-wrap; word-break: break-word; }

        /* Responsive Breakpoints */
        @media (max-width: 768px) {
            .menu-toggle { display: inline-block; }
            .sidebar { position: fixed; top: 0; left: -260px; }
            .sidebar.active { left: 0; }
            .main-content { padding: 20px 15px; width: 100vw; }
            .filter-form { flex-direction: column; }
            .filter-form input, .filter-form select, .filter-form button { width: 100%; }
        }
    </style>
</head>
<body>

<div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

<div class="admin-container">
    <div class="sidebar" id="careSidebar">
        <div class="sidebar-brand">
            <img src="../../<?= htmlspecialchars($site_logo) ?>" alt="Logo" style="width: 48px; height: 48px; border-radius: 50%; object-fit: cover;">
            <h2 style="font-size: 18px; margin-top: 8px;">Customer Care</h2>
        </div>
        <ul class="sidebar-menu">
            <li><a href="dashboard.php"><i class="fas fa-chart-line"></i> Care Dashboard</a></li>
            <li class="active"><a href="tickets.php"><i class="fas fa-headset"></i> Support Tickets</a></li>
            <li><a href="users_view.php"><i class="fas fa-users"></i> Inspect Customers</a></li>
            <li><a href="send_sms.php"><i class="fas fa-comment-sms"></i> Send SMS Notice</a></li>
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
                    <h2>Support Ticket Management</h2>
                    <p style="font-size: 13px; color: #666;">Inspect customer issues, send instructions, or escalate financial disputes</p>
                </div>
            </div>
        </div>

        <div class="filter-card">
            <form method="GET" action="tickets.php" class="filter-form">
                <input type="text" name="search" placeholder="Search by Ticket #, Name, Phone..." value="<?= htmlspecialchars($search) ?>" style="flex-grow:1;">
                <select name="status">
                    <option value="">All Statuses</option>
                    <option value="open" <?= $statusFilter === 'open' ? 'selected' : '' ?>>Open Only</option>
                    <option value="escalated_to_finance" <?= $statusFilter === 'escalated_to_finance' ? 'selected' : '' ?>>Escalated to Finance</option>
                    <option value="resolved" <?= $statusFilter === 'resolved' ? 'selected' : '' ?>>Resolved Only</option>
                </select>
                <button type="submit" class="btn-action"><i class="fas fa-search"></i> Filter</button>
            </form>
        </div>

        <div class="panel-section">
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Ticket #</th>
                            <th>Customer</th>
                            <th>Category</th>
                            <th>Subject & Message</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($ticketsList)): ?>
                            <?php foreach ($ticketsList as $tck): ?>
                                <tr>
                                    <td><strong>#<?= htmlspecialchars($tck['ticket_no']) ?></strong></td>
                                    <td>
                                        <strong><?= htmlspecialchars($tck['full_name']) ?></strong><br>
                                        <small style="color: #777;"><?= htmlspecialchars($tck['phone_number']) ?></small>
                                    </td>
                                    <td><span style="text-transform: capitalize; font-weight: 500;"><?= htmlspecialchars($tck['category']) ?></span></td>
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 8px;">
                                            <button type="button" class="btn-action btn-view" 
                                                    onclick="openMessageModal('<?= htmlspecialchars(addslashes($tck['ticket_no'])) ?>', '<?= htmlspecialchars(addslashes($tck['full_name'])) ?>', '<?= htmlspecialchars(addslashes($tck['category'])) ?>', '<?= htmlspecialchars(addslashes($tck['subject'])) ?>', '<?= htmlspecialchars(addslashes($tck['message'])) ?>', '<?= htmlspecialchars(addslashes($tck['created_at'])) ?>')">
                                                <i class="fas fa-eye"></i> View Message
                                            </button>
                                            <span style="font-size: 13px; color: #555; max-width: 180px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; display: inline-block;">
                                                <?= htmlspecialchars($tck['subject']) ?>
                                            </span>
                                        </div>
                                    </td>
                                    <td>
                                        <?php 
                                            $st = strtolower($tck['status']);
                                            $cls = ($st === 'open') ? 'open' : (($st === 'escalated_to_finance') ? 'escalated' : 'resolved');
                                        ?>
                                        <span class="badge <?= $cls ?>"><?= str_replace('_', ' ', $st) ?></span>
                                    </td>
                                    <td>
                                        <div style="display:flex; gap:6px;">
                                            <button type="button" class="btn-action" onclick="openReplyModal(<?= $tck['id'] ?>, '<?= htmlspecialchars(addslashes($tck['ticket_no'])) ?>', '<?= htmlspecialchars(addslashes($tck['user_reply'] ?? '')) ?>')">
                                                <i class="fas fa-reply"></i> Instruction
                                            </button>
                                            <button type="button" class="btn-action btn-escalate" onclick="openEscalateModal(<?= $tck['id'] ?>, '<?= htmlspecialchars(addslashes($tck['ticket_no'])) ?>')">
                                                <i class="fas fa-arrow-up"></i> Finance
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="6" style="text-align: center; color: #777; padding: 20px;">No support tickets matching criteria.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- 1. Read Message Dialog Modal -->
<div class="modal-overlay" id="readMessageModal">
    <div class="modal-box">
        <h3><i class="fas fa-envelope-open-text" style="color: #510049;"></i> Customer Issue Details</h3>
        <p style="font-size: 13px; color: #666;">Ticket: <strong id="msgTicketNo"></strong> | Category: <span id="msgCategory" style="text-transform: capitalize; font-weight: 600;"></span></p>
        <p style="font-size: 13px; color: #666; margin-top: 4px;">Submitted by: <strong id="msgCustomer"></strong> (<span id="msgDate"></span>)</p>

        <div style="margin-top: 15px;">
            <strong style="font-size: 14px; color: #2c3e50;" id="msgSubject"></strong>
            <div class="message-view-content" id="msgBody"></div>
        </div>

        <button type="button" class="btn-action" style="width: 100%; justify-content: center; margin-top: 10px;" onclick="closeModal('readMessageModal')">
            Close Dialog
        </button>
    </div>
</div>

<!-- 2. Reply / Instruction Modal -->
<div class="modal-overlay" id="replyModal">
    <div class="modal-box">
        <h3><i class="fas fa-reply" style="color: #510049;"></i> Send Customer Instructions</h3>
        <p style="font-size: 13px; color: #666;">Ticket: <strong id="repTicketNo"></strong></p>
        
        <form method="POST" action="tickets.php">
            <input type="hidden" name="action" value="reply_user">
            <input type="hidden" name="ticket_id" id="repTicketId">
            
            <textarea name="user_reply" id="repText" class="textarea-box" rows="5" placeholder="Write step-by-step instructions for the user..." required></textarea>
            
            <div style="margin-bottom: 15px; text-align: left;">
                <label style="font-size: 13px; cursor: pointer;">
                    <input type="checkbox" name="send_sms_notice" value="1" checked> Send SMS alert to customer
                </label>
            </div>

            <div style="display: flex; gap: 10px;">
                <button type="submit" class="btn-action" style="flex: 1; justify-content: center;">Send Reply</button>
                <button type="button" class="btn-action" style="background:#7f8c8d;" onclick="closeModal('replyModal')">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- 3. Escalate Modal -->
<div class="modal-overlay" id="escalateModal">
    <div class="modal-box">
        <h3><i class="fas fa-arrow-up" style="color: #2980b9;"></i> Escalate to Transaction Officer</h3>
        <p style="font-size: 13px; color: #666;">Ticket: <strong id="escTicketNo"></strong></p>
        
        <form method="POST" action="tickets.php">
            <input type="hidden" name="action" value="escalate_finance">
            <input type="hidden" name="ticket_id" id="escTicketId">
            
            <textarea name="admin_notes" class="textarea-box" rows="5" placeholder="Add internal notes for the Transaction Officer (e.g., payment reference discrepancy)..." required></textarea>

            <div style="display: flex; gap: 10px;">
                <button type="submit" class="btn-action btn-escalate" style="flex: 1; justify-content: center;">Escalate Ticket</button>
                <button type="button" class="btn-action" style="background:#7f8c8d;" onclick="closeModal('escalateModal')">Cancel</button>
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
        document.getElementById('careSidebar').classList.toggle('active');
        document.getElementById('sidebarOverlay').classList.toggle('active');
    }

    function openMessageModal(ticketNo, customerName, category, subject, message, dateStr) {
        document.getElementById('msgTicketNo').innerText = '#' + ticketNo;
        document.getElementById('msgCustomer').innerText = customerName;
        document.getElementById('msgCategory').innerText = category;
        document.getElementById('msgSubject').innerText = subject;
        document.getElementById('msgBody').innerText = message;
        document.getElementById('msgDate').innerText = dateStr;
        document.getElementById('readMessageModal').classList.add('active');
    }

    function openReplyModal(id, ticketNo, currentReply) {
        document.getElementById('repTicketId').value = id;
        document.getElementById('repTicketNo').innerText = '#' + ticketNo;
        document.getElementById('repText').value = currentReply;
        document.getElementById('replyModal').classList.add('active');
    }

    function openEscalateModal(id, ticketNo) {
        document.getElementById('escTicketId').value = id;
        document.getElementById('escTicketNo').innerText = '#' + ticketNo;
        document.getElementById('escalateModal').classList.add('active');
    }

    function closeModal(id) {
        document.getElementById(id).classList.remove('active');
    }

    window.addEventListener('DOMContentLoaded', () => {
        <?php if (!empty($error_msg)): ?>
            document.getElementById('notifTitle').innerText = 'Action Alert';
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