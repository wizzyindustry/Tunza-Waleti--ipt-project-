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
        :root {
            --primary-purple: #510049;
            --dark-purple: #3d0037;
            --deep-black: #1a0017;
            --accent-red: #e74c3c;
            --emerald-green: #117864;
            --bg-light: #f8f9fa;
        }

        body {
            margin: 0;
            padding: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: var(--deep-black);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        /* Two-Column Layout Container */
        .page-split-container {
            display: flex;
            width: 100%;
            max-width: 1100px;
            min-height: 650px;
            background: #ffffff;
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.4);
            overflow: hidden;
            margin: 20px;
        }

        /* Left Side: Operations Showcase Panel */
        .operations-left-panel {
            flex: 1.1;
            background: linear-gradient(135deg, var(--dark-purple) 0%, var(--deep-black) 100%);
            color: #ffffff;
            padding: 50px 45px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            border-right: 3px solid var(--accent-red);
        }

        .brand-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 25px;
        }

        .brand-header img {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            object-fit: cover;
        }

        .brand-header h2 {
            margin: 0;
            color: #ffffff;
            font-size: 24px;
            font-weight: 800;
        }

        .operations-left-panel h1 {
            font-size: 30px;
            font-weight: 800;
            margin-bottom: 12px;
            line-height: 1.3;
        }

        .operations-left-panel p.lead-desc {
            font-size: 14px;
            line-height: 1.6;
            opacity: 0.85;
            margin-bottom: 35px;
        }

        .role-list {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .role-card {
            display: flex;
            align-items: flex-start;
            gap: 15px;
            background: rgba(255, 255, 255, 0.05);
            padding: 15px 18px;
            border-radius: 12px;
            border: 1px solid rgba(255, 255, 255, 0.08);
        }

        .role-icon {
            width: 40px;
            height: 40px;
            background: rgba(231, 76, 60, 0.2);
            color: #ff6b6b;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            flex-shrink: 0;
        }

        .role-info h4 {
            margin: 0 0 3px 0;
            font-size: 15px;
            font-weight: 700;
            color: #ffffff;
        }

        .role-info p {
            margin: 0;
            font-size: 13px;
            opacity: 0.75;
            line-height: 1.4;
        }

        /* Right Side: Staff Login Form Panel */
        .login-right-panel {
            flex: 1;
            padding: 50px 40px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            background: #ffffff;
        }

        .login-title h3 {
            margin: 0 0 6px 0;
            font-size: 22px;
            color: var(--dark-purple);
        }

        .login-title p {
            margin: 0 0 25px 0;
            color: #666;
            font-size: 14px;
        }

        .input-group {
            margin-bottom: 20px;
        }

        .input-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            font-size: 13px;
            color: #444;
        }

        .input-group input {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #ccc;
            border-radius: 8px;
            font-size: 14px;
            box-sizing: border-box;
            transition: 0.3s;
        }

        .input-group input:focus {
            border-color: var(--primary-purple);
            outline: none;
            box-shadow: 0 0 0 3px rgba(81, 0, 73, 0.1);
        }

        .btn-submit {
            width: 100%;
            padding: 14px;
            background: var(--primary-purple);
            color: #fff;
            border: none;
            border-radius: 8px;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            transition: 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .btn-submit:hover {
            background: #000000;
        }

        .option-links {
            margin-top: 25px;
            text-align: center;
            font-size: 13px;
        }

        .option-links a {
            color: #666;
            text-decoration: none;
            font-weight: 600;
            transition: 0.3s;
        }

        .option-links a:hover {
            color: var(--primary-purple);
            text-decoration: underline;
        }

        /* Responsive Breakpoints */
        @media (max-width: 850px) {
            .page-split-container {
                flex-direction: column;
                margin: 10px;
            }
            .operations-left-panel {
                padding: 40px 25px;
                border-right: none;
                border-bottom: 3px solid var(--accent-red);
            }
            .login-right-panel {
                padding: 40px 25px;
            }
        }
    </style>
</head>

<body>

    <div class="page-split-container">
        
        <!-- LEFT PANEL: OPERATIONS & STAFF DESCRIPTIONS -->
        <div class="operations-left-panel">
            <div class="brand-header">
                <img src="../<?= htmlspecialchars($site_logo) ?>" alt="Tunza Waleti Logo">
                <h2>Tunza Waleti</h2>
            </div>

            <h1>Staff Management Gateway</h1>
            <p class="lead-desc">
                Restricted access portal for authorized administrative, support, and financial operations personnel.
            </p>

            <div class="role-list">
                <div class="role-card">
                    <div class="role-icon"><i class="fas fa-user-shield"></i></div>
                    <div class="role-info">
                        <h4>System Administrators</h4>
                        <p>Manage platform configurations, review system health, and oversee user account statuses.</p>
                    </div>
                </div>

                <div class="role-card">
                    <div class="role-icon" style="background: rgba(17, 120, 100, 0.2); color: #27ae60;"><i class="fas fa-headset"></i></div>
                    <div class="role-info">
                        <h4>Customer Care Officers</h4>
                        <p>Handle user account inquiries, manage support requests, and assist with account suspensions.</p>
                    </div>
                </div>

                <div class="role-card">
                    <div class="role-icon" style="background: rgba(243, 156, 18, 0.2); color: #f39c12;"><i class="fas fa-file-invoice-dollar"></i></div>
                    <div class="role-info">
                        <h4>Transaction Officers</h4>
                        <p>Verify deposit operations, audit transfer logs, and ensure absolute ledger consistency.</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- RIGHT PANEL: STAFF LOGIN FORM -->
        <div class="login-right-panel">
            <div class="login-title">
                <h3>Staff Authentication</h3>
                <p>Sign in with your assigned staff credentials to access your portal.</p>
            </div>

            <?php if (!empty($error)): ?>
                <div style="padding: 12px; background: #fce4e4; color: #c0392b; border-radius: 8px; margin-bottom: 20px; font-size: 13px; text-align: center;">
                    <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <form action="login.php<?= $target_role !== 'admin' ? '?role=' . htmlspecialchars(urlencode($target_role)) : '' ?>" method="POST">
                
                <div class="input-group">
                    <label for="identifier">Staff Phone Number or User ID</label>
                    <input type="text" name="identifier" id="identifier" placeholder="e.g. 0700000000, 0700000001..." value="<?= htmlspecialchars($saved_identifier) ?>" required>
                </div>

                <div class="input-group">
                    <label for="password">Staff Password</label>
                    <input type="password" name="password" id="password" placeholder="Enter your staff password" required>
                </div>

                <button type="submit" class="btn-submit">
                    <i class="fas fa-lock"></i> Authenticate Staff
                </button>

            </form>

            <div class="option-links">
                <p><a href="../login.php"><i class="fas fa-arrow-left"></i> Return to Customer Login</a></p>
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