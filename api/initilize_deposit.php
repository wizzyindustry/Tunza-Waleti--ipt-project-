<?php
// api/initiate_deposit.php

header('Content-Type: application/json');

// ------------------------------------------------------------------
// CLICKPESA CREDENTIALS
// ------------------------------------------------------------------
define('CLICKPESA_CLIENT_ID',    'IDXaiifCqpiVlSY0dxAguzc1s2EHgeQD');
define('CLICKPESA_API_KEY',      'SKj94nhpa4oDQav9GUJOtC6gwumVxwJOVhxp10a54s');
define('CLICKPESA_CHECKSUM_KEY', 'CHKawxG15lEwseaRTNBcoGOaGwAIIeI1RPJ');

// 1. Parse Input Data (Handles both JSON payloads and Form Data POST)
$rawInput = file_get_contents('php://input');
$input    = json_decode($rawInput, true) ?? $_POST;

$amount      = filter_var($input['amount'] ?? 0, FILTER_VALIDATE_FLOAT);
$phoneNumber = trim($input['phone_number'] ?? $input['phoneNumber'] ?? '');
$orderRef = 'DEP' . date('YmdHis') . rand(100, 999);

// Format Phone Number to International standard (2557XXXXXXXX)
$phoneNumber = preg_replace('/[^0-9]/', '', $phoneNumber);
if (strpos($phoneNumber, '0') === 0) {
    $phoneNumber = '255' . substr($phoneNumber, 1);
}

if (!$amount || $amount <= 0 || empty($phoneNumber)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Valid amount and phone number are required.'
    ]);
    exit;
}

// 2. Function to Get Dynamic Bearer Token from ClickPesa
function fetchClickPesaToken() {
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

    if ($err) {
        return ['error' => 'Token Generation cURL Error: ' . $err];
    }

    $data = json_decode($response, true);
    if (isset($data['success']) && $data['success'] === true && !empty($data['token'])) {
        $token = $data['token'];
        // Ensure "Bearer " prefix is attached properly
        if (strpos($token, 'Bearer ') !== 0) {
            $token = 'Bearer ' . $token;
        }
        return ['token' => $token];
    }

    return ['error' => $data['message'] ?? 'Failed to retrieve access token from ClickPesa.'];
}

// 3. Helper function for ClickPesa HMAC-SHA256 Checksum
function createClickPesaChecksum($checksumKey, $payload) {
    unset($payload['checksum']);
    
    $canonicalize = function ($obj) use (&$canonicalize) {
        if (!is_array($obj)) return $obj;
        if (array_values($obj) === $obj) return array_map($canonicalize, $obj);
        ksort($obj);
        $result = [];
        foreach ($obj as $k => $v) { 
            $result[$k] = $canonicalize($v); 
        }
        return $result;
    };

    $canonicalPayload = $canonicalize($payload);
    $payloadString    = json_encode($canonicalPayload, JSON_UNESCAPED_SLASHES);

    return hash_hmac('sha256', $payloadString, $checksumKey);
}

// 4. Fetch Active Bearer Token
$tokenResult = fetchClickPesaToken();
if (isset($tokenResult['error'])) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => $tokenResult['error']
    ]);
    exit;
}

$bearerToken = $tokenResult['token'];

// 5. Build Data Payload & Calculate Checksum Signature
$payloadData = [
    'amount'         => (string)$amount,
    'currency'       => 'TZS',
    'orderReference' => (string)$orderRef,
    'phoneNumber'    => (string)$phoneNumber
];

$payloadData['checksum'] = createClickPesaChecksum(CLICKPESA_CHECKSUM_KEY, $payloadData);

// 6. Send USSD Request to ClickPesa API
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
$err      = curl_error($curl);
$httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
curl_close($curl);

// 7. Process and Return Response to Frontend Client
if ($err) {
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'message' => 'cURL Error: ' . $err
    ]);
    exit;
}

$resData = json_decode($response, true);

if ($httpCode >= 200 && $httpCode < 300 && isset($resData['success']) && $resData['success'] === true) {
    echo json_encode([
        'success'      => true,
        'orderRef'     => $orderRef,
        'phoneNumber'  => $phoneNumber,
        'amount'       => $amount,
        'api_response' => $resData
    ]);
} else {
    http_response_code($httpCode ?: 400);
    echo json_encode([
        'success'      => false,
        'message'      => $resData['message'] ?? 'ClickPesa rejected the USSD push request.',
        'api_response' => $resData
    ]);
}
?>