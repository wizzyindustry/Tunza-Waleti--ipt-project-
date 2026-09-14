<?php
// admin/customer_care/send_sms.php

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

// Access Control Guard
if (!isset($_SESSION['care_id']) || ($_SESSION['care_role'] ?? '') !== 'customer_care') {
    header("Location: ../../admin/login.php?role=customer_care");
    exit();
}

$officer_id   = $_SESSION['care_id'];
$officer_name = $_SESSION['care_name'] ?? 'Customer Care Officer';

$prefill_phone = trim($_GET['phone'] ?? '');
$error_msg     = $_SESSION['care_error'] ?? '';
$success_msg   = $_SESSION['care_success'] ?? '';
unset($_SESSION['care_error'], $_SESSION['care_success']);

// Handle SMS Dispatch
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_direct_sms'])) {
    $recipient_phone = trim($_POST['recipient_phone'] ?? '');
    $message_text    = trim($_POST['message_text'] ?? '');

    if (empty($recipient_phone) || empty($message_text)) {
        $_SESSION['care_error'] = "Please provide both a recipient phone number and a message.";
    } else {
        if (class_exists('SmsService')) {
            $errorDetails = '';
            $sent = SmsService::sendSMS($pdo, $recipient_phone, $message_text, $errorDetails);

            if ($sent) {
                $_SESSION['care_success'] = "SMS successfully dispatched to {$recipient_phone} via MailerSend!";
            } else {
                $_SESSION['care_error'] = !empty($errorDetails) ? $errorDetails : "SMS dispatch failed. Check MailerSend settings.";
            }
        } else {
            $_SESSION['care_error'] = "SMS Helper service not found.";
        }
    }
    header("Location: send_sms.php" . (!empty($recipient_phone) ? "?phone=" . urlencode($recipient_phone) : ''));
    exit();
}

// Fetch Customers List for Dropdown Selector
$users = [];
try {
    $stmtUsers = $pdo->query("SELECT id, full_name, phone_number FROM users WHERE role = 'user' ORDER BY full_name ASC");
    $users = $stmtUsers->fetchAll(PDO::FETCH_ASSOC) ?: [];
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
    <title>Send SMS | Customer Care</title>
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

        /* SMS Grid Layout */
        .sms-layout { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 25px; width: 100%; }
        .card-panel { background: #fff; padding: 25px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); }
        
        .form-group { margin-bottom: 16px; }
        .form-group label { display: block; font-weight: 600; font-size: 13px; color: #444; margin-bottom: 6px; }
        .form-group input, .form-group select, .form-group textarea { width: 100%; padding: 11px 14px; border-radius: 8px; border: 1.5px solid #ddd; font-size: 14px; outline: none; transition: 0.3s; }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus { border-color: #510049; }

        .btn-action { padding: 12px 24px; border-radius: 8px; background: #27ae60; color: #fff; border: none; cursor: pointer; font-size: 14px; font-weight: 600; width: 100%; display: flex; justify-content: center; align-items: center; gap: 8px; transition: 0.3s; }
        .btn-action:hover { background: #1e8449; }

        .template-btn { background: #f8f9fa; border: 1px solid #ddd; padding: 12px; border-radius: 8px; text-align: left; width: 100%; cursor: pointer; font-size: 13px; margin-bottom: 10px; transition: 0.2s; }
        .template-btn:hover { background: #eef0f2; border-color: #bbb; }

        /* Modal Overlays */
        .modal-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.55); backdrop-filter: blur(4px); display: flex; align-items: center; justify-content: center; z-index: 9999; opacity: 0; visibility: hidden; transition: all 0.3s ease; }
        .modal-overlay.active { opacity: 1; visibility: visible; }
        .modal-box { background: #fff; width: 90%; max-width: 400px; padding: 25px; border-radius: 16px; text-align: center; box-shadow: 0 15px 35px rgba(0,0,0,0.25); }

        /* Responsive Breakpoints */
        @media (max-width: 768px) {
            .menu-toggle { display: inline-block; }
            .sidebar { position: fixed; top: 0; left: -260px; }
            .sidebar.active { left: 0; }
            .main-content { padding: 20px 15px; width: 100vw; }
            .sms-layout { grid-template-columns: 1fr; }
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
            <li><a href="tickets.php"><i class="fas fa-headset"></i> Support Tickets</a></li>
            <li><a href="users_view.php"><i class="fas fa-users"></i> Inspect Customers</a></li>
            <li class="active"><a href="send_sms.php"><i class="fas fa-comment-sms"></i> Send SMS Notice</a></li>
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
                    <h2>Send SMS Notification</h2>
                    <p style="font-size: 13px; color: #666;">Dispatch instructions or updates directly to a customer's phone number</p>
                </div>
            </div>
        </div>

        <div class="sms-layout">
            <!-- SMS Form -->
            <div class="card-panel">
                <form method="POST" action="send_sms.php">
                    <input type="hidden" name="send_direct_sms" value="1">

                    <div class="form-group">
                        <label><i class="fas fa-user"></i> Select Customer</label>
                        <select onchange="document.getElementById('phoneInput').value = this.value">
                            <option value="">-- Choose Customer --</option>
                            <?php foreach ($users as $u): ?>
                                <option value="<?= htmlspecialchars($u['phone_number']) ?>" <?= $prefill_phone === $u['phone_number'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($u['full_name']) ?> (<?= htmlspecialchars($u['phone_number']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label><i class="fas fa-mobile-alt"></i> Recipient Phone Number (E.164 Format)</label>
                        <input type="text" name="recipient_phone" id="phoneInput" placeholder="e.g. 255712345678" value="<?= htmlspecialchars($prefill_phone) ?>" required>
                    </div>

                    <div class="form-group">
                        <label><i class="fas fa-comment-dots"></i> Message Content</label>
                        <textarea name="message_text" id="msgText" rows="6" placeholder="Write your instructions or notification message..." required></textarea>
                    </div>

                    <button type="submit" class="btn-action">
                        <i class="fas fa-paper-plane"></i> Dispatch SMS Notification
                    </button>
                </form>
            </div>

            <!-- Template Panel -->
            <div class="card-panel">
                <h3 style="font-size: 16px; color: #3d0037; margin-bottom: 15px; border-bottom: 2px solid #eee; padding-bottom: 8px;">
                    <i class="fas fa-file-alt"></i> Quick Templates
                </h3>
                <p style="font-size: 12px; color: #666; margin-bottom: 15px;">Click any template below to pre-fill the message box:</p>

                <button type="button" class="template-btn" onclick="useTemplate('Habari! Tumepokea ombi lako la msaada. Ombi lako limeshughulikiwa kikamilifu. Ingia kwenye akaunti yako kuangalia matokeo.')">
                    <strong><i class="fas fa-check-circle" style="color: #27ae60;"></i> Issue Resolved Notice</strong><br>
                    <span style="color:#777; font-size: 12px;">Informs customer that their ticket is resolved.</span>
                </button>

                <button type="button" class="template-btn" onclick="useTemplate('Habari! Ombi lako la muamala limepelekwa kwa Afisa wa Fedha kwa ajili ya uhakiki zaidi. Tutawasiliana nawe hivi karibuni.')">
                    <strong><i class="fas fa-clock" style="color: #f39c12;"></i> Escalated to Finance Notice</strong><br>
                    <span style="color:#777; font-size: 12px;">Notifies customer that their transaction is under financial audit.</span>
                </button>

                <button type="button" class="template-btn" onclick="useTemplate('Habari! Akaunti yako ya Tunza Waleti imerejeshwa kikamilifu. Unaweza kuendelea kutumia huduma zetu kama kawaida.')">
                    <strong><i class="fas fa-user-check" style="color: #2980b9;"></i> Account Reactivated Notice</strong><br>
                    <span style="color:#777; font-size: 12px;">Informs customer that account suspension has been lifted.</span>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Notification Modal -->
<div class="modal-overlay" id="notificationModal">
    <div class="modal-box">
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

    function useTemplate(text) {
        document.getElementById('msgText').value = text;
    }

    function closeModal(id) {
        document.getElementById(id).classList.remove('active');
    }

    window.addEventListener('DOMContentLoaded', () => {
        <?php if (!empty($error_msg)): ?>
            document.getElementById('notifTitle').innerText = 'Dispatch Error';
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