<?php
// admin/settings.php

// 1. Set isolated administrator session name
session_name('TUNZA_MAIN_ADMIN_SESSION');
session_start();

require_once '../database/auth.php';
require_once '../database/config.php';

if (file_exists('../api/sms.php')) {
    require_once '../api/sms.php';
}

// Normalize database handle
if (!isset($pdo) && isset($conn)) {
    $pdo = $conn;
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
// 2. ACTION HANDLERS: SAVE SETTINGS & TEST MAILERSEND SMS
// ------------------------------------------------------------------

// A. SAVE ALL SYSTEM SETTINGS
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    $allowed_keys = [
        'app_name',
        'clickpesa_client_id',
        'clickpesa_api_key',
        'clickpesa_checksum_key',
        'mailersend_api_key',
        'mailersend_from_number',
        'min_deposit_limit',
        'max_deposit_limit',
        'min_withdraw_limit',
        'max_withdraw_limit',
        'daily_transaction_limit',
        'withdrawal_fee_percent',
        'maintenance_mode'
    ];

    try {
        $pdo->beginTransaction();

        $stmtSave = $pdo->prepare("
            INSERT INTO system_settings (setting_key, setting_value) 
            VALUES (:key, :value) 
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
        ");

        foreach ($allowed_keys as $key) {
            $val = trim($_POST[$key] ?? '');
            if ($key === 'maintenance_mode') {
                $val = isset($_POST['maintenance_mode']) ? '1' : '0';
            }
            $stmtSave->execute(['key' => $key, 'value' => $val]);
        }

        // Handle Site Logo Upload
        if (isset($_FILES['site_logo']) && $_FILES['site_logo']['error'] === UPLOAD_ERR_OK) {
            $fileTmp  = $_FILES['site_logo']['tmp_name'];
            $fileName = $_FILES['site_logo']['name'];
            $fileExt  = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

            if (in_array($fileExt, ['jpg', 'jpeg', 'png', 'webp', 'svg'])) {
                $newFileName = 'logo_' . time() . '.' . $fileExt;
                $targetDir   = '../assets/images/';

                if (!is_dir($targetDir)) {
                    mkdir($targetDir, 0755, true);
                }

                if (move_uploaded_file($fileTmp, $targetDir . $newFileName)) {
                    $logoPath = 'assets/images/' . $newFileName;
                    $stmtSave->execute(['key' => 'site_logo', 'value' => $logoPath]);
                }
            }
        }

        $pdo->commit();
        $_SESSION['admin_success'] = "All system and MailerSend settings saved successfully.";
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $_SESSION['admin_error'] = "Failed to save settings: " . $e->getMessage();
    }

    header("Location: settings.php");
    exit();
}

// B. TEST MAILERSEND SMS DISPATCH
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['test_sms_dispatch'])) {
    $test_phone = trim($_POST['test_phone_number'] ?? '');

    if (empty($test_phone)) {
        $_SESSION['admin_error'] = "Please enter a valid phone number to test SMS.";
    } else {
        if (class_exists('SmsService')) {
            $testMsg = "Habari! Hii ni ujumbe wa majaribio kutoka Tunza Waleti. Mfumo wako wa MailerSend SMS upo tayari kikamilifu!";
            $errorDetails = '';
            $sent = SmsService::sendSMS($pdo, $test_phone, $testMsg, $errorDetails);

            if ($sent) {
                $_SESSION['admin_success'] = "Test SMS dispatched successfully to {$test_phone} via MailerSend!";
            } else {
                $_SESSION['admin_error'] = !empty($errorDetails) ? $errorDetails : "Failed to send Test SMS. Check MailerSend API Key & From Number.";
            }
        } else {
            $_SESSION['admin_error'] = "SmsService helper file (helpers/SmsService.php) not found.";
        }
    }

    header("Location: settings.php");
    exit();
}

// ------------------------------------------------------------------
// 3. FETCH CURRENT SETTINGS
// ------------------------------------------------------------------
$settings = [];
try {
    $stmtSettings = $pdo->query("SELECT setting_key, setting_value FROM system_settings");
    if ($stmtSettings) {
        while ($row = $stmtSettings->fetch(PDO::FETCH_ASSOC)) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
    }
} catch (PDOException $e) {
    // Silently fallback
}

$site_logo = $settings['site_logo'] ?? 'assets/images/panta logo-07.jpg';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Settings | Tunza Admin</title>
    <link rel="shortcut icon" href="../<?= htmlspecialchars($site_logo) ?>" type="image/x-icon">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap');

        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Poppins', sans-serif; }
        html, body { height: 100%; overflow: hidden; background-color: #f4f7f6; color: #2c3e50; }

        .admin-container { display: flex; height: 100vh; width: 100vw; overflow: hidden; position: relative; }

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

        .main-content { 
            flex-grow: 1; 
            height: 100vh;
            padding: 30px; 
            overflow-y: auto; 
            width: calc(100vw - 260px); 
        }

        .header-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; gap: 15px; flex-wrap: wrap; }
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

        .settings-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 25px;
            margin-bottom: 30px;
        }

        .settings-card {
            background: #fff;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
        }

        .settings-card h3 {
            font-size: 16px;
            color: #3d0037;
            margin-bottom: 18px;
            border-bottom: 2px solid #f0f0f0;
            padding-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .form-group { margin-bottom: 16px; }
        .form-group label { display: block; margin-bottom: 6px; font-weight: 600; font-size: 13px; color: #444; }
        .form-group input, .form-group select { 
            width: 100%; 
            padding: 10px 14px; 
            border-radius: 8px; 
            border: 1.5px solid #ddd; 
            font-size: 14px; 
            outline: none;
        }

        .btn-action { 
            padding: 12px 24px; 
            border: none; 
            border-radius: 8px; 
            background: #510049; 
            color: #fff; 
            cursor: pointer; 
            font-size: 14px; 
            font-weight: 600; 
            transition: 0.3s; 
            display: inline-flex; 
            align-items: center; 
            gap: 8px; 
        }
        .btn-action:hover { background: #000; }
        .btn-sms { background: #00a884; }
        .btn-sms:hover { background: #008f70; }

        .toggle-container {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px;
            background: #f8f9fa;
            border-radius: 8px;
            border: 1px solid #eee;
        }

        .modal-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.55); backdrop-filter: blur(4px); display: flex; align-items: center; justify-content: center; z-index: 9999; opacity: 0; visibility: hidden; transition: all 0.3s ease; }
        .modal-overlay.active { opacity: 1; visibility: visible; }
        .modal-box { background: #fff; width: 90%; max-width: 420px; padding: 28px; border-radius: 16px; box-shadow: 0 15px 35px rgba(0,0,0,0.25); text-align: center; }
        .modal-box h3 { margin-bottom: 12px; color: #2c3e50; }

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
    <div class="sidebar" id="adminSidebar">
        <div class="sidebar-brand">
            <img src="../<?= htmlspecialchars($site_logo) ?>" alt="Tunza Logo" style="width: 50px; height: 50px; border-radius: 50%; object-fit: cover;">
            <h2>Tunza Admin</h2>
        </div>
        <ul class="sidebar-menu">
            <li><a href="dashboard.php"><i class="fas fa-chart-line"></i> Dashboard</a></li>
            <li><a href="users.php"><i class="fas fa-users-cog"></i> Manage Users</a></li>
            <li><a href="transactions.php"><i class="fas fa-exchange-alt"></i> Transactions</a></li>
            <li class="active"><a href="settings.php"><i class="fas fa-cog"></i> Settings</a></li>
            <li><a href="../pages/dashboard.php"><i class="fas fa-user-circle"></i> User Panel</a></li>
            <li><a href="../pages/logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
        </ul>
    </div>

    <div class="main-content">
        <div class="header-bar">
            <div class="header-title-container">
                <button class="menu-toggle" id="menuToggleBtn" onclick="toggleSidebar()" aria-label="Toggle Sidebar">
                    <i class="fas fa-bars"></i>
                </button>
                <div>
                    <h2>System Settings</h2>
                    <p style="font-size: 13px; color: #666;">Configure ClickPesa, MailerSend SMS, limits, and system branding</p>
                </div>
            </div>
            <button type="button" class="btn-action btn-sms" onclick="openTestSmsModal()">
                <i class="fas fa-paper-plane"></i> Test MailerSend SMS
            </button>
        </div>

        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="save_settings" value="1">

            <div class="settings-grid">
                
                <!-- 1. General Branding -->
                <div class="settings-card">
                    <h3><i class="fas fa-building"></i> General & Branding</h3>
                    <div class="form-group">
                        <label>Application Name</label>
                        <input type="text" name="app_name" value="<?= htmlspecialchars($settings['app_name'] ?? 'Tunza Waleti') ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Current Logo Preview</label>
                        <div style="margin-bottom: 8px;">
                            <img src="../<?= htmlspecialchars($site_logo) ?>" alt="Logo" style="width: 60px; height: 60px; border-radius: 50%; object-fit: cover; border: 2px solid #ddd;">
                        </div>
                        <input type="file" name="site_logo" accept="image/*">
                    </div>
                    <div class="form-group">
                        <div class="toggle-container">
                            <div>
                                <strong style="font-size: 13px;">Maintenance Mode</strong>
                                <p style="font-size: 11px; color: #666;">Block deposits & withdrawals globally</p>
                            </div>
                            <input type="checkbox" name="maintenance_mode" style="width: 20px; height: 20px;" <?= ($settings['maintenance_mode'] ?? '0') === '1' ? 'checked' : '' ?>>
                        </div>
                    </div>
                </div>

                <!-- 2. MailerSend SMS Integration -->
                <div class="settings-card">
                    <h3><i class="fas fa-sms"></i> MailerSend SMS Gateway</h3>
                    <div class="form-group">
                        <label>MailerSend API Key</label>
                        <input type="password" name="mailersend_api_key" placeholder="mlsn.xxxxxxxxxxxxxxxx" value="<?= htmlspecialchars($settings['mailersend_api_key'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label>Sender Phone Number (From)</label>
                        <input type="text" name="mailersend_from_number" placeholder="e.g. +12065550101" value="<?= htmlspecialchars($settings['mailersend_from_number'] ?? '') ?>">
                    </div>
                </div>

                <!-- 3. ClickPesa Gateway Credentials -->
                <div class="settings-card">
                    <h3><i class="fas fa-credit-card"></i> ClickPesa Integration</h3>
                    <div class="form-group">
                        <label>ClickPesa Client ID</label>
                        <input type="text" name="clickpesa_client_id" value="<?= htmlspecialchars($settings['clickpesa_client_id'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label>ClickPesa API Key</label>
                        <input type="password" name="clickpesa_api_key" value="<?= htmlspecialchars($settings['clickpesa_api_key'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label>Checksum Secret Key</label>
                        <input type="password" name="clickpesa_checksum_key" value="<?= htmlspecialchars($settings['clickpesa_checksum_key'] ?? '') ?>">
                    </div>
                </div>

                <!-- 4. Transaction Limits -->
                <div class="settings-card">
                    <h3><i class="fas fa-sliders-h"></i> Limits & Fees</h3>
                    <div class="form-group">
                        <label>Min Deposit Limit (TZS)</label>
                        <input type="number" name="min_deposit_limit" value="<?= htmlspecialchars($settings['min_deposit_limit'] ?? '1000') ?>">
                    </div>
                    <div class="form-group">
                        <label>Max Deposit Limit (TZS)</label>
                        <input type="number" name="max_deposit_limit" value="<?= htmlspecialchars($settings['max_deposit_limit'] ?? '3000000') ?>">
                    </div>
                    <div class="form-group">
                        <label>Min Withdrawal Limit (TZS)</label>
                        <input type="number" name="min_withdraw_limit" value="<?= htmlspecialchars($settings['min_withdraw_limit'] ?? '1000') ?>">
                    </div>
                    <div class="form-group">
                        <label>Withdrawal Fee (%)</label>
                        <input type="number" step="0.1" name="withdrawal_fee_percent" value="<?= htmlspecialchars($settings['withdrawal_fee_percent'] ?? '1.5') ?>">
                    </div>
                </div>

            </div>

            <button type="submit" class="btn-action" style="width: 100%; justify-content: center; font-size: 16px; padding: 14px;">
                <i class="fas fa-save"></i> Save All System Settings
            </button>
        </form>

    </div>
</div>

<!-- Test SMS Modal -->
<div class="modal-overlay" id="testSmsModal">
    <div class="modal-box">
        <h3><i class="fas fa-paper-plane" style="color: #00a884;"></i> Test MailerSend SMS</h3>
        <p style="font-size: 13px; color: #666; margin-bottom: 15px;">Send a test message to verify your MailerSend configuration.</p>

        <form method="POST">
            <input type="hidden" name="test_sms_dispatch" value="1">
            <input type="text" name="test_phone_number" placeholder="e.g. +255712345678" style="width: 100%; padding: 10px; border-radius: 8px; border: 1.5px solid #ddd; margin-bottom: 15px;" required>

            <div style="display: flex; gap: 10px;">
                <button type="submit" class="btn-action btn-sms" style="flex: 1; justify-content: center;">Send Test</button>
                <button type="button" class="btn-action" style="background:#7f8c8d;" onclick="closeTestSmsModal()">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Notification Modal -->
<div class="modal-overlay" id="notificationModal">
    <div class="modal-box">
        <h3 id="notifTitle">Notification</h3>
        <p id="notifMessage" style="font-size: 14px; color: #555; margin: 15px 0;">Details...</p>
        <button type="button" class="btn-action" style="width: 100%; justify-content: center;" onclick="document.getElementById('notificationModal').classList.remove('active')">OK</button>
    </div>
</div>

<script>
    function toggleSidebar() {
        document.getElementById('adminSidebar').classList.toggle('active');
        document.getElementById('sidebarOverlay').classList.toggle('active');
    }

    function openTestSmsModal() {
        document.getElementById('testSmsModal').classList.add('active');
    }

    function closeTestSmsModal() {
        document.getElementById('testSmsModal').classList.remove('active');
    }

    // Cross-tab session listener
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
            document.getElementById('notifTitle').innerText = 'System Alert';
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