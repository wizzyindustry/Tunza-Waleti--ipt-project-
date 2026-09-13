<?php
// pages/logout.php

// 1. Set isolated session name BEFORE starting session to prevent cross-tab collision
session_name('TUNZA_USER_SESSION');
session_start();

require_once '../database/auth.php'; // Ensures session exists
require_once '../database/config.php';

// Normalize PDO connection handle
if (!isset($pdo) && isset($conn)) {
    $pdo = $conn;
}

// 2. Fetch system logo dynamically safely
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

// Check if user confirmed logout via POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_logout'])) {
    // Unset all session variables
    $_SESSION = array();

    // Destroy session cookie if set
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params["path"],
            $params["domain"],
            $params["secure"],
            $params["httponly"]
        );
    }

    // Destroy the server session
    session_destroy();

    // Redirect safely to login page in root directory
    header("Location: ../login.php");
    exit();
}

$user_name = $_SESSION['user_name'] ?? 'User';
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Logout | TUNZA WALETI</title>
    <link rel="shortcut icon" href="../<?= htmlspecialchars($site_logo) ?>" type="image/x-icon">
    <link rel="stylesheet" href="../assets/style/main.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .logout-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.55);
            backdrop-filter: blur(4px);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 9999;
        }

        .logout-modal {
            background-color: #ffffff;
            width: 90%;
            max-width: 380px;
            padding: 28px 22px;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
            text-align: center;
        }

        .logout-modal i.logout-icon {
            font-size: 42px;
            color: #e74c3c;
            margin-bottom: 12px;
        }

        .logout-modal h3 {
            color: #2c3e50;
            font-size: 20px;
            font-weight: 700;
            margin-bottom: 6px;
        }

        .logout-modal p {
            font-size: 14px;
            color: #7f8c8d;
            margin-bottom: 22px;
        }

        .logout-actions {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .logout-actions form {
            width: 100%;
        }

        .btn-danger button {
            width: 100%;
            padding: 12px;
            font-size: 15px;
            font-weight: 600;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            background-color: #e74c3c;
            color: #ffffff;
            transition: background 0.3s ease;
        }

        .btn-danger button:hover {
            background-color: #c0392b;
        }

        .btn-cancel a {
            display: block;
            width: 100%;
            padding: 12px;
            font-size: 15px;
            font-weight: 600;
            border-radius: 8px;
            text-decoration: none;
            background-color: #f1f2f6;
            color: #2c3e50;
            transition: background 0.3s ease;
            box-sizing: border-box;
        }

        .btn-cancel a:hover {
            background-color: #dfe4ea;
        }
    </style>
</head>

<body>

    <div class="logout-overlay">
        <div class="logout-modal">
            <i class="fas fa-sign-out-alt logout-icon"></i>
            <h3><?= htmlspecialchars($user_name) ?></h3>
            <p>Are you sure you want to log out of Tunza Waleti?</p>

            <div class="logout-actions">
                <!-- Submit form via POST to trigger server-side session cleanup -->
                <form action="logout.php" method="POST" id="logoutForm" onsubmit="clearClientSession()">
                    <input type="hidden" name="confirm_logout" value="1">
                    <div class="btn-danger">
                        <button type="submit">Yes, Logout</button>
                    </div>
                </form>

                <div class="btn-cancel">
                    <a href="dashboard.php">No, Stay Logged In</a>
                </div>
            </div>
        </div>
    </div>

    <!-- Client-side Cross-Tab Sync & Cleanup Script -->
    <script>
        function clearClientSession() {
            localStorage.removeItem('tunza_session_update');
        }

        window.addEventListener('storage', function(event) {
            if (event.key === 'tunza_session_update') {
                const sessionData = JSON.parse(event.newValue);
                if (sessionData && sessionData.role === 'admin') {
                    window.location.href = '../admin/dashboard.php';
                }
            }
        });
    </script>

</body>

</html>