<?php
// api/initiate_withdrawal.php

header('Content-Type: application/json');

// Suppress raw HTML errors to ensure clean JSON responses
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
ini_set('display_errors', 0);

require_once '../database/auth.php';
require_once '../database/config.php';

if (!isset($pdo) && isset($conn)) {
    $pdo = $conn;
}

// ------------------------------------------------------------------
// CLICKPESA CONFIGURATION
// ------------------------------------------------------------------
define('CLICKPESA_CLIENT_ID',    'IDXaiifCqpiVlSY0dxAguzc1s2EHgeQD');
define('CLICKPESA_API_KEY',      'SKj94nhpa4oDQav9GUJOtC6gwumVxwJOVhxp10a54s');
define('CLICKPESA_CHECKSUM_KEY', 'CHKawxG15lEwseaRTNBcoGOaGwAIIeI1RPJ');

$user_id = $_SESSION['user_id'] ?? null;

if (!$user_id) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access. Please log in.']);
    exit;
}

// 1. Parse Input Data (JSON payload or POST)
$rawInput = file_get_contents('php://input');
$input    = json_decode($rawInput, true) ?? $_POST;

$amount      = filter_var($input['amount'] ?? 0, FILTER_VALIDATE_FLOAT);
$phoneNumber = trim($input['phone_number'] ?? $input['phoneNumber'] ?? '');

// Generate pure alphanumeric reference (No hyphens allowed)
$orderRef = 'WTH' . date('YmdHis') . rand(100, 999);

// Standardize phone number format (2557XXXXXXXX)
$phoneNumber = preg_replace('/[^0-9]/', '', $phoneNumber);
if (strpos($phoneNumber, '0') === 0) {
    $phoneNumber = '255' . substr($phoneNumber, 1);
}

if (!$amount || $amount <= 0 || empty($phoneNumber)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Valid amount and phone number are required.']);
    exit;
}

// 2. Check user's wallet balance in Database
try {
    $stmt = $pdo->prepare("SELECT balance FROM wallets WHERE user_id = :user_id");
    $stmt->execute(['user_id' => $user_id]);
    $wallet = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$wallet || $wallet['balance'] < $amount) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Insufficient wallet balance for this withdrawal.']);
        exit;
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error during balance verification: ' . $e->getMessage()]);
    exit;
}

// 3. Helper: Fetch Active JWT Bearer Token
function fetchClickPesaToken() {
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
    $err      = curl_error($curl);
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

// 4. Helper: Create HMAC-SHA256 Checksum Signature
function createClickPesaChecksum($checksumKey, $payload) {
    unset($payload['checksum']);

    $canonicalize = function ($obj) use (&$canonicalize) {
        if (!is_array($obj)) return $obj;
        if (array_values($obj) === $obj) return array_map($canonicalize, $obj);
        ksort($obj);
        $result = [];
        foreach ($obj as $k => $v) { $result[$k] = $canonicalize($v); }
        return $result;
    };

    $canonicalPayload = $canonicalize($payload);
    $payloadString    = json_encode($canonicalPayload, JSON_UNESCAPED_SLASHES);

    return hash_hmac('sha256', $payloadString, $checksumKey);
}

// 5. Retrieve Bearer Token
$bearerToken = fetchClickPesaToken();
if (!$bearerToken) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Failed to authenticate with ClickPesa gateway.']);
    exit;
}

// 6. Construct Mobile Money Payout Payload
$payloadData = [
    'amount'         => (float)$amount,
    'phoneNumber'    => (string)$phoneNumber,
    'orderReference' => (string)$orderRef
];

$payloadData['checksum'] = createClickPesaChecksum(CLICKPESA_CHECKSUM_KEY, $payloadData);

// 7. Execute Call to ClickPesa Payout Endpoint
$curl = curl_init();
curl_setopt_array($curl, [
    CURLOPT_URL => "https://api.clickpesa.com/third-parties/payouts/create-mobile-money-payout",
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_ENCODING => "",
    CURLOPT_MAXREDIRS => 10,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
    CURLOPT_CUSTOMREQUEST => "POST",
    CURLOPT_POSTFIELDS => json_encode($payloadData),
    CURLOPT_HTTPHEADER => [
        "Authorization: " . $bearerToken,
        "Content-Type: application/json"
    ],
]);

$response = curl_exec($curl);
$err      = curl_error($curl);
$httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
curl_close($curl);

if ($err) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'cURL Error: ' . $err]);
    exit;
}

$resData = json_decode($response, true);

// Check if ClickPesa accepted the payout
$isSuccess = ($httpCode >= 200 && $httpCode < 300) && (
    (isset($resData['status']) && in_array($resData['status'], ['PROCESSING', 'PENDING', 'SUCCESS', 'SUCCESSFUL'])) ||
    (isset($resData['success']) && $resData['success'] === true)
);

if ($isSuccess) {
    try {
        $pdo->beginTransaction();

        // Deduct from wallet balance
        $deductStmt = $pdo->prepare("
            UPDATE wallets 
            SET balance = balance - :amount 
            WHERE user_id = :user_id
        ");
        $deductStmt->execute([
            'amount'  => $amount,
            'user_id' => $user_id
        ]);

        // Insert transaction record into database
        $txnStmt = $pdo->prepare("
            INSERT INTO transactions (reference_no, user_id, type, amount, status, payment_method, description) 
            VALUES (:ref, :user_id, 'withdrawal', :amount, 'completed', :method, :desc)
        ");
        $txnStmt->execute([
            'ref'     => $orderRef,
            'user_id' => $user_id,
            'amount'  => $amount,
            'method'  => 'Mobile Money Payout',
            'desc'    => 'Withdrawal to ' . $phoneNumber
        ]);

        $pdo->commit();

        echo json_encode([
            'success'      => true,
            'message'      => 'Withdrawal initiated successfully! Funds are being disbursed to your mobile wallet.',
            'orderRef'     => $orderRef,
            'amount'       => $amount,
            'phoneNumber'  => $phoneNumber,
            'api_response' => $resData
        ]);

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database update error: ' . $e->getMessage()]);
    }
} else {
    $errorMessage = $resData['message'] 
                 ?? $resData['error'] 
                 ?? $resData['description'] 
                 ?? 'ClickPesa rejected the payout request.';

    http_response_code($httpCode ?: 400);
    echo json_encode([
        'success'  => false,
        'message'  => $errorMessage,
        'raw_body' => $resData
    ]);
}
?>