<?php
// pages/deposit.php

// 1. Set isolated session name BEFORE starting session to prevent session collision
session_name('TUNZA_USER_SESSION');
session_start();

require_once '../database/auth.php';
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

// 2. Fetch system logo & Maintenance Mode dynamically safely
$site_logo = 'assets/images/panta logo-07.jpg'; // Default fallback
$maintenance_mode = '0';

if (isset($pdo) && $pdo !== null) {
    try {
        $stmtSettings = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('site_logo', 'maintenance_mode')");
        if ($stmtSettings) {
            while ($row = $stmtSettings->fetch(PDO::FETCH_ASSOC)) {
                if ($row['setting_key'] === 'site_logo' && !empty($row['setting_value'])) {
                    $site_logo = $row['setting_value'];
                }
                if ($row['setting_key'] === 'maintenance_mode') {
                    $maintenance_mode = (string)$row['setting_value'];
                }
            }
        }
    } catch (PDOException $e) {
        // Fallback silently if table does not exist
    }
}

// ------------------------------------------------------------------
// CLICKPESA CONFIGURATION
// ------------------------------------------------------------------
define('CLICKPESA_CLIENT_ID', 'IDXaiifCqpiVlSY0dxAguzc1s2EHgeQD');
define('CLICKPESA_API_KEY',   'SKj94nhpa4oDQav9GUJOtC6gwumVxwJOVhxp10a54s');
define('CLICKPESA_CHECKSUM_KEY', 'CHKawxG15lEwseaRTNBcoGOaGwAIIeI1RPJ');

/**
 * Fetch dynamic Bearer token from ClickPesa
 */
function getClickPesaBearerToken() {
    if (isset($_SESSION['clickpesa_jwt_token']) && isset($_SESSION['clickpesa_jwt_expires'])) {
        if (time() < $_SESSION['clickpesa_jwt_expires']) {
            return $_SESSION['clickpesa_jwt_token'];
        }
    }

    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => "https://api.clickpesa.com/third-parties/generate-token",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CUSTOMREQUEST => "POST",
        CURLOPT_HTTPHEADER => [
            "client-id: " . CLICKPESA_CLIENT_ID,
            "api-key: " . CLICKPESA_API_KEY
        ],
    ]);

    $response = curl_exec($curl);
    $err = curl_error($curl);
    curl_close($curl);

    if ($err) return null;

    $data = json_decode($response, true);
    
    if (isset($data['token'])) {
        $token = $data['token'];
        if (strpos($token, 'Bearer ') !== 0) {
            $token = 'Bearer ' . $token;
        }
        $_SESSION['clickpesa_jwt_token'] = $token;
        $_SESSION['clickpesa_jwt_expires'] = time() + (50 * 60);
        return $token;
    }

    return null;
}

/**
 * Helper function to create ClickPesa HMAC-SHA256 Checksum
 */
function generateClickPesaChecksum($checksumKey, $payload) {
    unset($payload['checksum']);

    $canonicalize = function ($obj) use (&$canonicalize) {
        if (!is_array($obj)) return $obj;
        if (array_values($obj) === $obj) return array_map($canonicalize, $obj);
        ksort($obj);
        $result = [];
        foreach ($obj as $key => $value) {
            $result[$key] = $canonicalize($value);
        }
        return $result;
    };

    $canonicalPayload = $canonicalize($payload);
    $payloadString = json_encode($canonicalPayload, JSON_UNESCAPED_SLASHES);

    return hash_hmac('sha256', $payloadString, $checksumKey);
}

// Fetch user's active goals
try {
    $goalsStmt = $pdo->prepare("SELECT id, title FROM savings_goals WHERE user_id = :user_id");
    $goalsStmt->execute(['user_id' => $user_id]);
    $user_goals = $goalsStmt->fetchAll();
} catch (PDOException $e) {
    $user_goals = [];
}

// 3. AJAX ENDPOINT: Initiate ClickPesa USSD Push Request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'initiate_ussd') {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json');

    // Check Maintenance Mode
    if ($maintenance_mode === '1') {
        echo json_encode(['success' => false, 'message' => 'System deposits are currently under scheduled maintenance. Please try again later.']);
        exit;
    }
    
    $amount      = filter_var($_POST['amount'] ?? 0, FILTER_VALIDATE_FLOAT);
    $phoneNumber = trim($_POST['phone_number'] ?? '');
    $goal_id     = filter_var($_POST['goal_id'] ?? null, FILTER_VALIDATE_INT);
    
    // Pure alphanumeric reference
    $orderRef    = 'DEP' . date('YmdHis') . rand(100, 999);

    // Format phone number to international standard (2557XXXXXXXX)
    $phoneNumber = preg_replace('/[^0-9]/', '', $phoneNumber);
    if (strpos($phoneNumber, '0') === 0) {
        $phoneNumber = '255' . substr($phoneNumber, 1);
    }

    if ($amount === false || $amount <= 0 || empty($phoneNumber)) {
        echo json_encode(['success' => false, 'message' => 'Invalid amount or phone number specified.']);
        exit;
    }

    $bearerToken = getClickPesaBearerToken();
    if (!$bearerToken) {
        echo json_encode(['success' => false, 'message' => 'Failed to generate JWT authentication token with ClickPesa gateway.']);
        exit;
    }

    $payloadData = [
        'amount'         => (string)$amount,
        'currency'       => 'TZS',
        'orderReference' => (string)$orderRef,
        'phoneNumber'    => (string)$phoneNumber
    ];

    $payloadData['checksum'] = generateClickPesaChecksum(CLICKPESA_CHECKSUM_KEY, $payloadData);

    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => "https://api.clickpesa.com/third-parties/payments/initiate-ussd-push-request",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CUSTOMREQUEST => "POST",
        CURLOPT_POSTFIELDS => json_encode($payloadData),
        CURLOPT_HTTPHEADER => [
            "Authorization: " . $bearerToken,
            "Content-Type: application/json"
        ],
    ]);

    $response = curl_exec($curl);
    $err = curl_error($curl);
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    if ($err) {
        echo json_encode(['success' => false, 'message' => 'Gateway Connection Error: ' . $err]);
        exit;
    }

    $resData = json_decode($response, true);

    $isSuccess = ($httpCode >= 200 && $httpCode < 300) && (
        (isset($resData['status']) && in_array($resData['status'], ['PROCESSING', 'PENDING', 'SUCCESS'])) ||
        (isset($resData['success']) && $resData['success'] === true)
    );

    if ($isSuccess) {
        echo json_encode([
            'success'      => true,
            'orderRef'     => $orderRef,
            'amount'       => $amount,
            'goal_id'      => $goal_id ?? null,
            'api_response' => $resData
        ]);
    } else {
        $errorMessage = $resData['message'] 
                     ?? $resData['error'] 
                     ?? $resData['description'] 
                     ?? 'ClickPesa rejected the payment initiation request.';

        echo json_encode([
            'success'  => false,
            'message'  => $errorMessage,
            'raw_body' => $resData
        ]);
    }
    exit;
}

// 4. POST ENDPOINT: Final SQL Wallet Deposit Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'confirm_deposit') {
    if ($maintenance_mode === '1') {
        $error = "System deposits are currently under scheduled maintenance.";
    } else {
        $amount         = filter_var($_POST['amount'] ?? 0, FILTER_VALIDATE_FLOAT);
        $payment_method = trim($_POST['payment_method'] ?? 'Mobile Money');
        $goal_id        = filter_var($_POST['goal_id'] ?? null, FILTER_VALIDATE_INT);
        $reference_no   = trim($_POST['gateway_reference'] ?? '');

        if ($amount === false || $amount <= 0 || empty($reference_no)) {
            $error = "Invalid deposit parameters specified.";
        } else {
            try {
                $pdo->beginTransaction();

                $updateWallet = $pdo->prepare("
                    UPDATE wallets 
                    SET balance = balance + :amount 
                    WHERE user_id = :user_id
                ");
                $updateWallet->execute([
                    'amount'  => $amount,
                    'user_id' => $user_id
                ]);

                if ($goal_id) {
                    $updateGoal = $pdo->prepare("
                        UPDATE savings_goals 
                        SET current_amount = current_amount + :amount 
                        WHERE id = :goal_id AND user_id = :user_id
                    ");
                    $updateGoal->execute([
                        'amount'  => $amount,
                        'goal_id' => $goal_id,
                        'user_id' => $user_id
                    ]);
                }

                $insertTxn = $pdo->prepare("
                    INSERT INTO transactions (reference_no, user_id, type, amount, status, payment_method, description) 
                    VALUES (:ref, :user_id, 'deposit', :amount, 'completed', :method, :desc)
                ");
                $insertTxn->execute([
                    'ref'     => $reference_no,
                    'user_id' => $user_id,
                    'amount'  => $amount,
                    'method'  => $payment_method,
                    'desc'    => $goal_id ? 'Deposit allocated to target goal' : 'Wallet Deposit'
                ]);

                $pdo->commit();

                $_SESSION['success_msg'] = "Deposit of TZS " . number_format($amount, 2) . " completed successfully! Ref: " . $reference_no;
                header("Location: dashboard.php");
                exit();

            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = "Transaction failed: " . $e->getMessage();
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Deposit Funds | Tunza Waleti</title>
    <link rel="shortcut icon" href="../<?= htmlspecialchars($site_logo) ?>" type="image/x-icon">
    <link rel="stylesheet" href="../assets/style/main.css">
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
            min-height: 650px;
            background: #ffffff;
            border-radius: 20px;
            box-shadow: 0 15px 35px rgba(81, 0, 73, 0.15);
            overflow: hidden;
            margin: 20px;
        }

        /* Left Side: Deposit Description Panel */
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
            font-size: 28px;
            font-weight: 800;
            margin-bottom: 12px;
            line-height: 1.3;
        }

        .description-left-panel p.lead-desc {
            font-size: 14px;
            line-height: 1.6;
            opacity: 0.9;
            margin-bottom: 30px;
        }

        .guide-list {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .guide-item {
            display: flex;
            align-items: flex-start;
            gap: 15px;
        }

        .guide-icon {
            width: 40px;
            height: 40px;
            background: rgba(255, 255, 255, 0.15);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            flex-shrink: 0;
        }

        .guide-text h4 {
            margin: 0 0 3px 0;
            font-size: 15px;
            font-weight: 700;
        }

        .guide-text p {
            margin: 0;
            font-size: 13px;
            opacity: 0.8;
            line-height: 1.4;
        }

        /* Right Side: Deposit Form Panel */
        .deposit-right-panel {
            flex: 1;
            padding: 50px 40px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            background: #ffffff;
        }

        .deposit-title h3 {
            margin: 0 0 6px 0;
            font-size: 22px;
            color: var(--dark-purple);
        }

        .deposit-title p {
            margin: 0 0 25px 0;
            color: #666;
            font-size: 14px;
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

        .input-group input, .input-group select {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #ccc;
            border-radius: 8px;
            font-size: 14px;
            box-sizing: border-box;
            color: #000;
            background: #fff;
            transition: 0.3s;
        }

        .input-group input:focus, .input-group select:focus {
            border-color: var(--primary-purple);
            outline: none;
            color: #000;
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
            background: var(--dark-purple);
        }

        .btn-cancel {
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

        .btn-cancel:hover {
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

        /* USSD Waiting Box Styling */
        .status-box {
            text-align: center;
            padding: 25px 20px;
            background: #FAF7FA;
            border: 1px solid #F0E6EF;
            border-radius: 16px;
            margin-bottom: 20px;
        }

        .radar-spinner {
            position: relative;
            width: 70px;
            height: 70px;
            margin: 0 auto 15px auto;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .radar-circle {
            position: absolute;
            width: 100%;
            height: 100%;
            border-radius: 50%;
            background-color: rgba(81, 0, 73, 0.15);
            animation: pulse-ring 1.8s cubic-bezier(0.215, 0.61, 0.355, 1) infinite;
        }

        .radar-icon {
            position: relative;
            width: 46px;
            height: 46px;
            border-radius: 50%;
            background: var(--primary-purple);
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
        }

        @keyframes pulse-ring {
            0% { transform: scale(0.6); opacity: 0.9; }
            80%, 100% { transform: scale(1.4); opacity: 0; }
        }

        .progress-steps {
            text-align: left;
            margin: 15px 0 10px 0;
        }

        .step-item {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 10px;
            font-size: 13px;
            color: #777;
        }

        .step-item.active {
            color: var(--primary-purple);
            font-weight: 600;
        }

        /* POPUP MODAL DIALOG STYLES */
        .popup-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.65);
            backdrop-filter: blur(4px);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 9999;
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
            box-shadow: 0 20px 40px rgba(0,0,0,0.25);
            transform: scale(0.85);
            transition: transform 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        }

        .popup-overlay.active .popup-box {
            transform: scale(1);
        }

        .popup-icon {
            width: 65px;
            height: 65px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 30px;
            margin: 0 auto 18px auto;
        }

        .popup-icon.error { background: #fce4e4; color: var(--danger-red); }
        .popup-icon.warning { background: #fef5e7; color: var(--warning-amber); }
        .popup-icon.success { background: #e8f8f5; color: var(--emerald-green); }
        .popup-icon.maintenance { background: #fdeaea; color: var(--danger-red); }

        .popup-title {
            margin: 0 0 10px 0;
            font-size: 20px;
            color: var(--dark-purple);
            font-weight: 700;
        }

        .popup-message {
            margin: 0 0 25px 0;
            font-size: 14px;
            color: #555;
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
            text-decoration: none;
            display: block;
            box-sizing: border-box;
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
            .deposit-right-panel {
                padding: 35px 25px;
            }
        }
    </style>
</head>

<body>

    <div class="page-split-container">
        
        <!-- LEFT PANEL: DEPOSIT DESCRIPTION & INSTRUCTIONS -->
        <div class="description-left-panel">
            <div class="brand-header">
                <img src="../<?= htmlspecialchars($site_logo) ?>" alt="Tunza Waleti Logo">
                <h2>Tunza Waleti</h2>
            </div>

            <h1>Instant Mobile Money Deposits</h1>
            <p class="lead-desc">
                Deposit funds seamlessly into your primary wallet or directly allocate them to your earmarked savings goals via instant USSD Push.
            </p>

            <div class="guide-list">
                <div class="guide-item">
                    <div class="guide-icon"><i class="fas fa-mobile-alt"></i></div>
                    <div class="guide-text">
                        <h4>Direct Network Prompt</h4>
                        <p>Receive an automated USSD prompt on your phone right after initiating the request.</p>
                    </div>
                </div>

                <div class="guide-item">
                    <div class="guide-icon"><i class="fas fa-bullseye"></i></div>
                    <div class="guide-text">
                        <h4>Optional Goal Earmarking</h4>
                        <p>Optionally assign deposits straight to target savings goals to track project milestones.</p>
                    </div>
                </div>

                <div class="guide-item">
                    <div class="guide-icon"><i class="fas fa-shield-alt"></i></div>
                    <div class="guide-text">
                        <h4>Encrypted ClickPesa Gateway</h4>
                        <p>All transactions are verified using checksum signatures and network API encryption.</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- RIGHT PANEL: DEPOSIT WIZARD FORM -->
        <div class="deposit-right-panel">
            
            <!-- STEP 1: FORM INPUT -->
            <div id="depositStep1">
                <div class="deposit-title">
                    <h3>Deposit Funds</h3>
                    <p>Enter payment details to receive your USSD prompt.</p>
                </div>

                <div class="input-group">
                    <label for="dep_amount">Amount to Deposit (TZS)</label>
                    <input type="number" id="dep_amount" min="500" step="100" placeholder="e.g. 10000" required <?= $maintenance_mode === '1' ? 'disabled' : '' ?>>
                </div>

                <div class="input-group">
                    <label for="dep_phone">Mobile Money Phone Number</label>
                    <input type="text" id="dep_phone" placeholder="e.g. 0712345678 or 255712345678" required <?= $maintenance_mode === '1' ? 'disabled' : '' ?>>
                </div>

                <div class="input-group">
                    <label for="dep_method">Select Network Provider</label>
                    <select id="dep_method" <?= $maintenance_mode === '1' ? 'disabled' : '' ?>>
                        <option value="">-- Choose Operator Network --</option>
                        <option value="Vodacom M-Pesa">Vodacom M-Pesa</option>
                        <option value="Mixx by Yas (Tigo Pesa)">Mixx by Yas (Tigo Pesa)</option>
                        <option value="Airtel Money">Airtel Money</option>
                        <option value="HaloPesa">HaloPesa</option>
                    </select>
                </div>

                <div class="input-group">
                    <label for="dep_goal">Allocate to Goal (Optional)</label>
                    <select id="dep_goal" <?= $maintenance_mode === '1' ? 'disabled' : '' ?>>
                        <option value="">General Available Wallet</option>
                        <?php foreach ($user_goals as $goal): ?>
                            <option value="<?= $goal['id'] ?>"><?= htmlspecialchars($goal['title']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <button type="button" class="btn-submit" id="submitBtn" onclick="initiateClickPesaPayment()" <?= $maintenance_mode === '1' ? 'style="opacity: 0.6; cursor: not-allowed;"' : '' ?>>
                    <i class="fas fa-paper-plane"></i> Send Payment Prompt
                </button>

                <div class="option-links">
                    <p><a href="dashboard.php"><i class="fas fa-arrow-left"></i> Return to Dashboard</a></p>
                </div>
            </div>

            <!-- STEP 2: USSD WAITING SCREEN -->
            <div id="depositStep2" style="display: none;">
                <div class="status-box">
                    <div class="radar-spinner">
                        <div class="radar-circle"></div>
                        <div class="radar-icon"><i class="fas fa-mobile-alt"></i></div>
                    </div>

                    <h3 style="margin: 0 0 6px 0; color: var(--dark-purple);">Awaiting PIN Authorization</h3>
                    <p style="font-size: 13px; color: #555; margin: 0;">
                        USSD prompt sent to <strong id="displayPhone"></strong>. Please enter your <strong>Mobile Money PIN</strong> to authorize <strong>TZS <span id="displayAmount"></span></strong>.
                    </p>

                    <div class="progress-steps">
                        <div class="step-item active">
                            <i class="fas fa-check-circle" style="color: var(--light-green);"></i>
                            <span>USSD Push Triggered</span>
                        </div>
                        <div class="step-item active">
                            <i class="fas fa-spinner fa-spin" style="color: var(--primary-purple);"></i>
                            <span>Waiting for PIN entry...</span>
                        </div>
                        <div class="step-item">
                            <i class="far fa-circle"></i>
                            <span>Ledger update pending</span>
                        </div>
                    </div>
                </div>

                <form action="deposit.php" method="POST" id="confirmDepositForm">
                    <input type="hidden" name="action" value="confirm_deposit">
                    <input type="hidden" name="amount" id="final_amount">
                    <input type="hidden" name="payment_method" id="final_method">
                    <input type="hidden" name="goal_id" id="final_goal">
                    <input type="hidden" name="gateway_reference" id="gateway_reference">

                    <button type="submit" class="btn-submit" id="manualConfirmBtn">
                        <i class="fas fa-check-circle"></i> Complete Deposit
                    </button>
                    <button type="button" class="btn-cancel" onclick="cancelPayment()">Cancel Deposit</button>
                </form>
            </div>

        </div>

    </div>

    <!-- REUSABLE GENERAL POPUP MODAL DIALOG -->
    <div class="popup-overlay" id="popupModal">
        <div class="popup-box">
            <div class="popup-icon warning" id="popupIcon">
                <i class="fas fa-exclamation-triangle"></i>
            </div>
            <h3 class="popup-title" id="popupTitle">Attention Required</h3>
            <p class="popup-message" id="popupMessage">An issue was encountered during processing.</p>
            <button class="popup-btn" onclick="closePopup()">Dismiss</button>
        </div>
    </div>

    <!-- DEDICATED SYSTEM MAINTENANCE POPUP MODAL -->
    <div class="popup-overlay <?= $maintenance_mode === '1' ? 'active' : '' ?>" id="maintenanceModal">
        <div class="popup-box">
            <div class="popup-icon maintenance">
                <i class="fas fa-tools"></i>
            </div>
            <h3 class="popup-title">Scheduled Maintenance</h3>
            <p class="popup-message">
                Our payment gateway is currently undergoing scheduled system updates and upgrades. 
                <br><br>
                <strong>Deposits are temporarily unavailable</strong>. Please try again later or return to your dashboard.
            </p>
            <a href="dashboard.php" class="popup-btn"><i class="fas fa-arrow-left"></i> Return to Dashboard</a>
        </div>
    </div>

    <!-- CLIENT-SIDE SCRIPT ENGINE -->
    <script>
        const isMaintenance = <?= json_encode($maintenance_mode === '1') ?>;

        // General Modal Display Engine
        function showPopup(title, message, type = 'warning') {
            const modal = document.getElementById('popupModal');
            const iconContainer = document.getElementById('popupIcon');
            const titleElem = document.getElementById('popupTitle');
            const msgElem = document.getElementById('popupMessage');

            titleElem.textContent = title;
            msgElem.textContent = message;

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

        // Trigger Maintenance Popup if active
        function triggerMaintenancePopup() {
            document.getElementById('maintenanceModal').classList.add('active');
        }

        // USSD Payment Initiation Engine
        async function initiateClickPesaPayment() {
            if (isMaintenance) {
                triggerMaintenancePopup();
                return;
            }

            const amount = document.getElementById('dep_amount').value;
            const phone  = document.getElementById('dep_phone').value.trim();
            const method = document.getElementById('dep_method').value;
            const goalId = document.getElementById('dep_goal').value;
            const submitBtn = document.getElementById('submitBtn');

            if (!amount || amount <= 0) {
                showPopup('Invalid Amount', 'Please enter a valid deposit amount.', 'warning');
                return;
            }

            if (!phone || phone.length < 9) {
                showPopup('Invalid Phone Number', 'Please enter a valid mobile money number.', 'warning');
                return;
            }

            if (!method) {
                showPopup('Select Network', 'Please select your mobile network provider.', 'warning');
                return;
            }

            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Triggering USSD...';

            const formData = new FormData();
            formData.append('action', 'initiate_ussd');
            formData.append('amount', amount);
            formData.append('phone_number', phone);
            formData.append('goal_id', goalId);

            try {
                const response = await fetch('deposit.php', {
                    method: 'POST',
                    body: formData
                });

                const rawText = await response.text();
                let data;

                try {
                    data = JSON.parse(rawText);
                } catch (jsonErr) {
                    showPopup('Server Response Error', 'An unexpected response was returned by the server.', 'error');
                    console.error('Raw Response:', rawText);
                    return;
                }

                if (data.success) {
                    document.getElementById('displayAmount').innerText = parseFloat(amount).toLocaleString();
                    document.getElementById('displayPhone').innerText = phone;

                    document.getElementById('final_amount').value = amount;
                    document.getElementById('final_method').value = method;
                    document.getElementById('final_goal').value = goalId;
                    document.getElementById('gateway_reference').value = data.orderRef;

                    document.getElementById('depositStep1').style.display = 'none';
                    document.getElementById('depositStep2').style.display = 'block';

                } else {
                    showPopup('Gateway Rejection', data.message || 'Payment initiation failed.', 'error');
                }
            } catch (err) {
                showPopup('Network Connection Error', err.message, 'error');
            } finally {
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="fas fa-paper-plane"></i> Send Payment Prompt';
            }
        }

        function cancelPayment() {
            document.getElementById('depositStep2').style.display = 'none';
            document.getElementById('depositStep1').style.display = 'block';
        }

        // Auto-Trigger PHP Errors as Popups on DOM Ready
        document.addEventListener('DOMContentLoaded', function() {
            if (isMaintenance) {
                triggerMaintenancePopup();
            }
            <?php if (!empty($error)): ?>
                showPopup('Deposit Error', <?= json_encode($error) ?>, 'error');
            <?php endif; ?>
        });

        // Sync Tab Navigation
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