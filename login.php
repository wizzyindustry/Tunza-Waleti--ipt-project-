<?php
// login.php
session_start();
require_once 'database/config.php';

// Normalize PDO connection handle
if (!isset($pdo) && isset($conn)) {
    $pdo = $conn;
}

// 1. Determine active context before initializing session to avoid session collision
$target_role = $_GET['role'] ?? 'user';

// If a staff actor role is targeted directly via login.php, redirect to admin/login.php
if (in_array($target_role, ['admin', 'customer_care', 'transaction_officer'])) {
    header("Location: admin/login.php?role=" . urlencode($target_role));
    exit();
}

// 2. Automated Redirection if already authenticated in current session context
if (isset($_SESSION['user_id'])) {
    header("Location: pages/dashboard.php");
    exit();
} elseif (isset($_SESSION['admin_id'])) {
    header("Location: admin/dashboard.php");
    exit();
} elseif (isset($_SESSION['care_id'])) {
    header("Location: admin/customer_care/dashboard.php");
    exit();
} elseif (isset($_SESSION['finance_id'])) {
    header("Location: admin/transaction_officer/dashboard.php");
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
        // Fallback silently if table does not exist
    }
}

// Retrieve flash messages
$error = $_SESSION['login_error'] ?? '';
$saved_identifier = $_SESSION['saved_identifier'] ?? '';
unset($_SESSION['login_error'], $_SESSION['saved_identifier']);

// 4. HANDLE POST LOGIN FOR CUSTOMERS
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifier = trim($_POST['identifier'] ?? ''); 
    $password   = $_POST['password'] ?? '';

    if (empty($identifier) || empty($password)) {
        $_SESSION['login_error'] = "Please enter both phone number/ID and password.";
        $_SESSION['saved_identifier'] = $identifier;
        header("Location: login.php");
        exit();
    } else {
        try {
            // Standardize local phone format (e.g. 0793085794 -> 255793085794)
            $formattedPhone = preg_replace('/[^0-9]/', '', $identifier);
            if (strpos($formattedPhone, '0') === 0) {
                $formattedPhone = '255' . substr($formattedPhone, 1);
            }

            // Flexible query checking phone number, raw input, email, or numeric ID
            $stmt = $pdo->prepare("
                SELECT * 
                FROM users 
                WHERE phone_number = :phone_formatted 
                   OR phone_number = :phone_raw 
                   OR id = :id_raw 
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
                    case 'user':
                        session_name('TUNZA_USER_SESSION');
                        session_start();
                        session_regenerate_id(true);

                        $_SESSION['user_id']    = $user['id'];
                        $_SESSION['user_name']  = $user['user_name'] ?? $user['full_name'];
                        $_SESSION['user_phone'] = $user['phone_number'] ?? 'user';
                        $_SESSION['user_role']  = 'user';

                        header("location: pages/dashboard.php");
                        break;
                    case 'admin':
                        session_name('TUNZA_MAIN_ADMIN_SESSION');
                        session_start();
                        session_regenerate_id(true);

                        $_SESSION['admin_id']    = $user['id'];
                        $_SESSION['admin_name']  = $user['full_name'] ?? 'Administrator';
                        $_SESSION['admin_phone'] = $user['phone_number'] ?? '';
                        $_SESSION['admin_role']  = 'admin';

                        header("Location: admin/dashboard.php");
                        break;

                    case 'customer_care':
                        session_name('TUNZA_CARE_OFFICER_SESSION');
                        session_start();
                        session_regenerate_id(true);

                        $_SESSION['care_id']    = $user['id'];
                        $_SESSION['care_name']  = $user['full_name'] ?? 'Care Officer';
                        $_SESSION['care_phone'] = $user['phone_number'] ?? '';
                        $_SESSION['care_role']  = 'customer_care';

                        header("Location: admin/customer_care/dashboard.php");
                        break;

                    case 'transaction_officer':
                        session_name('TUNZA_FINANCE_OFFICER_SESSION');
                        session_start();
                        session_regenerate_id(true);

                        $_SESSION['finance_id']    = $user['id'];
                        $_SESSION['finance_name']  = $user['full_name'] ?? 'Finance Officer';
                        $_SESSION['finance_phone'] = $user['phone_number'] ?? '';
                        $_SESSION['finance_role']  = 'transaction_officer';

                        header("Location: admin/transaction_officer/dashboard.php");
                        break;

                    default:
                        $_SESSION['login_error'] = "Unauthorized role type for portal access.";
                        header("Location: login.php");
                        break;
                }
                exit();
            } else {
                $_SESSION['login_error'] = "Invalid credentials. Please check your phone/ID and password.";
                $_SESSION['saved_identifier'] = $identifier;
                header("Location: login.php");
                exit();
            }
        } catch (PDOException $e) {
            $_SESSION['login_error'] = "Database Connection Error: " . $e->getMessage();
            $_SESSION['saved_identifier'] = $identifier;
            header("Location: login.php");
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
    <title>Customer Login | Tunza Waleti</title>
    <link rel="stylesheet" href="assets/style/style2.css">
    <link rel="shortcut icon" href="<?= htmlspecialchars($site_logo) ?>" type="image/x-icon">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-purple: #510049;
            --dark-purple: #3d0037;
            --emerald-green: #117864;
            --bg-light: #f8f9fa;
        }

        body {
            margin: 0;
            padding: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f4e8f3;
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
            box-shadow: 0 15px 35px rgba(81, 0, 73, 0.15);
            overflow: hidden;
            margin: 20px;
        }

        /* Left Side: Form Panel */
        .login-left-panel {
            flex: 1;
            padding: 50px 40px;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .brand-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 30px;
        }

        .brand-header img {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            object-fit: cover;
        }

        .brand-header h2 {
            margin: 0;
            color: var(--primary-purple);
            font-size: 24px;
            font-weight: 800;
        }

        .login-title h3 {
            margin: 0 0 8px 0;
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
        }

        .btn-submit:hover {
            background: var(--dark-purple);
        }

        .option-links {
            margin-top: 20px;
            text-align: center;
            font-size: 13px;
        }

        .option-links a {
            color: var(--primary-purple);
            text-decoration: none;
            font-weight: 600;
        }

        .option-links a:hover {
            text-decoration: underline;
        }

        /* Right Side: Description Panel */
        .description-right-panel {
            flex: 1.1;
            background: linear-gradient(135deg, var(--dark-purple) 0%, var(--emerald-green) 100%);
            color: #ffffff;
            padding: 60px 45px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            position: relative;
        }

        .description-right-panel h1 {
            font-size: 32px;
            font-weight: 800;
            margin-bottom: 15px;
            line-height: 1.3;
        }

        .description-right-panel p.lead-desc {
            font-size: 15px;
            line-height: 1.6;
            opacity: 0.9;
            margin-bottom: 35px;
        }

        .feature-item {
            display: flex;
            align-items: flex-start;
            gap: 15px;
            margin-bottom: 25px;
        }

        .feature-icon {
            width: 42px;
            height: 42px;
            background: rgba(255, 255, 255, 0.15);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            flex-shrink: 0;
        }

        .feature-text h4 {
            margin: 0 0 4px 0;
            font-size: 16px;
            font-weight: 700;
        }

        .feature-text p {
            margin: 0;
            font-size: 13px;
            opacity: 0.8;
            line-height: 1.5;
        }

        /* Responsive Breakpoints */
        @media (max-width: 850px) {
            .page-split-container {
                flex-direction: column;
                margin: 10px;
            }
            .description-right-panel {
                padding: 40px 25px;
            }
            .login-left-panel {
                padding: 40px 25px;
            }
        }
    </style>
</head>

<body>

    <div class="page-split-container">
        
        <!-- LEFT PANEL: LOGIN FORM -->
        <div class="login-left-panel">
            <div class="brand-header">
                <img src="<?= htmlspecialchars($site_logo) ?>" alt="Tunza Waleti Logo">
                <h2>Tunza Waleti</h2>
            </div>

            <div class="login-title">
                <h3>Welcome Back</h3>
                <p>Sign in to access your wallet and target savings goals.</p>
            </div>

            <?php if (!empty($error)): ?>
                <div style="padding: 12px; background: #fce4e4; color: #c0392b; border-radius: 8px; margin-bottom: 20px; font-size: 13px; text-align: center;">
                    <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <form action="login.php" method="POST">
                <div class="input-group">
                    <label for="identifier">Phone Number, Email or User ID</label>
                    <input type="text" name="identifier" id="identifier" placeholder="e.g. 255793085794 or User ID" value="<?= htmlspecialchars($saved_identifier) ?>" required>
                </div>

                <div class="input-group">
                    <label for="password">Password</label>
                    <input type="password" name="password" id="password" placeholder="Enter your password" required>
                </div>

                <button type="submit" class="btn-submit">Sign In to Account</button>
            </form>

            <div class="option-links">
                <p>Don't have an account? <a href="register.php">Create Free Account</a></p>
                <p style="margin-top: 12px;">
                    <a href="admin/login.php" style="color: #666;"><i class="fas fa-user-shield"></i> Staff & Admin Portal</a>
                </p>
            </div>
        </div>

        <!-- RIGHT PANEL: TUNZA WALETI DESCRIPTION -->
        <div class="description-right-panel">
            <h1>Digital Saving & Target Management</h1>
            <p class="lead-desc">
                Tunza Waleti gives you complete financial discipline by separating your daily spending money from your dedicated long-term savings goals.
            </p>

            <div class="feature-item">
                <div class="feature-icon"><i class="fas fa-wallet"></i></div>
                <div class="feature-text">
                    <h4>Dual-Wallet Architecture</h4>
                    <p>Isolate liquid operational funds from your earmarked goal savings so you never overspend accidentally.</p>
                </div>
            </div>

            <div class="feature-item">
                <div class="feature-icon"><i class="fas fa-bullseye"></i></div>
                <div class="feature-text">
                    <h4>Target Savings Control</h4>
                    <p>Create dedicated savings buckets with clear financial targets, automated progress tracking, and fixed deadlines.</p>
                </div>
            </div>

            <div class="feature-item">
                <div class="feature-icon"><i class="fas fa-shield-alt"></i></div>
                <div class="feature-text">
                    <h4>Real-Time Ledger Accounting</h4>
                    <p>Every internal transfer, deposit, and goal allocation is logged with full atomic accuracy and security.</p>
                </div>
            </div>
        </div>

    </div>

    <!-- Client-side Cross-Tab Sync Script -->
    <script>
        window.addEventListener('storage', function(event) {
            if (event.key === 'tunza_session_update') {
                const sessionData = JSON.parse(event.newValue);
                if (sessionData && sessionData.role === 'admin') {
                    window.location.href = 'admin/dashboard.php';
                } else if (sessionData && sessionData.role === 'customer_care') {
                    window.location.href = 'admin/customer_care/dashboard.php';
                } else if (sessionData && sessionData.role === 'transaction_officer') {
                    window.location.href = 'admin/transaction_officer/dashboard.php';
                } else if (sessionData && sessionData.role === 'user') {
                    window.location.href = 'pages/dashboard.php';
                }
            }
        });

        <?php if (isset($_SESSION['user_id'])): ?>
            localStorage.setItem('tunza_session_update', JSON.stringify({
                user_id: '<?= $_SESSION['user_id'] ?>',
                role: '<?= $_SESSION['user_role'] ?>',
                time: Date.now()
            }));
        <?php endif; ?>
    </script>

</body>
</html>