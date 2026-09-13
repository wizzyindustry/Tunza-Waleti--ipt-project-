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
    
    // Pure alphanumeric reference (No hyphens allowed by ClickPesa)
    $orderRef    = 'DEP' . date('YmdHis') . rand(100, 999);

    // Format phone number to international standard (2557XXXXXXXX)
    $phoneNumber = preg_replace('/[^0-9]/', '', $phoneNumber);
    if (strpos($phoneNumber, '0') === 0) {
        $phoneNumber = '255' . substr($phoneNumber, 1);
    }

    if ($amount === false || $amount <= 0 || empty($phoneNumber)) {
        echo json_encode(['success' => false, 'message' => 'Invalid amount or phone number.']);
        exit;
    }

    $bearerToken = getClickPesaBearerToken();
    if (!$bearerToken) {
        echo json_encode(['success' => false, 'message' => 'Failed to generate JWT token with ClickPesa credentials.']);
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
        echo json_encode(['success' => false, 'message' => 'cURL Connection Error: ' . $err]);
        exit;
    }

    $resData = json_decode($response, true);

    // ClickPesa returns "status": "PROCESSING" or "success": true on valid USSD push requests
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
                     ?? 'ClickPesa rejected the request.';

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
            $error = "Invalid transaction parameters.";
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
</head>

<style>
    .deposit-wrapper {
        max-width: 500px;
        margin: 40px auto;
        padding: 25px;
        background: #ffffff;
        border-radius: 16px;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
    }
    .title {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        margin-bottom: 25px;
    }
    .title h2 { font-size: 28px; color: #333; margin-bottom: 5px; }
    .title p { font-size: 14px; color: #666; }

    #depositStep1, #depositStep2 {
        margin-top: 15px;
    }
    .input-group {
        margin: 12px 0;
    }
    .input-group input, .input-group select {
        width: 100%;
        height: 50px;
        border: 1px solid #ddd;
        border-radius: 10px;
        padding: 0 15px;
        outline: none;
        background-color: #F9F9F9;
        font-size: 15px;
        box-sizing: border-box;
        transition: 0.3s ease;
    }
    .input-group input:focus, .input-group select:focus {
        border-color: #510049;
        background-color: #fff;
    }
    .input-group label {
        margin-bottom: 6px;
        display: block;
        font-weight: 600;
        font-size: 14px;
        color: #444;
    }
    .btn {
        width: 100%;
        margin-top: 15px;
    }
    .btn button {
        width: 100%;
        height: 50px;
        border: none;
        border-radius: 10px;
        outline: none;
        background-color: #510049;
        color: #fff;
        font-size: 17px;
        font-weight: bold;
        cursor: pointer;
        transition: 0.3s ease;
    }
    .btn button:hover {
        background-color: #000000;
    }
    .btn-secondary button {
        background-color: #e0e0e0;
        color: #333;
    }
    .btn-secondary button:hover {
        background-color: #ccc;
    }
    .option {
        text-align: center;
        margin-top: 20px;
    }
    .option p a {
        text-decoration: none;
        color: #510049;
        font-weight: bold;
        font-size: 14px;
    }
    .alert {
        padding: 12px;
        background-color: #f8d7da;
        color: #721c24;
        border-radius: 8px;
        margin-bottom: 15px;
        font-size: 14px;
    }

    /* ANIMATED WAITING STATE STYLES */
    .status-box {
        text-align: center;
        padding: 30px 20px;
        background: #FAF7FA;
        border: 1px solid #F0E6EF;
        border-radius: 16px;
        margin-bottom: 20px;
    }

    /* Pulse Radar Animation */
    .radar-spinner {
        position: relative;
        width: 80px;
        height: 80px;
        margin: 0 auto 20px auto;
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
        width: 50px;
        height: 50px;
        border-radius: 50%;
        background: #510049;
        color: #ffffff;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 22px;
        box-shadow: 0 4px 15px rgba(81, 0, 73, 0.3);
    }

    @keyframes pulse-ring {
        0% { transform: scale(0.6); opacity: 0.9; }
        80%, 100% { transform: scale(1.4); opacity: 0; }
    }

    /* Progress Steps List */
    .progress-steps {
        text-align: left;
        margin: 20px 0 10px 0;
        padding: 0 10px;
    }
    .step-item {
        display: flex;
        align-items: center;
        gap: 12px;
        margin-bottom: 12px;
        font-size: 14px;
        color: #777;
    }
    .step-item.active {
        color: #510049;
        font-weight: 600;
    }
    .step-item i {
        font-size: 16px;
    }

    /* MAINTENANCE MODAL DIALOG STYLES */
    .modal-overlay {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.6);
        backdrop-filter: blur(5px);
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 99999;
        opacity: 0;
        visibility: hidden;
        transition: all 0.3s ease;
    }
    .modal-overlay.active {
        opacity: 1;
        visibility: visible;
    }
    .modal-box {
        background: #ffffff;
        width: 90%;
        max-width: 420px;
        padding: 30px;
        border-radius: 20px;
        box-shadow: 0 20px 40px rgba(0,0,0,0.25);
        text-align: center;
        transform: scale(0.8);
        transition: all 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
    }
    .modal-overlay.active .modal-box {
        transform: scale(1);
    }
    .modal-icon {
        width: 70px;
        height: 70px;
        border-radius: 50%;
        margin: 0 auto 20px auto;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 32px;
        background: #fdeaea;
        color: #e74c3c;
    }
    .modal-box h3 {
        font-size: 22px;
        color: #2c3e50;
        margin-bottom: 10px;
    }
    .modal-box p {
        font-size: 14px;
        color: #666;
        line-height: 1.6;
        margin-bottom: 25px;
    }
    .modal-btn {
        width: 100%;
        padding: 12px;
        border: none;
        border-radius: 10px;
        background: #510049;
        color: #fff;
        font-size: 15px;
        font-weight: 600;
        cursor: pointer;
        transition: 0.3s ease;
        text-decoration: none;
        display: block;
    }
    .modal-btn:hover {
        background: #000;
    }
</style>

<body>

    <div class="deposit-wrapper">
        <div class="title">
            <h2>Deposit Funds</h2>
            <p class="subtitle">Tunza Waleti Digital Savings</p>
        </div>

        <?php if (!empty($error)): ?>
            <div class="alert error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <!-- STEP 1: Deposit Form -->
        <div id="depositStep1">
            <div class="input-group">
                <label for="dep_amount">Amount to Deposit (TZS)</label>
                <input type="number" id="dep_amount" min="500" step="100" placeholder="e.g. 10000" required <?= $maintenance_mode === '1' ? 'disabled' : '' ?>>
            </div>

            <div class="input-group">
                <label for="dep_phone">Mobile Money Phone Number</label>
                <input type="text" id="dep_phone" placeholder="e.g. 255712345678" required <?= $maintenance_mode === '1' ? 'disabled' : '' ?>>
            </div>

            <div class="input-group">
                <label for="dep_method">Select Mobile Network</label>
                <select id="dep_method" <?= $maintenance_mode === '1' ? 'disabled' : '' ?>>
                    <option value="">--------Select Mobile Network--------</option>
                    <option value="Vodacom M-Pesa">Vodacom M-Pesa</option>
                    <option value="Mixx by Yas (Tigo Pesa)">Mixx by Yas (Tigo Pesa)</option>
                    <option value="Airtel Money">Airtel Money</option>
                    <option value="HaloPesa">HaloPesa</option>
                </select>
            </div>

            <div class="input-group">
                <label for="dep_goal">Allocate to Goal (Optional)</label>
                <select id="dep_goal" <?= $maintenance_mode === '1' ? 'disabled' : '' ?>>
                    <option value="">General Wallet Balance</option>
                    <?php foreach ($user_goals as $goal): ?>
                        <option value="<?= $goal['id'] ?>"><?= htmlspecialchars($goal['title']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="btn">
                <button type="button" id="submitBtn" onclick="initiateClickPesaPayment()">Pay with Mobile Money</button>
            </div>

            <div class="option">
                <p><a href="dashboard.php">&larr; Back to Dashboard</a></p>
            </div>
        </div>

        <!-- STEP 2: Waiting for Mobile PIN Confirmation -->
        <div id="depositStep2" style="display: none;">
            <div class="status-box">
                <div class="radar-spinner">
                    <div class="radar-circle"></div>
                    <div class="radar-icon">
                        <i class="fas fa-mobile-alt"></i>
                    </div>
                </div>

                <h3 style="margin-top: 10px; color: #222;">Awaiting Confirmation</h3>
                <p style="font-size: 14px; color: #555; margin-top: 6px;">
                    A USSD popup has been sent to <strong id="displayPhone"></strong>. Please enter your <strong>Mobile Money PIN</strong> to authorize the payment of <strong>TZS <span id="displayAmount"></span></strong>.
                </p>

                <div class="progress-steps">
                    <div class="step-item active" id="step1-status">
                        <i class="fas fa-check-circle" style="color: #28a745;"></i>
                        <span>USSD Request Sent</span>
                    </div>
                    <div class="step-item active" id="step2-status">
                        <i class="fas fa-spinner fa-spin" style="color: #510049;"></i>
                        <span>Waiting for PIN entry on your phone...</span>
                    </div>
                    <div class="step-item" id="step3-status">
                        <i class="far fa-circle"></i>
                        <span>Verifying transaction with network</span>
                    </div>
                </div>
            </div>

            <form action="deposit.php" method="POST" id="confirmDepositForm">
                <input type="hidden" name="action" value="confirm_deposit">
                <input type="hidden" name="amount" id="final_amount">
                <input type="hidden" name="payment_method" id="final_method">
                <input type="hidden" name="goal_id" id="final_goal">
                <input type="hidden" name="gateway_reference" id="gateway_reference">

                <div class="btn">
                    <button type="submit" id="manualConfirmBtn">I Have Entered My PIN</button>
                </div>
                <div class="btn btn-secondary" style="margin-top: 10px;">
                    <button type="button" onclick="cancelPayment()">Cancel Payment</button>
                </div>
            </form>
        </div>

    </div>

    <!-- MAINTENANCE MODE POPUP MODAL -->
    <div class="modal-overlay <?= $maintenance_mode === '1' ? 'active' : '' ?>" id="maintenanceModal">
        <div class="modal-box">
            <div class="modal-icon">
                <i class="fas fa-tools"></i>
            </div>
            <h3>System Under Maintenance</h3>
            <p>
                Our payment gateway is currently undergoing routine maintenance and upgrades. 
                <strong>Deposits are temporarily disabled</strong>. Please check back shortly.
            </p>
            <a href="dashboard.php" class="modal-btn">Return to Dashboard</a>
        </div>
    </div>

    <script>
        const isMaintenance = <?= json_encode($maintenance_mode === '1') ?>;
        let pollTimer = null;

        // Cross-tab Synchronization Listener
        window.addEventListener('storage', function(event) {
            if (event.key === 'tunza_session_update') {
                const sessionData = JSON.parse(event.newValue);
                if (sessionData && sessionData.role === 'admin') {
                    window.location.href = '../admin/dashboard.php';
                }
            }
        });

        async function initiateClickPesaPayment() {
            if (isMaintenance) {
                document.getElementById('maintenanceModal').classList.add('active');
                return;
            }

            const amount = document.getElementById('dep_amount').value;
            const phone  = document.getElementById('dep_phone').value;
            const method = document.getElementById('dep_method').value;
            const goalId = document.getElementById('dep_goal').value;
            const submitBtn = document.getElementById('submitBtn');

            if (!amount || amount <= 0) {
                alert('Please enter a valid deposit amount.');
                return;
            }

            if (!phone || phone.length < 9) {
                alert('Please enter a valid phone number (e.g. 255712345678).');
                return;
            }

            if (!method) {
                alert('Please select a valid mobile money network.');
                return;
            }

            submitBtn.disabled = true;
            submitBtn.innerText = "Sending USSD Prompt...";

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
                    alert('PHP Server Response Error:\n\n' + rawText.substring(0, 300));
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

                    // Switch view to Step 2 (Loading state)
                    document.getElementById('depositStep1').style.display = 'none';
                    document.getElementById('depositStep2').style.display = 'block';

                } else {
                    alert('ClickPesa Error: ' + (data.message || 'Payment initiation rejected.'));
                    console.error('API Error Response:', data);
                }
            } catch (err) {
                alert('Network Error: ' + err.message);
            } finally {
                submitBtn.disabled = false;
                submitBtn.innerText = "Pay with Mobile Money";
            }
        }

        function cancelPayment() {
            if (pollTimer) clearInterval(pollTimer);
            document.getElementById('depositStep2').style.display = 'none';
            document.getElementById('depositStep1').style.display = 'block';
        }
    </script>
</body>

</html>