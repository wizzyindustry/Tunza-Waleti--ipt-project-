<?php
// login.php

// 1. Determine active context before initializing session to avoid session collision
$target_role = $_GET['role'] ?? 'user';

if ($target_role === 'admin') {
    session_name('TUNZA_ADMIN_SESSION');
} else {
    session_name('TUNZA_USER_SESSION');
}

session_start();
require_once 'database/config.php';

// Normalize PDO connection handle
if (!isset($pdo) && isset($conn)) {
    $pdo = $conn;
}

// 2. Redirect if already authenticated in the current session context
if (isset($_SESSION['user_id'])) {
    if (($_SESSION['user_role'] ?? '') === 'admin') {
        header("Location: admin/dashboard.php");
    } else {
        header("Location: pages/dashboard.php");
    }
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

// 4. HANDLE POST LOGIN
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifier = trim($_POST['identifier'] ?? ''); 
    $password   = $_POST['password'] ?? '';

    if (empty($identifier) || empty($password)) {
        $_SESSION['login_error'] = "Please enter both phone number/ID and password.";
        $_SESSION['saved_identifier'] = $identifier;
        header("Location: login.php" . ($target_role === 'admin' ? '?role=admin' : ''));
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
                
                // If user is admin, destroy standard session and switch to Admin Session Cookie
                if (($user['role'] ?? 'user') === 'admin') {
                    session_destroy(); // Destroy previous user session context
                    session_name('TUNZA_ADMIN_SESSION');
                    session_start();
                } else {
                    session_destroy(); // Destroy previous admin session context
                    session_name('TUNZA_USER_SESSION');
                    session_start();
                }

                session_regenerate_id(true);

                // Set session variables
                $_SESSION['user_id']    = $user['id'];
                $_SESSION['user_name']  = $user['full_name'] ?? 'User';
                $_SESSION['user_phone'] = $user['phone_number'] ?? '';
                $_SESSION['user_image'] = $user['profile_pic'] ?? 'default.png';
                $_SESSION['user_role']  = $user['role'] ?? 'user';

                // Route to appropriate dashboard
                if ($_SESSION['user_role'] === 'admin') {
                    header("Location: admin/dashboard.php");
                } else {
                    header("Location: pages/dashboard.php");
                }
                exit();
            } else {
                $_SESSION['login_error'] = "Invalid phone number, email, or password.";
                $_SESSION['saved_identifier'] = $identifier;
                header("Location: login.php" . ($target_role === 'admin' ? '?role=admin' : ''));
                exit();
            }
        } catch (PDOException $e) {
            $_SESSION['login_error'] = "Database Connection Error: " . $e->getMessage();
            $_SESSION['saved_identifier'] = $identifier;
            header("Location: login.php" . ($target_role === 'admin' ? '?role=admin' : ''));
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
    <title>Login | Tunza Waleti</title>
    <link rel="stylesheet" href="assets/style/style2.css">
    <link rel="shortcut icon" href="<?= htmlspecialchars($site_logo) ?>" type="image/x-icon">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>

<body>

    <div class="main-wrapper">
        <div class="logo">
            <img src="<?= htmlspecialchars($site_logo) ?>" alt="Tunza Waleti Logo">
        </div>

        <div class="title">
            <h2>Tunza Waleti</h2>
            <p class="subtitle">Digital Saving Management System</p>
        </div>

        <div class="das">
            <div class="dash-1"></div>
            <div class="dash-2"></div>
        </div>

        <div class="login-container">

            <?php if (!empty($error)): ?>
                <div class="alert error" style="padding: 12px; background: #fce4e4; color: #c0392b; border-radius: 8px; margin-bottom: 15px; font-size: 14px; text-align: center;">
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <div class="form-container">
                <form action="login.php<?= $target_role === 'admin' ? '?role=admin' : '' ?>" method="POST">
                    
                    <div class="input-group">
                        <label for="identifier">Phone Number, Email or User ID</label>
                        <input type="text" name="identifier" id="identifier" placeholder="e.g. 255793085794 or 212" value="<?= htmlspecialchars($saved_identifier) ?>" required>
                    </div>

                    <div class="input-group">
                        <label for="password">Password</label>
                        <input type="password" name="password" id="password" placeholder="Enter your password" required>
                    </div>

                    <div class="btn">
                        <button type="submit">Login</button>
                    </div>

                    <div class="option">
                        <p>Don't have an account? <a href="register.php">Register here</a></p>
                    </div>

                </form>
            </div>
        </div>
    </div>

    <!-- Client-side Cross-Tab Sync Script -->
    <script>
        // Broadcast login events across tabs so open pages update automatically without corrupting sessions
        window.addEventListener('storage', function(event) {
            if (event.key === 'tunza_session_update') {
                const sessionData = JSON.parse(event.newValue);
                if (sessionData && sessionData.role === 'admin') {
                    window.location.href = 'admin/dashboard.php';
                } else if (sessionData && sessionData.role === 'user') {
                    window.location.href = 'pages/dashboard.php';
                }
            }
        });

        // Broadcast current login role on successful submit
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