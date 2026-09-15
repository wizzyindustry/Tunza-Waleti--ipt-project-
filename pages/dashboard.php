<?php
// pages/dashboard.php

session_name('TUNZA_USER_SESSION');
session_start();

require_once '../database/auth.php'; // Ensures session exists & user is logged in
require_once '../database/config.php';

// Normalize PDO connection handle
if (!isset($pdo) && isset($conn)) {
    $pdo = $conn;
}

// Ensure logged-in user exists and has a regular user role
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

// Redirect admin users trying to access the user panel directly
if (($_SESSION['user_role'] ?? 'user') === 'admin') {
    header("Location: ../admin/dashboard.php");
    exit();
}

$user_id   = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'] ?? 'User';

// ------------------------------------------------------------------
// POST HANDLER: ADD MONEY TO OR WITHDRAW FROM A TARGET GOAL
// ------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['goal_action'])) {
    $goal_id = filter_var($_POST['goal_id'] ?? 0, FILTER_VALIDATE_INT);
    $amount  = filter_var($_POST['amount'] ?? 0, FILTER_VALIDATE_FLOAT);
    $action  = trim($_POST['goal_action']);

    if ($goal_id && $amount > 0 && in_array($action, ['add_to_goal', 'withdraw_from_goal'])) {
        try {
            $pdo->beginTransaction();

            // Fetch Main Wallet with row lock
            $stmtW = $pdo->prepare("SELECT id, balance FROM wallets WHERE user_id = :user_id FOR UPDATE");
            $stmtW->execute(['user_id' => $user_id]);
            $wallet = $stmtW->fetch(PDO::FETCH_ASSOC);

            // Fetch Goal details with row lock
            $stmtG = $pdo->prepare("SELECT * FROM savings_goals WHERE id = :goal_id AND user_id = :user_id FOR UPDATE");
            $stmtG->execute(['goal_id' => $goal_id, 'user_id' => $user_id]);
            $goal = $stmtG->fetch(PDO::FETCH_ASSOC);

            if (!$wallet || !$goal) {
                throw new Exception("Wallet or Target Goal record not found.");
            }

            $wallet_balance = (float)$wallet['balance'];
            $goal_balance   = (float)$goal['current_amount'];

            if ($action === 'add_to_goal') {
                if ($wallet_balance < $amount) {
                    throw new Exception("Insufficient main wallet balance to add money to this goal.");
                }

                // Deduct from Wallet, Add to Goal
                $new_wallet_bal = $wallet_balance - $amount;
                $new_goal_bal   = $goal_balance + $amount;

                $upW = $pdo->prepare("UPDATE wallets SET balance = :bal WHERE id = :id");
                $upW->execute(['bal' => $new_wallet_bal, 'id' => $wallet['id']]);

                $upG = $pdo->prepare("UPDATE savings_goals SET current_amount = :bal WHERE id = :id");
                $upG->execute(['bal' => $new_goal_bal, 'id' => $goal_id]);

                // Record Transaction Log
                $refNo = 'GOAL-ADD-' . strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 8));
                $stmtTx = $pdo->prepare("INSERT INTO transactions (reference_no, user_id, type, amount, status, description, created_at) VALUES (:ref, :uid, 'deposit', :amt, 'completed', :desc, NOW())");
                $stmtTx->execute(['ref' => $refNo, 'uid' => $user_id, 'amt' => $amount, 'desc' => "Added money to goal: " . $goal['title']]);

                $_SESSION['success_msg'] = "Successfully added TZS " . number_format($amount, 2) . " to goal: " . htmlspecialchars($goal['title']);

            } elseif ($action === 'withdraw_from_goal') {
                // ALLOWED AT ANY TIME: User can withdraw even if maturity date has not been reached
                if ($goal_balance < $amount) {
                    throw new Exception("Insufficient funds inside target goal to withdraw.");
                }

                // Deduct from Goal, Add to Wallet
                $new_goal_bal   = $goal_balance - $amount;
                $new_wallet_bal = $wallet_balance + $amount;

                $upG = $pdo->prepare("UPDATE savings_goals SET current_amount = :bal WHERE id = :id");
                $upG->execute(['bal' => $new_goal_bal, 'id' => $goal_id]);

                $upW = $pdo->prepare("UPDATE wallets SET balance = :bal WHERE id = :id");
                $upW->execute(['bal' => $new_wallet_bal, 'id' => $wallet['id']]);

                // Record Transaction Log
                $refNo = 'GOAL-WTH-' . strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 8));
                $stmtTx = $pdo->prepare("INSERT INTO transactions (reference_no, user_id, type, amount, status, description, created_at) VALUES (:ref, :uid, 'withdraw', :amt, 'completed', :desc, NOW())");
                $stmtTx->execute(['ref' => $refNo, 'uid' => $user_id, 'amt' => $amount, 'desc' => "Withdrew money from goal: " . $goal['title']]);

                $_SESSION['success_msg'] = "Successfully transferred TZS " . number_format($amount, 2) . " from goal back to main wallet.";
            }

            $pdo->commit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $_SESSION['error_msg'] = "Goal Operation Failed: " . $e->getMessage();
        }
    } else {
        $_SESSION['error_msg'] = "Invalid input details for goal transfer.";
    }

    header("Location: dashboard.php");
    exit();
}

// Fetch system logo dynamically
$site_logo = 'assets/images/panta logo-07.jpg'; 
if (isset($pdo) && $pdo !== null) {
    try {
        $stmtLogo = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'site_logo' LIMIT 1");
        if ($stmtLogo && $row = $stmtLogo->fetch(PDO::FETCH_ASSOC)) {
            if (!empty($row['setting_value'])) {
                $site_logo = $row['setting_value'];
            }
        }
    } catch (PDOException $e) {}
}

// Flash messages
$flash_success = $_SESSION['success_msg'] ?? '';
$flash_error   = $_SESSION['error_msg'] ?? '';
unset($_SESSION['success_msg'], $_SESSION['error_msg']);

$is_suspended = false;

try {
    // Check User Status
    $hasStatusCol = false;
    try {
        $colCheck = $pdo->query("SHOW COLUMNS FROM users LIKE 'status'");
        if ($colCheck && $colCheck->rowCount() > 0) {
            $hasStatusCol = true;
        }
    } catch (PDOException $e) {}

    $statusQuerySelect = $hasStatusCol ? "status" : "'active' AS status";
    $userCheckStmt = $pdo->prepare("SELECT id, role, {$statusQuerySelect} FROM users WHERE id = :user_id LIMIT 1");
    $userCheckStmt->execute(['user_id' => $user_id]);
    $currentUser = $userCheckStmt->fetch(PDO::FETCH_ASSOC);

    if (!$currentUser) {
        session_unset();
        session_destroy();
        header("Location: ../login.php");
        exit();
    }

    if (strtolower($currentUser['status'] ?? 'active') === 'suspended') {
        $is_suspended = true;
    }

    // Fetch User Main Wallet Balance
    $walletStmt = $pdo->prepare("SELECT balance, currency FROM wallets WHERE user_id = :user_id LIMIT 1");
    $walletStmt->execute(['user_id' => $user_id]);
    $wallet = $walletStmt->fetch(PDO::FETCH_ASSOC);

    if (!$wallet) {
        $initWallet = $pdo->prepare("INSERT INTO wallets (user_id, balance, currency) VALUES (:user_id, 0.00, 'TZS')");
        $initWallet->execute(['user_id' => $user_id]);
        $wallet_balance = 0.00;
        $currency       = 'TZS';
    } else {
        $wallet_balance = (float)$wallet['balance'];
        $currency       = $wallet['currency'] ?? 'TZS';
    }

    // Fetch Total Locked Savings Total
    $savingsStmt = $pdo->prepare("SELECT SUM(current_amount) FROM savings_goals WHERE user_id = :user_id");
    $savingsStmt->execute(['user_id' => $user_id]);
    $locked_savings_total = (float)($savingsStmt->fetchColumn() ?: 0.00);

    // Fetch Active Target Goals
    $goalsStmt = $pdo->prepare("
        SELECT * 
        FROM savings_goals 
        WHERE user_id = :user_id 
        ORDER BY id DESC
    ");
    $goalsStmt->execute(['user_id' => $user_id]);
    $goals = $goalsStmt->fetchAll(PDO::FETCH_ASSOC);

    // Find earliest active lock expiry timestamp for top card
    $earliest_unlock_time = 0;
    $now_ts = time();
    foreach ($goals as $g) {
        $ts = 0;
        if (!empty($g['target_date'])) {
            $ts = strtotime($g['target_date']);
        } elseif (!empty($g['deadline'])) {
            $ts = strtotime($g['deadline']);
        } elseif (!empty($g['duration_value']) && !empty($g['duration_type']) && !empty($g['created_at'])) {
            $ts = strtotime("+{$g['duration_value']} {$g['duration_type']}", strtotime($g['created_at']));
        }

        if ($ts > $now_ts) {
            if ($earliest_unlock_time === 0 || $ts < $earliest_unlock_time) {
                $earliest_unlock_time = $ts;
            }
        }
    }

    // Fetch Recent Transactions
    $txnStmt = $pdo->prepare("
        SELECT reference_no, type, amount, status, created_at 
        FROM transactions 
        WHERE user_id = :user_id 
        ORDER BY created_at DESC 
        LIMIT 6
    ");
    $txnStmt->execute(['user_id' => $user_id]);
    $transactions = $txnStmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("Database Error loading dashboard: " . $e->getMessage());
}

// User Avatar Logic
try {
    $headerUserStmt = $pdo->prepare("SELECT profile_pic FROM users WHERE id = :user_id LIMIT 1");
    $headerUserStmt->execute(['user_id' => $user_id]);
    $headerUser = $headerUserStmt->fetch(PDO::FETCH_ASSOC);

    $pic_filename = !empty($headerUser['profile_pic']) ? $headerUser['profile_pic'] : 'default.png';

    if (file_exists('../assets/uploads/' . $pic_filename) && $pic_filename !== 'default.png') {
        $header_avatar = '../assets/uploads/' . $pic_filename;
    } else {
        $header_avatar = '../' . $site_logo;
    }
} catch (PDOException $e) {
    $header_avatar = '../' . $site_logo;
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard | Tunza Waleti</title>
    <link rel="stylesheet" href="../assets/style/main.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/style/media.css">
    <link rel="icon" href="../<?= htmlspecialchars($site_logo) ?>">
    <script src="../assets/js/main.js" defer></script>
    <style>
        .dashboard-alert {
            padding: 14px 18px;
            margin: 15px 20px 0 20px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .dashboard-alert.success { background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .dashboard-alert.error { background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }

        .dual-balance-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }

        .balance-card.wallet-theme {
            background: linear-gradient(135deg, #3d0037 0%, #510049 100%);
            color: #ffffff;
            border-radius: 16px;
            padding: 22px;
            box-shadow: 0 8px 20px rgba(81, 0, 73, 0.15);
        }

        .balance-card.savings-theme {
            background: linear-gradient(135deg, #0e6251 0%, #117864 100%);
            color: #ffffff;
            border-radius: 16px;
            padding: 22px;
            box-shadow: 0 8px 20px rgba(17, 120, 100, 0.15);
        }

        .card-label-row { font-size: 13px; text-transform: uppercase; letter-spacing: 0.5px; opacity: 0.9; font-weight: 600; margin-bottom: 8px; }
        .card-amount-row { font-size: 28px; font-weight: 700; margin-bottom: 10px; }
        .card-subtext { font-size: 12px; opacity: 0.85; }

        /* Locked Card Timer Badge */
        .card-countdown-badge {
            margin-top: 10px;
            background: rgba(0, 0, 0, 0.25);
            padding: 6px 12px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        /* Countdown Badge Styling */
        .goal-countdown-box {
            background: #fdf2e9;
            border: 1px solid #fadbd8;
            color: #e67e22;
            padding: 8px 12px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
            margin-top: 10px;
            width: 100%;
        }
        .goal-countdown-box.matured {
            background: #d4edda;
            border-color: #c3e6cb;
            color: #155724;
        }

        /* Goal Actions Styling */
        .goal-actions-row {
            display: flex;
            gap: 10px;
            margin-top: 14px;
        }

        .btn-goal-action {
            flex: 1;
            padding: 10px 14px;
            border-radius: 8px;
            border: none;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            transition: 0.3s;
            text-decoration: none;
        }

        .btn-goal-add { background: #27ae60; color: #fff; }
        .btn-goal-add:hover { background: #1e8449; }

        .btn-goal-withdraw { background: #e67e22; color: #fff; }
        .btn-goal-withdraw:hover { background: #d35400; }

        /* Modal Overlays */
        .modal-overlay {
            position: fixed;
            top: 0; left: 0;
            width: 100vw; height: 100vh;
            background: rgba(0, 0, 0, 0.6);
            backdrop-filter: blur(4px);
            z-index: 9999;
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            visibility: hidden;
            transition: 0.3s ease;
        }
        .modal-overlay.active { opacity: 1; visibility: visible; }
        .modal-box {
            background: #ffffff;
            width: 90%;
            max-width: 420px;
            border-radius: 16px;
            padding: 25px;
            box-shadow: 0 15px 35px rgba(0,0,0,0.25);
        }
        .modal-box h3 { margin-bottom: 8px; color: #2c3e50; font-size: 18px; }
        .modal-box p { font-size: 13px; color: #666; margin-bottom: 15px; }

        .modal-form .form-group { margin-bottom: 15px; text-align: left; }
        .modal-form label { display: block; font-size: 13px; font-weight: 600; color: #444; margin-bottom: 6px; }
        .modal-form input { width: 100%; padding: 10px 12px; border: 1.5px solid #ddd; border-radius: 8px; font-size: 14px; outline: none; }
        .modal-form input:focus { border-color: #510049; }

        /* Suspension Overlay Modal */
        .suspension-overlay {
            position: fixed;
            top: 0; left: 0; width: 100vw; height: 100vh;
            background: rgba(0, 0, 0, 0.75);
            backdrop-filter: blur(8px);
            z-index: 99999;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .suspension-modal {
            background: #ffffff;
            width: 100%;
            max-width: 460px;
            border-radius: 20px;
            padding: 35px 25px;
            text-align: center;
            box-shadow: 0 20px 40px rgba(0,0,0,0.3);
        }

        .customer-care-cta{
            margin: 20px;
            background: #eaf2f8;
            padding: 20px;
            border-radius: 10px;
            text-align: center;
        }
        .customer-care-cta-content a{
            padding: 10px;
            background: #e74c3c;
            color: #fff;
            border-radius: 5px;
            text-decoration: none;
            font-weight: 600;
            font-size: 14px;
            transition: 0.3s;
            margin-top: 10px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 50%;
            gap: 8px;
        }
    </style>
</head>

<body>

    <?php if ($is_suspended): ?>
    <div class="suspension-overlay" id="suspensionModal">
        <div class="suspension-modal">
            <div class="suspension-icon" style="width: 80px; height: 80px; background: #fdedec; color: #e74c3c; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 36px; margin: 0 auto 20px auto;">
                <i class="fas fa-user-slash"></i>
            </div>
            <h2>Account Suspended</h2>
            <p style="font-size: 14px; color: #666; line-height: 1.6; margin-bottom: 25px;">Your account access has been temporarily restricted by the administrator. Please contact system support for further assistance or updates regarding your account.</p>
            <div>
                <a href="logout.php" style="background: #e74c3c; color: #fff; padding: 12px 24px; border-radius: 10px; text-decoration: none; font-weight: 600; font-size: 14px; display: inline-flex; align-items: center; gap: 8px;"><i class="fas fa-sign-out-alt"></i> Logout Now</a>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- HEADER -->
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
                    <li><a href="dashboard.php" class="active">Home</a></li>
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
                <li class="active"><a href="dashboard.php"><i class="fas fa-home"></i> <span>Home</span></a></li>
                <li><a href="deposit.php"><i class="fas fa-donate"></i> <span>Deposit</span></a></li>
                <li><a href="withdraw.php"><i class="fas fa-arrow-circle-down"></i> <span>Withdraw</span></a></li>
                <li><a href="history.php"><i class="fas fa-history"></i> <span>History</span></a></li>
                <li><a href="profile.php"><i class="fas fa-user"></i> <span>Profile</span></a></li>
                <li><a href="logout.php"><i class="fas fa-sign-out-alt"></i> <span>Logout</span></a></li>
            </ul>
        </div>
    </header>

    <!-- MAIN DASHBOARD -->
    <main class="dashboard-main">

        <?php if (!empty($flash_success)): ?>
            <div class="dashboard-alert success">
                <i class="fas fa-check-circle"></i> <?= htmlspecialchars($flash_success) ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($flash_error)): ?>
            <div class="dashboard-alert error">
                <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($flash_error) ?>
            </div>
        <?php endif; ?>

        <section class="dashboard-hero">
            <div class="hero-text">
                <h2>Account Overview</h2>
                <p>Manage your main wallet, track locked goal savings, and review quick transactions.</p>
            </div>
            <div class="hero-badge">
                <i class="fas fa-shield-alt"></i> Verified Account
            </div>
        </section>

        <!-- Balance Section Cards -->
        <section class="balance-section">
            <div class="dual-balance-grid">
                
                <div class="balance-card wallet-theme">
                    <div class="card-label-row"><i class="fas fa-wallet"></i> Available Wallet Balance</div>
                    <div class="card-amount-row">
                        <span><?= htmlspecialchars($currency) ?></span> <?= number_format($wallet_balance, 2) ?>
                    </div>
                    <div class="card-subtext"><i class="fas fa-sync-alt"></i> Ready for instant transfer or withdrawal</div>
                </div>

                <div class="balance-card savings-theme">
                    <div class="card-label-row"><i class="fas fa-piggy-bank"></i> Total Savings Goals Balance</div>
                    <div class="card-amount-row">
                        <span><?= htmlspecialchars($currency) ?></span> <?= number_format($locked_savings_total, 2) ?>
                    </div>
                    <div class="card-subtext"><i class="fas fa-lock"></i> Locked & earning interest towards target goals</div>
                    
                    <!-- Countdown Display directly inside Total Savings Card -->
                    <div class="card-countdown-badge" id="totalSavingsCardCountdown">
                        <i class="fas fa-clock"></i> <span id="totalSavingsTimerText">Calculating lock status...</span>
                    </div>
                </div>

            </div>
        </section>

        <!-- Action Buttons -->
        <section class="actions-section">
            <div class="actions-grid">
                <a href="deposit.php" class="action-card deposit-card">
                    <div class="action-icon"><i class="fas fa-arrow-down"></i></div>
                    <span class="action-label">Deposit</span>
                </a>
                <a href="withdraw.php" class="action-card withdraw-card">
                    <div class="action-icon"><i class="fas fa-wallet"></i></div>
                    <span class="action-label">Withdraw</span>
                </a>
                <a href="history.php" class="action-card history-card">
                    <div class="action-icon"><i class="fas fa-history"></i></div>
                    <span class="action-label">History</span>
                </a>
                <a href="profile.php" class="action-card send-card">
                    <div class="action-icon"><i class="fas fa-user-gear"></i></div>
                    <span class="action-label">Profile</span>
                </a>
            </div>
        </section>

        <!-- Widgets Layout Grid -->
        <div class="dashboard-widgets-grid">
            
            <!-- Target Goals Section -->
            <section class="goals-section">
                <div class="widget-header">
                    <h3><i class="fas fa-bullseye"></i> Target Goals</h3>
                    <a href="add_goal.php" class="widget-link">Add Goal <i class="fas fa-plus-circle"></i></a>
                </div>

                <?php if (empty($goals)): ?>
                    <div class="empty-state-card">
                        <i class="fas fa-bullseye fa-2x"></i>
                        <p>No active target goals set yet.</p>
                        <small>Create a goal to start tracking your savings target!</small>
                    </div>
                <?php else: ?>
                    <?php foreach ($goals as $goal): ?>
                        <?php 
                            $percent = $goal['target_amount'] > 0 ? min(100, round(($goal['current_amount'] / $goal['target_amount']) * 100)) : 0;

                            // Dynamic target timestamp calculation
                            $target_time = 0;
                            if (!empty($goal['target_date'])) {
                                $target_time = strtotime($goal['target_date']);
                            } elseif (!empty($goal['deadline'])) {
                                $target_time = strtotime($goal['deadline']);
                            } elseif (!empty($goal['duration_value']) && !empty($goal['duration_type']) && !empty($goal['created_at'])) {
                                $target_time = strtotime("+{$goal['duration_value']} {$goal['duration_type']}", strtotime($goal['created_at']));
                            }

                            $is_locked = ($target_time > 0 && time() < $target_time);
                        ?>
                        <div class="progress-card">
                            <div class="progress-circle">
                                <span class="percentage"><?= $percent ?>%</span>
                            </div>
                            <div class="goals-info">
                                <h4 class="goal-title"><?= htmlspecialchars($goal['title']) ?></h4>
                                <div class="goal-numbers">
                                    <span class="current"><?= $currency ?> <?= number_format($goal['current_amount'], 2) ?></span>
                                    <span class="target">of <?= $currency ?> <?= number_format($goal['target_amount'], 2) ?></span>
                                </div>
                                <div class="progress-bar-wrapper">
                                    <div class="progress-bar-fill" style="width: <?= $percent ?>%;"></div>
                                </div>

                                <!-- Dynamic Live Countdown Timer Box -->
                                <?php if ($target_time > 0): ?>
                                    <div class="goal-countdown-box <?= !$is_locked ? 'matured' : '' ?>" id="cntBox_<?= $goal['id'] ?>">
                                        <i class="fas <?= $is_locked ? 'fa-clock' : 'fa-check-circle' ?>" id="cntIcon_<?= $goal['id'] ?>"></i>
                                        <span id="cntText_<?= $goal['id'] ?>">Calculating remaining time...</span>
                                    </div>
                                <?php endif; ?>

                                <!-- Action Buttons Row: Both Deposit and Withdraw are ALWAYS enabled -->
                                <div class="goal-actions-row">
                                    <button type="button" class="btn-goal-action btn-goal-add" 
                                            onclick="openGoalModal(<?= $goal['id'] ?>, 'add_to_goal', '<?= htmlspecialchars(addslashes($goal['title'])) ?>')">
                                        <i class="fas fa-plus"></i> Add Money
                                    </button>
                                    
                                    <button type="button" 
                                            id="btnWithdraw_<?= $goal['id'] ?>"
                                            class="btn-goal-action btn-goal-withdraw" 
                                            onclick="openGoalModal(<?= $goal['id'] ?>, 'withdraw_from_goal', '<?= htmlspecialchars(addslashes($goal['title'])) ?>')">
                                        <i class="fas fa-minus" id="btnWithdrawIcon_<?= $goal['id'] ?>"></i> 
                                        <span id="btnWithdrawText_<?= $goal['id'] ?>">Withdraw</span>
                                    </button>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </section>

            <!-- Recent Transactions Section -->
            <section class="plan-container">
                <div class="widget-header">
                    <h3><i class="fas fa-history"></i> Recent Transactions</h3>
                    <a href="history.php" class="widget-link">View All <i class="fas fa-chevron-right"></i></a>
                </div>

                <?php if (empty($transactions)): ?>
                    <div class="empty-state-card">
                        <i class="fas fa-receipt fa-2x"></i>
                        <p>No recent transaction activity.</p>
                        <small>Make your first deposit or withdrawal to see records here.</small>
                    </div>
                <?php else: ?>
                    <div class="plan-cards-grid">
                        <?php foreach ($transactions as $txn): 
                            $txnType     = strtolower($txn['type']);
                            $isWithdraw  = in_array($txnType, ['withdraw', 'withdrawal', 'payout']);
                            $isCompleted = in_array(strtolower($txn['status']), ['completed', 'success', 'successful']);
                        ?>
                            <div class="plan-item-card">
                                <div class="plan-card-top">
                                    <span class="plan-name">
                                        <i class="fas <?= $isWithdraw ? 'fa-arrow-up text-red' : 'fa-arrow-down text-green' ?>"></i> 
                                        <?= ucfirst(htmlspecialchars($txn['type'])) ?>
                                    </span>
                                    <span class="plan-duration"><?= date('d M Y, H:i', strtotime($txn['created_at'])) ?></span>
                                </div>
                                <div class="plan-card-bottom">
                                    <span class="plan-amount" style="color: <?= $isWithdraw ? '#c0392b' : '#27ae60' ?>;">
                                        <?= $isWithdraw ? '-' : '+' ?> <?= $currency ?> <?= number_format($txn['amount'], 2) ?>
                                    </span>
                                    <span class="plan-status <?= $isCompleted ? 'mature-badge' : 'active-badge' ?>">
                                        <?= ucfirst(htmlspecialchars($txn['status'])) ?>
                                    </span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

        </div>

        <!-- Customer Care CTA -->
        <div class="customer-care-cta">
            <div class="customer-care-cta-content">
                <i class="fas fa-headset" style="font-size: 32px; color: #e74c3c; margin-bottom: 10px;"></i>
                <h3>Customer Care</h3>
                <p>Need help? Contact customer care for assistance.</p>
                <a href="support.php" class="btn-primary">Contact Us</a>
            </div>
        </div>
        
    </main>

    <!-- Goal Transfer Modal -->
    <div class="modal-overlay" id="goalModal">
        <div class="modal-box">
            <h3 id="goalModalTitle">Goal Action</h3>
            <p id="goalModalSub">Transfer funds for your goal</p>

            <form method="POST" action="dashboard.php" class="modal-form">
                <input type="hidden" name="goal_id" id="modalGoalId">
                <input type="hidden" name="goal_action" id="modalGoalAction">

                <div class="form-group">
                    <label>Amount (TZS)</label>
                    <input type="number" step="0.01" min="1" name="amount" placeholder="e.g. 10000" required>
                </div>

                <div style="display: flex; gap: 10px; margin-top: 20px;">
                    <button type="submit" class="btn-goal-action btn-goal-add" id="modalSubmitBtn" style="flex: 1;">Confirm Transfer</button>
                    <button type="button" class="btn-goal-action" style="background:#7f8c8d; color:#fff;" onclick="closeGoalModal()">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Real-time Countdown Timer Engine -->
    <script>
        const goalTimers = [];
        const earliestUnlockTime = <?= $earliest_unlock_time * 1000 ?>;

        <?php foreach ($goals as $g): ?>
            <?php 
                $target_ts = 0;
                if (!empty($g['target_date'])) {
                    $target_ts = strtotime($g['target_date']);
                } elseif (!empty($g['deadline'])) {
                    $target_ts = strtotime($g['deadline']);
                } elseif (!empty($g['duration_value']) && !empty($g['duration_type']) && !empty($g['created_at'])) {
                    $target_ts = strtotime("+{$g['duration_value']} {$g['duration_type']}", strtotime($g['created_at']));
                }
            ?>
            <?php if ($target_ts > 0): ?>
                goalTimers.push({
                    id: <?= $g['id'] ?>,
                    targetTime: <?= $target_ts * 1000 ?>
                });
            <?php endif; ?>
        <?php endforeach; ?>

        function updateCountdowns() {
            const now = new Date().getTime();

            // 1. Update Top Locked Balance Card Timer
            const cardTimerText = document.getElementById('totalSavingsTimerText');
            if (cardTimerText) {
                if (earliestUnlockTime > now) {
                    const diff = earliestUnlockTime - now;
                    const d = Math.floor(diff / (1000 * 60 * 60 * 24));
                    const h = Math.floor((diff % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
                    const m = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));
                    const s = Math.floor((diff % (1000 * 60)) / 1000);
                    cardTimerText.innerText = `Earliest Unlock: ${d}d ${h}h ${m}m ${s}s`;
                } else {
                    cardTimerText.innerText = "All Savings Unlocked!";
                }
            }

            // 2. Update Goal Card Countdown Display
            goalTimers.forEach(item => {
                const distance = item.targetTime - now;

                const box = document.getElementById('cntBox_' + item.id);
                const txt = document.getElementById('cntText_' + item.id);
                const icon = document.getElementById('cntIcon_' + item.id);

                if (distance > 0) {
                    const days = Math.floor(distance / (1000 * 60 * 60 * 24));
                    const hours = Math.floor((distance % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
                    const minutes = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));
                    const seconds = Math.floor((distance % (1000 * 60)) / 1000);

                    if (txt) {
                        txt.innerText = "Time Remaining: " + days + "d " + hours + "h " + minutes + "m " + seconds + "s";
                    }
                } else {
                    if (txt) {
                        txt.innerText = "Goal Matured & Unlocked!";
                    }
                    if (box) {
                        box.classList.add('matured');
                    }
                    if (icon) {
                        icon.className = 'fas fa-check-circle';
                    }
                }
            });
        }

        setInterval(updateCountdowns, 1000);
        window.addEventListener('DOMContentLoaded', updateCountdowns);

        function openGoalModal(goalId, actionType, goalTitle) {
            document.getElementById('modalGoalId').value = goalId;
            document.getElementById('modalGoalAction').value = actionType;

            const modalTitle = document.getElementById('goalModalTitle');
            const modalSub = document.getElementById('goalModalSub');
            const submitBtn = document.getElementById('modalSubmitBtn');

            if (actionType === 'add_to_goal') {
                modalTitle.innerText = "Add Money to Goal";
                modalSub.innerText = "Transfer funds from your available wallet to: " + goalTitle;
                submitBtn.innerText = "Add Money";
                submitBtn.className = "btn-goal-action btn-goal-add";
            } else {
                modalTitle.innerText = "Withdraw from Goal";
                modalSub.innerText = "Transfer funds from " + goalTitle + " back to your available wallet";
                submitBtn.innerText = "Withdraw Funds";
                submitBtn.className = "btn-goal-action btn-goal-withdraw";
            }

            document.getElementById('goalModal').classList.add('active');
        }

        function closeGoalModal() {
            document.getElementById('goalModal').classList.remove('active');
        }

        // Cross-tab Session Listener
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