<?php
// pages/add_goal.php

// 1. Set isolated session name BEFORE starting session to prevent cross-tab collision
session_name('TUNZA_USER_SESSION');
session_start();

require_once '../database/auth.php'; // Ensures the user is logged in
require_once '../database/config.php';

// Normalize PDO handle
if (!isset($pdo) && isset($conn)) {
    $pdo = $conn;
}

// Guarantee active user authentication & role routing
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

if (($_SESSION['user_role'] ?? 'user') === 'admin') {
    header("Location: ../admin/dashboard.php");
    exit();
}

$user_id   = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'] ?? 'User';
$error     = '';
$success   = '';

// 2. Fetch system logo dynamically
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

// 3. Fetch user profile picture for top header
try {
    $picStmt = $pdo->prepare("SELECT profile_pic FROM users WHERE id = :user_id LIMIT 1");
    $picStmt->execute(['user_id' => $user_id]);
    $userRow = $picStmt->fetch(PDO::FETCH_ASSOC);

    $pic_filename = !empty($userRow['profile_pic']) ? $userRow['profile_pic'] : 'default.png';

    if (file_exists('../assets/uploads/' . $pic_filename) && $pic_filename !== 'default.png') {
        $header_avatar = '../assets/uploads/' . $pic_filename;
    } else {
        $header_avatar = '../' . $site_logo;
    }
} catch (PDOException $e) {
    $header_avatar = '../' . $site_logo;
}

// 4. Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title          = trim($_POST['title'] ?? '');
    $target_amount  = filter_var($_POST['target_amount'] ?? 0, FILTER_VALIDATE_FLOAT);
    $initial_amount = filter_var($_POST['initial_amount'] ?? 0, FILTER_VALIDATE_FLOAT);
    $duration_value = filter_var($_POST['duration_value'] ?? 1, FILTER_VALIDATE_INT);
    $duration_type  = trim($_POST['duration_type'] ?? 'months');

    // Initial amount defaults to 0.00 if omitted or invalid
    if ($initial_amount === false || $initial_amount < 0) {
        $initial_amount = 0.00;
    }

    // Validate Duration
    $allowed_types = ['days', 'weeks', 'months', 'years'];
    if (!in_array($duration_type, $allowed_types)) {
        $duration_type = 'months';
    }
    if ($duration_value === false || $duration_value <= 0) {
        $duration_value = 1;
    }

    // Calculate Target End Date based on duration
    $target_date = date('Y-m-d H:i:s', strtotime("+{$duration_value} {$duration_type}"));

    // Input Validation
    if (empty($title)) {
        $error = 'Please enter a goal title (e.g., House Rent, School Fees).';
    } elseif ($target_amount === false || $target_amount <= 0) {
        $error = 'Please enter a valid target amount greater than zero.';
    } elseif ($initial_amount > $target_amount) {
        $error = 'Initial deposit amount cannot be greater than the target amount.';
    } else {
        try {
            // Check wallet balance if initial amount > 0
            if ($initial_amount > 0) {
                $walletStmt = $pdo->prepare("SELECT balance FROM wallets WHERE user_id = :user_id LIMIT 1");
                $walletStmt->execute(['user_id' => $user_id]);
                $wallet = $walletStmt->fetch(PDO::FETCH_ASSOC);

                $wallet_balance = (float)($wallet['balance'] ?? 0);

                if ($wallet_balance < $initial_amount) {
                    $error = 'Insufficient main wallet balance for the initial deposit. Available balance: TZS ' . number_format($wallet_balance, 2);
                }
            }

            if (empty($error)) {
                $pdo->beginTransaction();

                // Dynamically detect column availability in savings_goals
                $hasTargetDate    = false;
                $hasDurationVal   = false;
                $hasDurationType  = false;

                try {
                    $cols = $pdo->query("SHOW COLUMNS FROM savings_goals")->fetchAll(PDO::FETCH_COLUMN);
                    $hasTargetDate   = in_array('target_date', $cols) || in_array('deadline', $cols);
                    $hasDurationVal  = in_array('duration_value', $cols);
                    $hasDurationType = in_array('duration_type', $cols);
                } catch (PDOException $e) {}

                // Build insert query
                $fields = ['user_id', 'title', 'target_amount', 'current_amount'];
                $params = [
                    ':user_id'        => $user_id,
                    ':title'          => $title,
                    ':target_amount'  => $target_amount,
                    ':current_amount' => $initial_amount
                ];

                if ($hasTargetDate) {
                    $dateCol = in_array('target_date', $cols) ? 'target_date' : 'deadline';
                    $fields[] = $dateCol;
                    $params[':' . $dateCol] = $target_date;
                }
                if ($hasDurationVal) {
                    $fields[] = 'duration_value';
                    $params[':duration_value'] = $duration_value;
                }
                if ($hasDurationType) {
                    $fields[] = 'duration_type';
                    $params[':duration_type'] = $duration_type;
                }

                $sql = "INSERT INTO savings_goals (" . implode(', ', $fields) . ") VALUES (" . implode(', ', array_keys($params)) . ")";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);

                // If initial deposit allocated, deduct from wallet and record transaction
                if ($initial_amount > 0) {
                    $deductStmt = $pdo->prepare("UPDATE wallets SET balance = balance - :amount WHERE user_id = :user_id");
                    $deductStmt->execute(['amount' => $initial_amount, 'user_id' => $user_id]);

                    $ref_no = 'GLD-' . strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 8));
                    $txnStmt = $pdo->prepare("
                        INSERT INTO transactions (user_id, reference_no, type, amount, status, payment_method, description, created_at) 
                        VALUES (:user_id, :ref, 'savings', :amount, 'completed', 'Wallet Balance', :desc, NOW())
                    ");
                    $txnStmt->execute([
                        'user_id' => $user_id,
                        'ref'     => $ref_no,
                        'amount'  => $initial_amount,
                        'desc'    => 'Initial funding for goal: ' . $title
                    ]);
                }

                $pdo->commit();

                $_SESSION['success_msg'] = 'Target goal created successfully!';

                if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                    header('Content-Type: application/json');
                    echo json_encode(['status' => 'success', 'message' => 'Goal created successfully!']);
                    exit();
                }

                header('Location: dashboard.php');
                exit();
            }

        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = 'Database error: ' . $e->getMessage();
        }
    }

    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => $error]);
        exit();
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Savings Goal | Tunza Waleti</title>
    <link rel="stylesheet" href="../assets/style/main.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/style/media.css">
    <link rel="shortcut icon" href="../<?= htmlspecialchars($site_logo) ?>" type="image/x-icon">
    <script src="../assets/js/main.js" defer></script>

    <style>
        @import url('https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap');

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }

        body {
            background-color: #f4f7f6;
            min-height: 100vh;
            color: #2c3e50;
        }

        .goal-page-wrapper {
            width: 100%;
            max-width: 520px;
            margin: 40px auto;
            background: #ffffff;
            padding: 35px 28px;
            border-radius: 14px;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.08);
            text-align: center;
        }

        /* Logo & Headers */
        .logo {
            display: flex;
            justify-content: center;
            margin-bottom: 15px;
        }

        .logo img {
            width: 90px;
            height: 90px;
            border-radius: 50%;
            object-fit: cover;
        }

        .title h2 {
            color: #1a252f;
            font-size: 24px;
            font-weight: 700;
        }

        .subtitle {
            font-size: 13px;
            color: #7f8c8d;
            margin-top: 4px;
        }

        /* Decorative Dash Line */
        .das {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 6px;
            margin: 12px 0 25px 0;
        }

        .dash-1 {
            width: 40px;
            height: 4px;
            background-color: #510049;
            border-radius: 2px;
        }

        .dash-2 {
            width: 12px;
            height: 4px;
            background-color: #27ae60;
            border-radius: 2px;
        }

        /* Alert Banners */
        .alert.error {
            background-color: #fce4e4;
            color: #c0392b;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 13px;
            text-align: left;
            border: 1px solid #f5c6cb;
        }

        /* Form Control Layouts */
        .input-group {
            text-align: left;
            margin-bottom: 18px;
        }

        .input-group label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #34495e;
            margin-bottom: 6px;
        }

        .input-group input, .input-group select {
            width: 100%;
            height: 46px;
            padding: 10px 14px;
            border: 1.5px solid #dcdde1;
            border-radius: 8px;
            font-size: 14px;
            outline: none;
            transition: border-color 0.3s ease;
            background-color: #fff;
        }

        .input-group input:focus, .input-group select:focus {
            border-color: #510049;
            box-shadow: 0 0 0 3px rgba(81, 0, 73, 0.1);
        }

        /* Dual Input Row for Time Duration */
        .duration-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        .target-date-preview {
            background: #eaf2f8;
            color: #2980b9;
            padding: 10px 14px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 500;
            margin-top: -6px;
            margin-bottom: 18px;
            text-align: left;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* Action Buttons */
        .btn button {
            width: 100%;
            height: 48px;
            background-color: #510049;
            color: #ffffff;
            border: none;
            border-radius: 8px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.3s ease;
        }

        .btn button:hover {
            background-color: #000000;
        }

        .option {
            background-color: #d8d1d2;
            padding: 12px;
            border-radius: 10px;
            margin-top: 22px;
        }

        .option a {
            color: #510049;
            text-decoration: none;
            font-weight: 700;
        }

        .option a:hover {
            text-decoration: underline;
        }
    </style>
</head>

<body>
    <!-- ===== HEADER ===== -->
    <header class="header">
        <div class="user-profile">
            <img src="<?= htmlspecialchars($header_avatar) ?>" alt="user-image" style="object-fit: cover; border-radius: 50%;">
            <div class="user-info">
                <h3><?= htmlspecialchars($user_name) ?></h3>
                <p><span class="status-dot"></span> Welcome Back</p>
            </div>
        </div>
        <div class="navigation">
            <nav>
                <ul>
                    <li><a href="dashboard.php">Home</a></li>
                    <li><a href="deposit.php">Deposit</a></li>
                    <li><a href="withdraw.php">Withdraw</a></li>
                    <li><a href="history.php">History</a></li>
                    <li><a href="profile.php">Profile</a></li>
                    <li><a href="logout.php">Logout</a></li>
                </ul>
            </nav>
            <i class="fas fa-list" title="Toggle Sidebar"></i>
        </div>
        <div class="side-bar">
            <ul class="sidebar-menu">
                <li><a href="dashboard.php"><i class="fas fa-home"></i> <span>Home</span></a></li>
                <li><a href="deposit.php"><i class="fas fa-donate"></i> <span>Deposit</span></a></li>
                <li><a href="withdraw.php"><i class="fas fa-arrow-circle-down"></i> <span>Withdraw</span></a></li>
                <li><a href="history.php"><i class="fas fa-history"></i> <span>History</span></a></li>
                <li><a href="profile.php"><i class="fas fa-user"></i> <span>Profile</span></a></li>
                <li><a href="logout.php"><i class="fas fa-sign-out-alt"></i> <span>Logout</span></a></li>
            </ul>
        </div>
    </header>

    <!-- ===== MAIN CONTENT ===== -->
    <main>
        <div class="goal-page-wrapper">
            <div class="logo">
                <img src="<?= htmlspecialchars($header_avatar) ?>" alt="Tunza Waleti Logo">
            </div>

            <div class="title">
                <h2>Create Savings Goal</h2>
                <p class="subtitle">Set your target, timeline, and lock your savings</p>
            </div>

            <div class="das">
                <div class="dash-1"></div>
                <div class="dash-2"></div>
            </div>

            <?php if (!empty($error)): ?>
                <div class="alert error">
                    <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <form action="add_goal.php" method="POST">
                <div class="input-group">
                    <label for="title">Goal Title</label>
                    <input type="text" name="title" id="title" placeholder="e.g., House Rent, School Fees" value="<?= htmlspecialchars($_POST['title'] ?? '') ?>" required>
                </div>

                <div class="input-group">
                    <label for="target_amount">Target Amount (TZS)</label>
                    <input type="number" step="0.01" min="1" name="target_amount" id="target_amount" placeholder="e.g., 500000" value="<?= htmlspecialchars($_POST['target_amount'] ?? '') ?>" required>
                </div>

                <div class="input-group">
                    <label for="initial_amount">Initial Deposit Amount (Optional)</label>
                    <input type="number" step="0.01" min="0" name="initial_amount" id="initial_amount" placeholder="0.00" value="<?= htmlspecialchars($_POST['initial_amount'] ?? '') ?>">
                </div>

                <!-- Goal Time Duration Section -->
                <div class="input-group">
                    <label>Goal Target Duration</label>
                    <div class="duration-grid">
                        <input type="number" name="duration_value" id="duration_value" min="1" max="365" value="<?= htmlspecialchars($_POST['duration_value'] ?? '1') ?>" required placeholder="e.g. 6">
                        <select name="duration_type" id="duration_type" required>
                            <option value="days" <?= (($_POST['duration_type'] ?? '') === 'days') ? 'selected' : '' ?>>Days</option>
                            <option value="weeks" <?= (($_POST['duration_type'] ?? '') === 'weeks') ? 'selected' : '' ?>>Weeks</option>
                            <option value="months" <?= (($_POST['duration_type'] ?? 'months') === 'months') ? 'selected' : '' ?>>Months</option>
                            <option value="years" <?= (($_POST['duration_type'] ?? '') === 'years') ? 'selected' : '' ?>>Years</option>
                        </select>
                    </div>
                </div>

                <div class="target-date-preview" id="datePreview">
                    <i class="fas fa-calendar-alt"></i> Goal Completion Date: <strong id="previewDateStr">--</strong>
                </div>

                <div class="btn">
                    <button type="submit">Create Goal</button>
                </div>

                <div class="option">
                    <p><a href="dashboard.php">&larr; Back to Dashboard</a></p>
                </div>
            </form>
        </div>
    </main>

    <!-- Dynamic Date Calculation Listener -->
    <script>
        function updateDatePreview() {
            const valInput = document.getElementById('duration_value');
            const typeSelect = document.getElementById('duration_type');
            const previewStr = document.getElementById('previewDateStr');

            let num = parseInt(valInput.value, 10);
            if (isNaN(num) || num < 1) num = 1;

            const unit = typeSelect.value;
            const now = new Date();

            if (unit === 'days') {
                now.setDate(now.getDate() + num);
            } else if (unit === 'weeks') {
                now.setDate(now.getDate() + (num * 7));
            } else if (unit === 'months') {
                now.setMonth(now.getMonth() + num);
            } else if (unit === 'years') {
                now.setFullYear(now.getFullYear() + num);
            }

            const options = { year: 'numeric', month: 'short', day: 'numeric' };
            previewStr.innerText = now.toLocaleDateString('en-US', options);
        }

        document.getElementById('duration_value').addEventListener('input', updateDatePreview);
        document.getElementById('duration_type').addEventListener('change', updateDatePreview);
        window.addEventListener('DOMContentLoaded', updateDatePreview);

        // Client-side Cross-Tab Sync Listener
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