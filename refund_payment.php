<?php
/**
 * refund_payment.php - HANDLE PAYMENT REFUNDS
 * Place in: assets/libraries/webview/flutterwave/refund_payment.php
 */

header('Content-Type: application/json; charset=utf-8');

$log_file = __DIR__ . '/refund_errors.log';

try {
    $base_path = __DIR__;
    $public_html = dirname(dirname(dirname(dirname($base_path))));
    $common_file = $public_html . '/common.php';
    
    if (!file_exists($common_file)) {
        throw new Exception('common.php not found');
    }
    
    include_once $common_file;
    
    if (!isset($GLOBALS['obj'])) {
        throw new Exception('Database object not found');
    }
    
    $obj = $GLOBALS['obj'];
    
    // Get request data
    $input = json_decode(file_get_contents('php://input'), true);
    
    $transactionId = $input['transaction_id'] ?? '';
    $refundAmount = isset($input['amount']) ? (float)$input['amount'] : null;
    $refundReason = $input['reason'] ?? 'Customer requested refund';
    $requestedBy = $input['requested_by'] ?? ''; // Admin ID
    
    file_put_contents($log_file, "\n" . str_repeat("=", 80) . "\n", FILE_APPEND);
    file_put_contents($log_file, "[REFUND] " . date('Y-m-d H:i:s') . "\n", FILE_APPEND);
    file_put_contents($log_file, "TransactionID: $transactionId, Amount: $refundAmount, Reason: $refundReason\n", FILE_APPEND);
    
    // Get transaction details
    $tx = $obj->MySQLSelect("SELECT * FROM flutterwave_transactions WHERE tPaymentTransactionId = '$transactionId' LIMIT 1");
    
    if (empty($tx)) {
        throw new Exception('Transaction not found');
    }
    
    $tx = $tx[0];
    
    // Check if transaction is refundable
    if (!in_array($tx['eStatus'], ['successful', 'completed'])) {
        throw new Exception('Only successful transactions can be refunded');
    }
    
    // Use full amount if not specified
    if ($refundAmount === null) {
        $refundAmount = $tx['amount'];
    }
    
    // Validate refund amount
    if ($refundAmount <= 0 || $refundAmount > $tx['amount']) {
        throw new Exception('Invalid refund amount');
    }
    
    file_put_contents($log_file, "✓ Transaction validated\n", FILE_APPEND);
    
    // Get Flutterwave secret key
    $secResult = $obj->MySQLSelect("SELECT vValue FROM configurations_payment WHERE vName = 'FLUTTERWAVE_SECRET_KEY_LIVE' LIMIT 1");
    $secretKey = !empty($secResult) ? $secResult[0]['vValue'] : '';
    
    // Call Flutterwave refund API
    file_put_contents($log_file, "Calling Flutterwave refund API...\n", FILE_APPEND);
    
    $ch = curl_init("https://api.flutterwave.com/v3/transactions/{$transactionId}/refund");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $secretKey,
        ],
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'amount' => $refundAmount
        ])
    ]);
    
    $raw = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    $response = json_decode($raw, true) ?? [];
    
    file_put_contents($log_file, "HTTP Code: $httpCode\n", FILE_APPEND);
    file_put_contents($log_file, "Response: " . json_encode($response) . "\n", FILE_APPEND);
    
    // Check if refund was successful
    if ($httpCode === 200 && ($response['status'] ?? '') === 'success') {
        $refundTxId = $response['data']['id'] ?? '';
        
        file_put_contents($log_file, "✓ Refund successful! RefundTxID: $refundTxId\n", FILE_APPEND);
        
        // Update transaction in database
        $obj->MySQLQueryPerform('flutterwave_transactions', [
            'eStatus' => 'refunded',
            'tRefundTransactionId' => $refundTxId,
            'vRefundReason' => $refundReason,
            'dRefundedAt' => date('Y-m-d H:i:s')
        ], 'update', "tPaymentTransactionId = '$transactionId'");
        
        // Reverse user wallet if full refund
        if ($refundAmount == $tx['amount']) {
            try {
                reverseUserWallet($tx['iMemberId'], $tx['vMemberType'], $refundAmount);
                file_put_contents($log_file, "✓ User wallet reversed\n", FILE_APPEND);
            } catch (Exception $e) {
                file_put_contents($log_file, "⚠ Wallet reversal error: " . $e->getMessage() . "\n", FILE_APPEND);
            }
        }
        
        // Send refund email
        try {
            sendRefundEmail($tx['iMemberId'], $tx['vMemberType'], $transactionId, $refundAmount, $tx['currency']);
            file_put_contents($log_file, "✓ Refund email sent\n", FILE_APPEND);
        } catch (Exception $e) {
            file_put_contents($log_file, "⚠ Email error: " . $e->getMessage() . "\n", FILE_APPEND);
        }
        
        echo json_encode([
            'status' => 'success',
            'message' => 'Refund processed successfully',
            'refund_transaction_id' => $refundTxId,
            'amount_refunded' => $refundAmount,
            'original_transaction_id' => $transactionId
        ]);
    } else {
        throw new Exception('Refund failed: ' . ($response['message'] ?? 'Unknown error'));
    }
    
} catch (Exception $e) {
    file_put_contents($log_file, "ERROR: " . $e->getMessage() . "\n", FILE_APPEND);
    
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}

/**
 * Reverse user wallet after refund
 */
function reverseUserWallet($memberId, $memberType, $refundAmount) {
    global $obj;
    
    if ($memberType === 'Driver') {
        $walletField = 'vWalletDriver';
        $table = 'register_driver';
        $idField = 'iDriverId';
    } elseif ($memberType === 'Hotel') {
        $walletField = 'vWallet';
        $table = 'hotel';
        $idField = 'iHotelId';
    } else {
        $walletField = 'vWallet';
        $table = 'register_user';
        $idField = 'iUserId';
    }
    
    // Get current wallet
    $userData = get_value($table, $walletField, $idField, $memberId);
    $currentWallet = (float)($userData[0][$walletField] ?? 0);
    $newWallet = max(0, $currentWallet - $refundAmount);
    
    // Update wallet
    $obj->MySQLQueryPerform($table, [$walletField => $newWallet], 'update', "$idField = '$memberId'");
}

/**
 * Send refund notification email
 */
function sendRefundEmail($memberId, $memberType, $transactionId, $refundAmount, $currency) {
    global $obj;
    
    // Get user email
    if ($memberType === 'Driver') {
        $userData = get_value('register_driver', 'vEmail, vName', 'iDriverId', $memberId);
    } elseif ($memberType === 'Hotel') {
        $userData = get_value('hotel', 'vEmail, vName', 'iHotelId', $memberId);
    } else {
        $userData = get_value('register_user', 'vEmail, vName', 'iUserId', $memberId);
    }
    
    if (empty($userData)) {
        throw new Exception('User not found');
    }
    
    $email = $userData[0]['vEmail'];
    $name = $userData[0]['vName'];
    
    $subject = "Refund Processed - Movvack";
    $message = "
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; background: #f5f5f5; }
            .header { background: #667eea; color: white; padding: 20px; border-radius: 8px; text-align: center; }
            .content { background: white; padding: 20px; margin-top: 20px; border-radius: 8px; }
            .detail { margin: 10px 0; }
            .label { font-weight: bold; color: #333; }
            .value { color: #666; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h2>Refund Processed</h2>
                <p>Your refund has been successfully processed</p>
            </div>
            <div class='content'>
                <p>Hi $name,</p>
                <p>We've processed your refund. Here are the details:</p>
                
                <div class='detail'>
                    <span class='label'>Original Transaction:</span>
                    <span class='value'>$transactionId</span>
                </div>
                
                <div class='detail'>
                    <span class='label'>Refund Amount:</span>
                    <span class='value'>" . number_format($refundAmount, 2) . " $currency</span>
                </div>
                
                <div class='detail'>
                    <span class='label'>Date Processed:</span>
                    <span class='value'>" . date('F d, Y H:i:s') . "</span>
                </div>
                
                <p style='margin-top: 20px; color: green;'>✓ The refund has been credited to your original payment method.</p>
            </div>
        </div>
    </body>
    </html>
    ";
    
    $headers = "MIME-Version: 1.0\r\n";
    $headers .= "Content-type: text/html; charset=UTF-8\r\n";
    $headers .= "From: noreply@movvack.com\r\n";
    
    mail($email, $subject, $message, $headers);
    
    // Log email
    $obj->MySQLQueryPerform('payment_email_logs', [
        'iMemberId' => $memberId,
        'tTransactionId' => $transactionId,
        'vEmailAddress' => $email,
        'vEmailType' => 'refund',
        'vStatus' => 'sent',
        'dSentAt' => date('Y-m-d H:i:s')
    ], 'insert');
}
?>