<?php
// api/clickpesa_callback.php

require_once '../database/config.php';
require_once 'sms.php'; // Include the SMS Service

// Normalize PDO handle
if (!isset($pdo) && isset($conn)) { $pdo = $conn; }

// ... [Keep existing Checksum and Payload Validation code] ...

try {
    $pdo->beginTransaction();

    // Fetch transaction record JOINED with user details
    $stmtTxn = $pdo->prepare("
        SELECT t.*, u.phone_number, u.full_name 
        FROM transactions t 
        JOIN users u ON t.user_id = u.id 
        WHERE t.reference_no = :ref FOR UPDATE
    ");
    $stmtTxn->execute(['ref' => $orderReference]);
    $txn = $stmtTxn->fetch(PDO::FETCH_ASSOC);

    if (!$txn) {
        $pdo->rollBack();
        exit();
    }

    $txnType    = strtolower($txn['type']);
    $amount     = number_format((float)$txn['amount'], 2);
    $userPhone  = $txn['phone_number'];
    $userName   = $txn['full_name'];
    $refNo      = $txn['reference_no'];

    if ($isSuccessEvent) {
        // Mark Transaction Completed
        $updateTxn = $pdo->prepare("UPDATE transactions SET status = 'completed', updated_at = NOW() WHERE id = :id");
        $updateTxn->execute(['id' => $txn['id']]);

        if ($txnType === 'deposit') {
            $updateWallet = $pdo->prepare("UPDATE wallets SET balance = balance + :amt WHERE user_id = :user_id");
            $updateWallet->execute(['amt' => $txn['amount'], 'user_id' => $txn['user_id']]);

            // Fetch new balance
            $balStmt = $pdo->prepare("SELECT balance FROM wallets WHERE user_id = :user_id LIMIT 1");
            $balStmt->execute(['user_id' => $txn['user_id']]);
            $newBalance = number_format((float)$balStmt->fetchColumn(), 2);

            // Construct Deposit SMS
            $smsText = "Habari {$userName}, Deposit ya TZS {$amount} imekamilika kikamilifu. Ref: {$refNo}. Salio jipya ni TZS {$newBalance}. Ahsante!";
        } else {
            // Withdrawal Completed
            $smsText = "Habari {$userName}, Kutoa fedha kwa TZS {$amount} kumekamilika kikamilifu. Ref: {$refNo}. Ahsante kwa kutumia Tunza Waleti!";
        }

        $pdo->commit();

        // Send SMS after committing transaction
        SmsService::sendSMS($pdo, $userPhone, $smsText);

    } elseif ($isFailedEvent) {
        // Mark Transaction Failed
        $updateTxn = $pdo->prepare("UPDATE transactions SET status = 'failed', updated_at = NOW() WHERE id = :id");
        $updateTxn->execute(['id' => $txn['id']]);

        if (in_array($txnType, ['withdraw', 'withdrawal', 'payout'])) {
            $refundWallet = $pdo->prepare("UPDATE wallets SET balance = balance + :amt WHERE user_id = :user_id");
            $refundWallet->execute(['amt' => $txn['amount'], 'user_id' => $txn['user_id']]);
        }

        $pdo->commit();

        // Failure Alert SMS
        $smsText = "Habari {$userName}, Mwamala wako wa TZS {$amount} (Ref: {$refNo}) haukufanikiwa. Salio lako limehifadhiwa kikamilifu.";
        SmsService::sendSMS($pdo, $userPhone, $smsText);
    }

} catch (PDOException $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
}