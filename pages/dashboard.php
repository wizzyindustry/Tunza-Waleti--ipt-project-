<?php
// pages/dashboard.php

// 1. Initialize isolated User Session to prevent cross-tab session collisions
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

// Flash message check (set by deposit.php or withdraw.php)
$flash_success = $_SESSION['success_msg'] ?? '';
$flash_error   = $_SESSION['error_msg'] ?? '';

// Clear flash messages after storing them locally
unset($_SESSION['success_msg'], $_SESSION['error_msg']);

$is_suspended = false;

try {
    // 3. Verify logged-in user exists & fetch current account status
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

    // Check suspension status
    if (strtolower($currentUser['status'] ?? 'active') === 'suspended') {
        $is_suspended = true;
    }

    // 4. Fetch User Main Wallet Balance (Auto-heals missing row)
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

    // 5. Fetch User's Total Locked Goal Savings
    $savingsStmt = $pdo->prepare("SELECT SUM(current_amount) FROM savings_goals WHERE user_id = :user_id");
    $savingsStmt->execute(['user_id' => $user_id]);
    $locked_savings_total = (float)($savingsStmt->fetchColumn() ?: 0.00);

    // 6. Fetch User's Active Target Goals
    $goalsStmt = $pdo->prepare("
        SELECT id, title, target_amount, current_amount 
        FROM savings_goals 
        WHERE user_id = :user_id 
        ORDER BY id DESC
    ");
    $goalsStmt->execute(['user_id' => $user_id]);
    $goals = $goalsStmt->fetchAll(PDO::FETCH_ASSOC);

    // 7. Fetch User's Recent Transactions (Deposits & Withdrawals)
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

// 8. Fetch user profile picture for header
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

        /* Suspension Overlay Modal */
        .suspension-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100vw;
            height: 100vh;
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
            animation: popup 0.3s ease-out forwards;
        }

        @keyframes popup {
            from { transform: scale(0.8); opacity: 0; }
            to { transform: scale(1); opacity: 1; }
        }

        .suspension-icon {
            width: 80px;
            height: 80px;
            background: #fdedec;
            color: #e74c3c;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 36px;
            margin: 0 auto 20px auto;
        }

        .suspension-modal h2 {
            font-size: 22px;
            color: #2c3e50;
            margin-bottom: 12px;
            font-weight: 700;
        }

        .suspension-modal p {
            font-size: 14px;
            color: #666;
            line-height: 1.6;
            margin-bottom: 25px;
        }

        .suspension-actions {
            display: flex;
            gap: 12px;
            justify-content: center;
        }

        .btn-suspend-logout {
            background: #e74c3c;
            color: #fff;
            padding: 12px 24px;
            border-radius: 10px;
            text-decoration: none;
            font-weight: 600;
            font-size: 14px;
            transition: 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-suspend-logout:hover {
            background: #c0392b;
        }

        /* customer care style */
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
        .customer-care-cta-content a:hover{
            background: #c0392b;
        }
    </style>
</head>

<body>

    <?php if ($is_suspended): ?>
    <!-- Account Suspension Dialog Overlay -->
    <div class="suspension-overlay" id="suspensionModal">
        <div class="suspension-modal">
            <div class="suspension-icon">
                <i class="fas fa-user-slash"></i>
            </div>
            <h2>Account Suspended</h2>
            <p>Your account access has been temporarily restricted by the administrator. Please contact system support for further assistance or updates regarding your account.</p>
            <div class="suspension-actions">
                <a href="logout.php" class="btn-suspend-logout"><i class="fas fa-sign-out-alt"></i> Logout Now</a>
            </div>
        </div>
    </div>
    <?php endif; ?>

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

    <!-- ===== MAIN DASHBOARD ===== -->
    <main class="dashboard-main">

        <!-- Flash Alert Message Handler -->
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

        <!-- Dashboard Welcome Hero Section -->
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
                
                <!-- Main Available Wallet Balance -->
                <div class="balance-card wallet-theme">
                    <div class="card-label-row"><i class="fas fa-wallet"></i> Available Wallet Balance</div>
                    <div class="card-amount-row">
                        <span><?= htmlspecialchars($currency) ?></span> <?= number_format($wallet_balance, 2) ?>
                    </div>
                    <div class="card-subtext"><i class="fas fa-sync-alt"></i> Ready for instant transfer or withdrawal</div>
                </div>

                <!-- Total Goal Savings Balance -->
                <div class="balance-card savings-theme">
                    <div class="card-label-row"><i class="fas fa-piggy-bank"></i> Total Savings Goals Balance</div>
                    <div class="card-amount-row">
                        <span><?= htmlspecialchars($currency) ?></span> <?= number_format($locked_savings_total, 2) ?>
                    </div>
                    <div class="card-subtext"><i class="fas fa-lock"></i> Locked & earning interest towards target goals</div>
                </div>

            </div>
        </section>

        <!-- Main Action Buttons Grid -->
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

        <!-- Goals & Active Plans Layout Grid -->
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
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </section>

            <!-- Activity & Transactions Section -->
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
                            $txnType    = strtolower($txn['type']);
                            $isWithdraw = in_array($txnType, ['withdraw', 'withdrawal', 'payout']);
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

        <!-------customer care CTA --------------------->
        <div class="customer-care-cta">
            <div class="customer-care-cta-content">
                <i class="fas fa-headset"></i>
                <h3>Customer Care</h3>
                <p>Need help? Contact customer care for assistance.</p>
                <a href="support.php" class="btn-primary">Contact Us</a>
            </div>
        </div>
        
    </main>

    <!-- Client-side Cross-Tab Sync Listener -->
    <script>
        window.addEventListener('storage', function(event) {
            if (event.key === 'tunza_session_update') {
                const sessionData = JSON.parse(event.newValue);
                if (sessionData && sessionData.role === 'admin') {
                    // Automatically redirect tab to Admin Dashboard if admin login occurs in another tab
                    window.location.href = '../admin/dashboard.php';
                }
            }
        });
    </script>
</body>

</html>