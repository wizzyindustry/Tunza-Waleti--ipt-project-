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
        $error = "All fields are required to complete your registration.";
    } elseif ($password !== $confirm_pwd) {
        $error = "Passwords do not match. Please verify both password entries.";
    } elseif (strlen($password) < 6) {
        $error = "Password must be at least 6 characters long.";
    } else {
        try {
            // Standardize phone format if needed
            $formattedPhone = preg_replace('/[^0-9]/', '', $phone_number);
            if (strpos($formattedPhone, '0') === 0) {
                $formattedPhone = '255' . substr($formattedPhone, 1);
            }

            // Check if phone number already exists
            $stmt = $pdo->prepare("SELECT id FROM users WHERE phone_number = :phone OR phone_number = :raw_phone LIMIT 1");
            $stmt->execute(['phone' => $formattedPhone, 'raw_phone' => $phone_number]);

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
                    'phone_number' => $formattedPhone,
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
                $_SESSION['login_error'] = "Registration successful! Please log in to access your wallet.";
                header("Location: login.php");
                exit();
            }
        } catch (Exception $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = "System error occurred during registration: " . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Free Account | Tunza Waleti</title>
    <link rel="stylesheet" href="assets/style/style2.css">
    <link rel="shortcut icon" href="<?= htmlspecialchars($site_logo) ?>" type="image/x-icon">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-purple: #510049;
            --dark-purple: #3d0037;
            --emerald-green: #117864;
            --light-green: #27ae60;
            --danger-red: #e74c3c;
            --warning-amber: #f39c12;
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
            min-height: 680px;
            background: #ffffff;
            border-radius: 20px;
            box-shadow: 0 15px 35px rgba(81, 0, 73, 0.15);
            overflow: hidden;
            margin: 20px;
        }

        /* Left Side: Description & Registration Guide Panel */
        .description-left-panel {
            flex: 1.1;
            background: linear-gradient(135deg, var(--dark-purple) 0%, var(--emerald-green) 100%);
            color: #ffffff;
            padding: 50px 45px;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .brand-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 25px;
        }

        .brand-header img {
            width: 46px;
            height: 46px;
            border-radius: 50%;
            object-fit: cover;
        }

        .brand-header h2 {
            margin: 0;
            color: #ffffff;
            font-size: 24px;
            font-weight: 800;
        }

        .description-left-panel h1 {
            font-size: 30px;
            font-weight: 800;
            margin-bottom: 12px;
            line-height: 1.25;
        }

        .description-left-panel p.lead-desc {
            font-size: 14px;
            line-height: 1.6;
            opacity: 0.9;
            margin-bottom: 30px;
        }

        /* Steps Indicator Component */
        .registration-steps {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .step-guide-item {
            display: flex;
            align-items: flex-start;
            gap: 15px;
        }

        .step-number-badge {
            width: 36px;
            height: 36px;
            background: rgba(255, 255, 255, 0.2);
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 15px;
            font-weight: 800;
            flex-shrink: 0;
            transition: 0.3s;
        }

        .step-guide-item.active .step-number-badge {
            background: var(--light-green);
            border-color: var(--light-green);
        }

        .step-guide-text h4 {
            margin: 0 0 3px 0;
            font-size: 15px;
            font-weight: 700;
        }

        .step-guide-text p {
            margin: 0;
            font-size: 13px;
            opacity: 0.8;
            line-height: 1.4;
        }

        /* Right Side: Form Panel */
        .register-right-panel {
            flex: 1;
            padding: 50px 40px;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .register-title h3 {
            margin: 0 0 6px 0;
            font-size: 22px;
            color: var(--dark-purple);
        }

        .register-title p {
            margin: 0 0 25px 0;
            color: #666;
            font-size: 14px;
        }

        /* Form Wizard Steps */
        .form-step {
            display: none;
        }

        .form-step.active {
            display: block;
        }

        .input-group {
            margin-bottom: 18px;
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

        .btn-submit, .btn-next {
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

        .btn-submit:hover, .btn-next:hover {
            background: var(--dark-purple);
        }

        .btn-back {
            width: 100%;
            padding: 12px;
            background: transparent;
            color: #666;
            border: 1px solid #ccc;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            margin-top: 10px;
            transition: 0.3s;
        }

        .btn-back:hover {
            background: var(--bg-light);
            color: #333;
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

        /* ========================================== */
        /* POPUP DIALOG MODAL STYLES                  */
        /* ========================================== */
        .popup-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.55);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 2000;
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.3s ease, visibility 0.3s ease;
        }

        .popup-overlay.active {
            opacity: 1;
            visibility: visible;
        }

        .popup-box {
            background: #ffffff;
            width: 90%;
            max-width: 420px;
            border-radius: 16px;
            padding: 30px 25px;
            text-align: center;
            box-shadow: 0 20px 40px rgba(0,0,0,0.2);
            transform: scale(0.85);
            transition: transform 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        }

        .popup-overlay.active .popup-box {
            transform: scale(1);
        }

        .popup-icon {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            margin: 0 auto 18px auto;
        }

        .popup-icon.error { background: #fce4e4; color: var(--danger-red); }
        .popup-icon.warning { background: #fef5e7; color: var(--warning-amber); }
        .popup-icon.success { background: #e8f8f5; color: var(--emerald-green); }

        .popup-title {
            margin: 0 0 10px 0;
            font-size: 20px;
            color: var(--dark-purple);
            font-weight: 700;
        }

        .popup-message {
            margin: 0 0 25px 0;
            font-size: 14px;
            color: #666;
            line-height: 1.5;
        }

        .popup-btn {
            width: 100%;
            padding: 12px;
            border: none;
            background: var(--primary-purple);
            color: #ffffff;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            transition: 0.3s;
        }

        .popup-btn:hover {
            background: var(--dark-purple);
        }

        /* Responsive Breakpoints */
        @media (max-width: 850px) {
            .page-split-container {
                flex-direction: column;
                margin: 10px;
            }
            .description-left-panel {
                padding: 35px 25px;
            }
            .register-right-panel {
                padding: 35px 25px;
            }
        }
    </style>
</head>

<body>

    <div class="page-split-container">
        
        <!-- LEFT PANEL: DESCRIPTION & STEP GUIDE -->
        <div class="description-left-panel">
            <div class="brand-header">
                <img src="<?= htmlspecialchars($site_logo) ?>" alt="Tunza Waleti Logo">
                <h2>Tunza Waleti</h2>
            </div>

            <h1>Start Your Journey to Smart Financial Discipline</h1>
            <p class="lead-desc">
                Create your account in seconds to activate your primary digital wallet and set up target savings goals.
            </p>

            <div class="registration-steps">
                <div class="step-guide-item active" id="guide-step-1">
                    <div class="step-number-badge">1</div>
                    <div class="step-guide-text">
                        <h4>Personal & Contact Information</h4>
                        <p>Provide your full name and registered mobile phone number for wallet access.</p>
                    </div>
                </div>

                <div class="step-guide-item" id="guide-step-2">
                    <div class="step-number-badge">2</div>
                    <div class="step-guide-text">
                        <h4>Account Security Setup</h4>
                        <p>Define a strong password to safeguard your wallet funds and internal transactions.</p>
                    </div>
                </div>

                <div class="step-guide-item">
                    <div class="step-number-badge"><i class="fas fa-check" style="font-size: 12px;"></i></div>
                    <div class="step-guide-text">
                        <h4>Instant Wallet Provisioning</h4>
                        <p>Your primary wallet and target tracking dashboard activate immediately upon submission.</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- RIGHT PANEL: REGISTRATION WIZARD FORM -->
        <div class="register-right-panel">
            <div class="register-title">
                <h3>Create Account</h3>
                <p>Fill out the details below to open your free account.</p>
            </div>

            <form action="register.php" method="POST" id="regForm">
                
                <!-- STEP 1: PERSONAL DETAILS -->
                <div class="form-step active" id="step-1">
                    <div class="input-group">
                        <label for="fname">Full Name</label>
                        <input type="text" name="fname" id="fname" placeholder="Enter your full name" value="<?= htmlspecialchars($_POST['fname'] ?? '') ?>" required>
                    </div>

                    <div class="input-group">
                        <label for="phone">Phone Number</label>
                        <input type="text" name="phone" id="phone" placeholder="e.g. 0793085794 or 255793085794" value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>" required>
                    </div>

                    <button type="button" class="btn-next" onclick="goToStep(2)">Continue to Security <i class="fas fa-arrow-right"></i></button>

                    <div class="option-links">
                        <p>Already have an account? <a href="login.php">Sign In here</a></p>
                    </div>
                </div>

                <!-- STEP 2: PASSWORD SECURITY -->
                <div class="form-step" id="step-2">
                    <div class="input-group">
                        <label for="Cpassd">Create Password</label>
                        <input type="password" name="Cpassd" id="Cpassd" placeholder="Create password (min. 6 chars)" required>
                    </div>

                    <div class="input-group">
                        <label for="Conferm">Confirm Password</label>
                        <input type="password" name="Conferm" id="Conferm" placeholder="Confirm password" required>
                    </div>

                    <button type="submit" class="btn-submit"><i class="fas fa-user-plus"></i> Complete Registration</button>
                    <button type="button" class="btn-back" onclick="goToStep(1)"><i class="fas fa-arrow-left"></i> Back to Step 1</button>

                    <div class="option-links">
                        <p>Already have an account? <a href="login.php">Sign In here</a></p>
                    </div>
                </div>

            </form>
        </div>

    </div>

    <!-- REUSABLE POPUP DIALOG MODAL -->
    <div class="popup-overlay" id="popupModal">
        <div class="popup-box">
            <div class="popup-icon warning" id="popupIcon">
                <i class="fas fa-exclamation-triangle"></i>
            </div>
            <h3 class="popup-title" id="popupTitle">Attention Required</h3>
            <p class="popup-message" id="popupMessage">Please complete all required fields.</p>
            <button class="popup-btn" onclick="closePopup()">Dismiss</button>
        </div>
    </div>

    <!-- CLIENT-SIDE SCRIPT ENGINE -->
    <script>
        // Modal Control Engine
        function showPopup(title, message, type = 'warning') {
            const modal = document.getElementById('popupModal');
            const iconContainer = document.getElementById('popupIcon');
            const titleElem = document.getElementById('popupTitle');
            const msgElem = document.getElementById('popupMessage');

            titleElem.textContent = title;
            msgElem.textContent = message;

            // Reset icon classes
            iconContainer.className = 'popup-icon ' + type;
            if (type === 'error') {
                iconContainer.innerHTML = '<i class="fas fa-times-circle"></i>';
            } else if (type === 'success') {
                iconContainer.innerHTML = '<i class="fas fa-check-circle"></i>';
            } else {
                iconContainer.innerHTML = '<i class="fas fa-exclamation-triangle"></i>';
            }

            modal.classList.add('active');
        }

        function closePopup() {
            document.getElementById('popupModal').classList.remove('active');
        }

        // Wizard Navigation Engine with Modal Trigger
        function goToStep(step) {
            const step1 = document.getElementById('step-1');
            const step2 = document.getElementById('step-2');
            const guide1 = document.getElementById('guide-step-1');
            const guide2 = document.getElementById('guide-step-2');

            if (step === 2) {
                const fname = document.getElementById('fname').value.trim();
                const phone = document.getElementById('phone').value.trim();

                if (!fname || !phone) {
                    showPopup('Missing Details', 'Please enter your full name and phone number before proceeding.', 'warning');
                    return;
                }

                step1.classList.remove('active');
                step2.classList.add('active');

                if (guide1 && guide2) {
                    guide1.classList.remove('active');
                    guide2.classList.add('active');
                }
            } else {
                step2.classList.remove('active');
                step1.classList.add('active');

                if (guide1 && guide2) {
                    guide2.classList.remove('active');
                    guide1.classList.add('active');
                }
            }
        }

        // Trigger Server Errors as Popup Dialog on Load if Present
        <?php if (!empty($error)): ?>
            document.addEventListener('DOMContentLoaded', function() {
                showPopup('Registration Alert', <?= json_encode($error) ?>, 'error');
            });
        <?php endif; ?>

        // Sync Tab Navigation
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