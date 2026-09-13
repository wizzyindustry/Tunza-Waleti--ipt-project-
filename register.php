<?php
// register.php

// 1. Set isolated user session name BEFORE starting session to prevent session collision
session_name('TUNZA_USER_SESSION');
session_start();

require_once 'database/config.php'; // Ensure database connection configuration is included

// Normalize database connection handle
if (!isset($pdo) && isset($conn)) {
    $pdo = $conn;
} elseif (isset($pdo) && !isset($conn)) {
    $conn = $pdo;
}

// Redirect if user is already logged in as a standard user
if (isset($_SESSION['user_id']) && ($_SESSION['user_role'] ?? 'user') === 'user') {
    header("Location: pages/dashboard.php");
    exit();
}

$error = '';
$success = '';

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

// 3. Process Registration
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name    = trim($_POST['fname'] ?? '');
    $phone_number = trim($_POST['phone'] ?? '');
    $password     = $_POST['Cpassd'] ?? '';
    $confirm_pwd  = $_POST['Conferm'] ?? '';

    // Validation
    if (empty($full_name) || empty($phone_number) || empty($password) || empty($confirm_pwd)) {
        $error = "All fields are required.";
    } elseif ($password !== $confirm_pwd) {
        $error = "Passwords do not match.";
    } elseif (strlen($password) < 6) {
        $error = "Password must be at least 6 characters long.";
    } else {
        try {
            // Check if phone number already exists
            $stmt = $pdo->prepare("SELECT id FROM users WHERE phone_number = :phone LIMIT 1");
            $stmt->execute(['phone' => $phone_number]);

            if ($stmt->rowCount() > 0) {
                $error = "An account with this phone number already exists.";
            } else {
                // Begin database transaction
                $pdo->beginTransaction();

                // Hash password securely
                $hashed_password = password_hash($password, PASSWORD_BCRYPT);

                // Insert User Record
                $insertUser = $pdo->prepare("
                    INSERT INTO users (full_name, phone_number, password, role) 
                    VALUES (:full_name, :phone_number, :password, 'user')
                ");
                $insertUser->execute([
                    'full_name'    => $full_name,
                    'phone_number' => $phone_number,
                    'password'     => $hashed_password
                ]);

                $user_id = $pdo->lastInsertId();

                // Create associated Wallet with initial balance 0.00
                $insertWallet = $pdo->prepare("
                    INSERT INTO wallets (user_id, balance, currency) 
                    VALUES (:user_id, 0.00, 'TZS')
                ");
                $insertWallet->execute(['user_id' => $user_id]);

                // Commit transaction
                $pdo->commit();

                // Store flash message in session and redirect directly to login page
                $_SESSION['success_msg'] = "Registration successful! Please log in to continue.";
                header("Location: login.php");
                exit();
            }
        } catch (Exception $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = "System error occurred during registration. Please try again.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register | Tunza Waleti</title>
    <link rel="stylesheet" href="assets/style/style2.css">
    <link rel="shortcut icon" href="<?= htmlspecialchars($site_logo) ?>" type="image/x-icon">
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

        <div class="register-container">

            <!-- Flash Notifications -->
            <?php if (!empty($error)): ?>
                <div class="alert error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <?php if (!empty($success)): ?>
                <div class="alert success"><?= htmlspecialchars($success) ?></div>
            <?php endif; ?>

            <div class="form-container">
                <!-- Single form wrapping all inputs -->
                <form action="register.php" method="POST" id="regForm">
                    
                    <!-- STEP 1 -->
                    <div class="form-step active" id="step-1">
                        <div class="input-group">
                            <label for="fname">Full Name</label>
                            <input type="text" name="fname" id="fname" placeholder="Enter your full name" value="<?= htmlspecialchars($_POST['fname'] ?? '') ?>" required>
                        </div>

                        <div class="input-group">
                            <label for="phone">Phone Number</label>
                            <input type="text" name="phone" id="phone" placeholder="+255 7XX XXX XXX" value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>" required>
                        </div>

                        <div class="btn">
                            <button type="button" onclick="goToStep(2)">Continue</button>
                        </div>

                        <div class="option">
                            <p>Already have an account? <a href="login.php">Login</a></p>
                        </div>
                    </div>

                    <!-- STEP 2 -->
                    <div class="form-step" id="step-2">
                        <div class="input-group">
                            <label for="Cpassd">Create Password</label>
                            <input type="password" name="Cpassd" id="Cpassd" placeholder="Create password (min. 6 chars)" required>
                        </div>

                        <div class="input-group">
                            <label for="Conferm">Confirm Password</label>
                            <input type="password" name="Conferm" id="Conferm" placeholder="Confirm password" required>
                        </div>

                        <div class="btn">
                            <button type="submit">Create Account</button>
                        </div>

                        <div class="btn-secondary">
                            <button type="button" onclick="goToStep(1)">Back</button>
                        </div>

                        <div class="option">
                            <p>Already have an account? <a href="login.php">Login</a></p>
                        </div>
                    </div>

                </form>
            </div>
        </div>
    </div>

    <!-- Client-side step navigation and session cross-tab sync -->
    <script>
        function goToStep(step) {
            const step1 = document.getElementById('step-1');
            const step2 = document.getElementById('step-2');

            if (step === 2) {
                const fname = document.getElementById('fname').value.trim();
                const phone = document.getElementById('phone').value.trim();

                if (!fname || !phone) {
                    alert('Please complete your name and phone number first.');
                    return;
                }

                step1.classList.remove('active');
                step2.classList.add('active');
            } else {
                step2.classList.remove('active');
                step1.classList.add('active');
            }
        }

        window.addEventListener('storage', function(event) {
            if (event.key === 'tunza_session_update') {
                const sessionData = JSON.parse(event.newValue);
                if (sessionData && sessionData.role === 'admin') {
                    window.location.href = 'admin/dashboard.php';
                }
            }
        });
    </script>
</body>

</html>