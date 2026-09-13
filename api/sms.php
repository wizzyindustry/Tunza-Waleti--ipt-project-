<?php
// helpers/SmsService.php

class SmsService {

    /**
     * Format phone number to E.164 standard with leading '+'
     */
    private static function formatPhoneNumber($phone) {
        $cleanPhone = preg_replace('/[^0-9]/', '', $phone);

        if (strpos($cleanPhone, '0') === 0) {
            $cleanPhone = '255' . substr($cleanPhone, 1);
        }

        return '+' . $cleanPhone;
    }

    /**
     * Send SMS notification via MailerSend REST API
     */
    public static function sendSMS($pdo, $recipientPhone, $messageText, &$errorDetails = null) {
        if (empty($recipientPhone) || empty($messageText)) {
            $errorDetails = "Recipient phone number or message text is empty.";
            return false;
        }

        // 1. Fetch MailerSend Settings from system_settings Table
        $settings = [];
        try {
            $stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('mailersend_api_key', 'mailersend_from_number')");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $settings[$row['setting_key']] = trim($row['setting_value'] ?? '');
            }
        } catch (PDOException $e) {
            $errorDetails = "Database error loading SMS settings: " . $e->getMessage();
            return false;
        }

        $apiKey     = $settings['mailersend_api_key'] ?? '';
        $fromNumber = $settings['mailersend_from_number'] ?? '';

        if (empty($apiKey)) {
            $errorDetails = "MailerSend API Key is missing in System Settings.";
            return false;
        }

        if (empty($fromNumber)) {
            $errorDetails = "MailerSend 'From' Phone Number is missing in System Settings.";
            return false;
        }

        // Clean API key (strip whitespace / accidental quotes / Bearer prefix)
        $cleanApiKey   = trim(str_replace(['"', "'", 'Bearer '], '', $apiKey));
        $formattedFrom = self::formatPhoneNumber($fromNumber);
        $formattedTo   = self::formatPhoneNumber($recipientPhone);

        // 2. Build MailerSend REST API Payload
        $endpoint = 'https://api.mailersend.com/v1/sms';
        $payload  = [
            'from' => $formattedFrom,
            'to'   => [$formattedTo],
            'text' => $messageText
        ];

        // 3. Dispatch HTTP POST Request via cURL
        $curl = curl_init();

        curl_setopt_array($curl, [
            CURLOPT_URL            => $endpoint,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING       => '',
            CURLOPT_MAXREDIRS      => 10,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST  => 'POST',
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $cleanApiKey,
                'Content-Type: application/json',
                'Accept: application/json'
            ],
        ]);

        $response  = curl_exec($curl);
        $httpCode  = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $curlError = curl_error($curl);
        curl_close($curl);

        if ($curlError) {
            $errorDetails = "cURL Connection Failure: " . $curlError;
            return false;
        }

        // 4. Validate Response (HTTP 200 OK or 202 Accepted)
        if ($httpCode >= 200 && $httpCode < 300) {
            return true;
        }

        // Parse MailerSend Detailed Error Response
        $jsonResp = json_decode($response, true);
        if (isset($jsonResp['message'])) {
            $errorDetails = "MailerSend [HTTP {$httpCode}]: " . $jsonResp['message'];
            if (isset($jsonResp['errors'])) {
                $errorDetails .= " - Details: " . json_encode($jsonResp['errors']);
            }
        } else {
            $errorDetails = "MailerSend [HTTP {$httpCode}]: " . $response;
        }

        error_log("SMS Dispatch Error: " . $errorDetails);
        return false;
    }
}