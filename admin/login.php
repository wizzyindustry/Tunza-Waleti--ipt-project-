<?php
// admin/login.php

// 1. Determine active staff context before starting session
$target_role = $_GET['role'] ?? 'admin';

switch ($target_role) {
    case 'customer_care':
        session_name('TUNZA_CARE_OFFICER_SESSION');
        break;
    case 'transaction_officer':
        session_name('TUNZA_FINANCE_OFFICER_SESSION');
        break;
    default:
        session_name('TUNZA_MAIN_ADMIN_SESSION');
        break;
}

session_start();
require_once '../database/config.php';

// Normalize PDO connection handle safely
if (!isset($pdo) && isset($conn)) {
    $pdo = $conn;
}

// 2. Redirect if already authenticated in the staff session context
if (isset($_SESSION['admin_id'])) {
    header("Location: dashboard.php");
    exit();
} elseif (isset($_SESSION['care_id'])) {
    header("Location: customer_care/dashboard.php");
    exit();
} elseif (isset($_SESSION['finance_id'])) {
    header("Location: transaction_officer/dashboard.php");
    exit();
}

// 3. Fetch system logo dynamically safely
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
        // Fallback silently
    }
}

// Retrieve flash messages
$error = $_SESSION['admin_login_error'] ?? '';
$saved_identifier = $_SESSION['saved_identifier'] ?? '';
unset($_SESSION['admin_login_error'], $_SESSION['saved_identifier']);

// 4. HANDLE STAFF POST LOGIN
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifier = trim($_POST['identifier'] ?? ''); 
    $password   = $_POST['password'] ?? '';

    if (empty($identifier) || empty($password)) {
        $_SESSION['admin_login_error'] = "Please enter your staff ID/phone and password.";
        $_SESSION['saved_identifier'] = $identifier;
        header("Location: login.php" . ($target_role !== 'admin' ? '?role=' . urlencode($target_role) : ''));
        exit();
    } else {
        try {
            // Standardize local phone format (e.g. 0700000001 -> 255700000001)
            $formattedPhone = preg_replace('/[^0-9]/', '', $identifier);
            if (strpos($formattedPhone, '0') === 0) {
                $formattedPhone = '255' . substr($formattedPhone, 1);
            }

            // Fetch user record
            $stmt = $pdo->prepare("
                SELECT * 
                FROM users 
                WHERE (phone_number = :phone_formatted OR phone_number = :phone_raw OR id = :id_raw)
                  AND role IN ('admin', 'customer_care', 'transaction_officer', 'officer')
                LIMIT 1
            ");

            $stmt->execute([
                'phone_formatted' => $formattedPhone,
                'phone_raw'       => $identifier,
                'id_raw'          => $identifier
            ]);

            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user && password_verify($password, $user['password'])) {
                
                $userRole = $user['role'] ?? '';

                // Destroy temporary setup session before starting role-specific session
                session_destroy();

                // Route to dedicated staff dashboard based on role
                switch ($userRole) {
                    case 'admin':
                        session_name('TUNZA_MAIN_ADMIN_SESSION');
                        session_start();
                        session_regenerate_id(true);

                        $_SESSION['admin_id']    = $user['id'];
                        $_SESSION['admin_name']  = $user['full_name'] ?? 'Administrator';
                        $_SESSION['admin_phone'] = $user['phone_number'] ?? '';
                        $_SESSION['admin_role']  = 'admin';

                        header("Location: dashboard.php");
                        break;

                    case 'customer_care':
                        session_name('TUNZA_CARE_OFFICER_SESSION');
                        session_start();
                        session_regenerate_id(true);

                        $_SESSION['care_id']    = $user['id'];
                        $_SESSION['care_name']  = $user['full_name'] ?? 'Care Officer';
                        $_SESSION['care_phone'] = $user['phone_number'] ?? '';
                        $_SESSION['care_role']  = 'customer_care';

                        header("Location: customer_care/dashboard.php");
                        break;

                    case 'transaction_officer':
                        session_name('TUNZA_FINANCE_OFFICER_SESSION');
                        session_start();
                        session_regenerate_id(true);

                        $_SESSION['finance_id']    = $user['id'];
                        $_SESSION['finance_name']  = $user['full_name'] ?? 'Finance Officer';
                        $_SESSION['finance_phone'] = $user['phone_number'] ?? '';
                        $_SESSION['finance_role']  = 'transaction_officer';

                        header("Location: transaction_officer/dashboard.php");
                        break;

                    default:
                        $_SESSION['admin_login_error'] = "Unauthorized role type for administrative portal.";
                        header("Location: login.php");
                        break;
                }
                exit();
            } else {
                $_SESSION['admin_login_error'] = "Invalid administrative credentials or unauthorized staff account.";
                $_SESSION['saved_identifier'] = $identifier;
                header("Location: login.php" . ($target_role !== 'admin' ? '?role=' . urlencode($target_role) : ''));
                exit();
            }
        } catch (PDOException $e) {
            $_SESSION['admin_login_error'] = "Database Connection Error: " . $e->getMessage();
            $_SESSION['saved_identifier'] = $identifier;
            header("Location: login.php" . ($target_role !== 'admin' ? '?role=' . urlencode($target_role) : ''));
            exit();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff & Admin Portal | Tunza Waleti</title>
    <link rel="stylesheet" href="../assets/style/style2.css">
    <link rel="shortcut icon" href="../<?= htmlspecialchars($site_logo) ?>" type="image/x-icon">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .main-wrapper { background: linear-gradient(135deg, #3d0037 0%, #1a0017 100%); }
        .login-container { border-top: 4px solid #e74c3c; }
        .btn button { background: #510049; }
        .btn button:hover { background: #000; }
    </style>
</head>

<body>

    <div class="main-wrapper">
        <div class="logo">
            <img src="../<?= htmlspecialchars($site_logo) ?>" alt="Tunza Waleti Admin Logo">
        </div>

        <div class="title">
            <h2 style="color: #fff;"><i class="fas fa-user-shield"></i> Staff Portal</h2>
            <p class="subtitle" style="color: #d8c2d5;">Administrative & Operations Gateway</p>
        </div>

        <div class="das">
            <div class="dash-1" style="background: #e74c3c;"></div>
            <div class="dash-2" style="background: #27ae60;"></div>
        </div>

        <div class="login-container">

            <?php if (!empty($error)): ?>
                <div class="alert error" style="padding: 12px; background: #fce4e4; color: #c0392b; border-radius: 8px; margin-bottom: 15px; font-size: 14px; text-align: center;">
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <div class="form-container">
                <form action="login.php<?= $target_role !== 'admin' ? '?role=' . htmlspecialchars(urlencode($target_role)) : '' ?>" method="POST">
                    
                    <div class="input-group">
                        <label for="identifier">Staff Phone Number or User ID</label>
                        <input type="text" name="identifier" id="identifier" placeholder="e.g. 0700000000, 0700000001..." value="<?= htmlspecialchars($saved_identifier) ?>" required>
                    </div>

                    <div class="input-group">
                        <label for="password">Staff Password</label>
                        <input type="password" name="password" id="password" placeholder="Enter your staff password" required>
                    </div>

                    <div class="btn">
                        <button type="submit"><i class="fas fa-lock"></i> Authenticate Staff</button>
                    </div>

                    <div class="option" style="text-align: center; margin-top: 15px;">
                        <p><a href="../login.php" style="color: #777; font-size: 13px;"><i class="fas fa-arrow-left"></i> Return to Customer Login</a></p>
                    </div>

                </form>
            </div>
        </div>
    </div>

    <!-- Client-side Cross-Tab Sync Script -->
    <script>
        window.addEventListener('storage', function(event) {
            if (event.key === 'tunza_session_update') {
                const sessionData = JSON.parse(event.newValue);
                if (sessionData && sessionData.role === 'admin') {
                    window.location.href = 'dashboard.php';
                } else if (sessionData && sessionData.role === 'customer_care') {
                    window.location.href = 'customer_care/dashboard.php';
                } else if (sessionData && sessionData.role === 'transaction_officer') {
                    window.location.href = 'transaction_officer/dashboard.php';
                }
            }
        });
    </script>

</body>

</html>