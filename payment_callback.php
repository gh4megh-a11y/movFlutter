<?php
/**
 * payment_callback.php - VERIFY, LOG & PROCESS FLUTTERWAVE PAYMENT
 * Place in: assets/libraries/webview/flutterwave/payment_callback.php
 */

header('Content-Type: application/json; charset=utf-8');

$log_file = __DIR__ . '/payment_errors.log';

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
    
    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    
    file_put_contents($log_file, "\n" . str_repeat("=", 80) . "\n", FILE_APPEND);
    file_put_contents($log_file, "[CALLBACK] " . date('Y-m-d H:i:s') . "\n", FILE_APPEND);
    file_put_contents($log_file, "Input: " . json_encode($input) . "\n", FILE_APPEND);
    
    $transactionId = $input['transaction_id'] ?? '';
    $txRef = $input['tx_ref'] ?? '';
    $tSessionId = $input['tSessionId'] ?? '';
    $GeneralMemberId = $input['GeneralMemberId'] ?? '';
    $GeneralUserType = $input['GeneralUserType'] ?? 'User';
    $PAGE_TYPE = $input['PAGE_TYPE'] ?? '';
    
    if (empty($transactionId) || empty($txRef)) {
        throw new Exception('Missing transaction information');
    }
    
    // Get Flutterwave secret key
    $secResult = $obj->MySQLSelect("SELECT vValue FROM configurations_payment WHERE vName = 'FLUTTERWAVE_SECRET_KEY_LIVE' LIMIT 1");
    $secretKey = !empty($secResult) ? $secResult[0]['vValue'] : '';
    
    file_put_contents($log_file, "Verifying transaction $transactionId with Flutterwave...\n", FILE_APPEND);
    
    // Verify transaction with Flutterwave
    $ch = curl_init("https://api.flutterwave.com/v3/transactions/{$transactionId}/verify");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $secretKey,
        ],
    ]);
    
    $raw = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    $response = json_decode($raw, true) ?? [];
    
    file_put_contents($log_file, "HTTP Code: $httpCode\n", FILE_APPEND);
    file_put_contents($log_file, "Response: " . json_encode($response) . "\n", FILE_APPEND);
    
    // Check if payment was successful
    if ($httpCode === 200 && ($response['status'] ?? '') === 'success') {
        $data = $response['data'] ?? [];
        $paymentStatus = $data['status'] ?? '';
        $amount = $data['amount'] ?? 0;
        $currency = $data['currency'] ?? 'USD';
        
        file_put_contents($log_file, "Payment status: $paymentStatus\n", FILE_APPEND);
        
        if (in_array($paymentStatus, ['successful', 'completed', 'pending'])) {
            file_put_contents($log_file, "✓ Payment verified successfully\n", FILE_APPEND);
            
            // Determine transaction status for database
            $dbStatus = ($paymentStatus === 'successful' || $paymentStatus === 'completed') ? 'successful' : 'pending';
            
            // Log transaction in database
            try {
                $existingTx = $obj->MySQLSelect("SELECT id FROM flutterwave_transactions WHERE tPaymentTransactionId = '$transactionId' LIMIT 1");
                
                if (empty($existingTx)) {
                    // Insert new transaction
                    $obj->MySQLQueryPerform('flutterwave_transactions', [
                        'iMemberId' => $GeneralMemberId,
                        'vMemberType' => $GeneralUserType,
                        'tPaymentTransactionId' => $transactionId,
                        'tTxRef' => $txRef,
                        'vPaymentMethod' => 'Flutterwave',
                        'amount' => $amount,
                        'currency' => $currency,
                        'eStatus' => $dbStatus,
                        'vPageType' => $PAGE_TYPE,
                        'tResponse' => json_encode($data),
                        'dCompletedAt' => ($dbStatus === 'successful') ? date('Y-m-d H:i:s') : null
                    ], 'insert');
                    
                    file_put_contents($log_file, "✓ Transaction logged to database\n", FILE_APPEND);
                } else {
                    // Update existing transaction
                    $obj->MySQLQueryPerform('flutterwave_transactions', [
                        'eStatus' => $dbStatus,
                        'tResponse' => json_encode($data),
                        'dCompletedAt' => ($dbStatus === 'successful') ? date('Y-m-d H:i:s') : null
                    ], 'update', "tPaymentTransactionId = '$transactionId'");
                    
                    file_put_contents($log_file, "✓ Transaction updated in database\n", FILE_APPEND);
                }
            } catch (Exception $dbErr) {
                file_put_contents($log_file, "⚠ Database error: " . $dbErr->getMessage() . "\n", FILE_APPEND);
            }
            
            // Update user wallet if successful
            if ($dbStatus === 'successful') {
                try {
                    updateUserWallet($GeneralMemberId, $GeneralUserType, $amount, $currency, $transactionId);
                    file_put_contents($log_file, "✓ User wallet updated\n", FILE_APPEND);
                } catch (Exception $walletErr) {
                    file_put_contents($log_file, "⚠ Wallet update error: " . $walletErr->getMessage() . "\n", FILE_APPEND);
                }
            }
            
            // Send confirmation email
            try {
                sendPaymentEmail($GeneralMemberId, $GeneralUserType, $transactionId, $amount, $currency, 'confirmation');
                file_put_contents($log_file, "✓ Confirmation email queued\n", FILE_APPEND);
            } catch (Exception $emailErr) {
                file_put_contents($log_file, "⚠ Email error: " . $emailErr->getMessage() . "\n", FILE_APPEND);
            }
            
            echo json_encode([
                'status' => 'success',
                'message' => 'Payment verified and processed',
                'transaction_id' => $transactionId,
                'amount' => $amount,
                'currency' => $currency,
                'payment_status' => $paymentStatus
            ]);
        } else {
            throw new Exception('Payment status: ' . $paymentStatus);
        }
    } else {
        // Log failed transaction
        try {
            $obj->MySQLQueryPerform('flutterwave_transactions', [
                'iMemberId' => $GeneralMemberId,
                'vMemberType' => $GeneralUserType,
                'tPaymentTransactionId' => $transactionId,
                'tTxRef' => $txRef,
                'vPaymentMethod' => 'Flutterwave',
                'eStatus' => 'failed',
                'vPageType' => $PAGE_TYPE,
                'tResponse' => json_encode($response)
            ], 'insert');
            
            file_put_contents($log_file, "✓ Failed transaction logged\n", FILE_APPEND);
        } catch (Exception $dbErr) {
            file_put_contents($log_file, "⚠ Failed to log failed transaction: " . $dbErr->getMessage() . "\n", FILE_APPEND);
        }
        
        throw new Exception('Payment verification failed: ' . ($response['message'] ?? 'Unknown error'));
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
 * Update user wallet after successful payment
 */
function updateUserWallet($memberId, $memberType, $amount, $currency, $transactionId) {
    global $obj;
    
    // Add to wallet based on member type
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
    $newWallet = $currentWallet + $amount;
    
    // Update wallet
    $obj->MySQLQueryPerform($table, [$walletField => $newWallet], 'update', "$idField = '$memberId'");
    
    return [
        'previous_balance' => $currentWallet,
        'amount_added' => $amount,
        'new_balance' => $newWallet
    ];
}

/**
 * Send payment notification email
 */
function sendPaymentEmail($memberId, $memberType, $transactionId, $amount, $currency, $type = 'confirmation') {
    global $obj, $tconfig;
    
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
    
    // Build email content
    $subject = "Payment Confirmation - Movvack";
    
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
            .footer { text-align: center; margin-top: 20px; color: #999; font-size: 12px; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h2>Payment Confirmation</h2>
                <p>Thank you for your payment!</p>
            </div>
            <div class='content'>
                <p>Hi $name,</p>
                <p>Your payment has been successfully processed. Below are the details:</p>
                
                <div class='detail'>
                    <span class='label'>Transaction ID:</span>
                    <span class='value'>$transactionId</span>
                </div>
                
                <div class='detail'>
                    <span class='label'>Amount:</span>
                    <span class='value'>" . number_format($amount, 2) . " $currency</span>
                </div>
                
                <div class='detail'>
                    <span class='label'>Date:</span>
                    <span class='value'>" . date('F d, Y H:i:s') . "</span>
                </div>
                
                <div class='detail'>
                    <span class='label'>Status:</span>
                    <span class='value' style='color: green;'>✓ Successful</span>
                </div>
                
                <p style='margin-top: 20px;'>If you have any questions, please contact our support team.</p>
                <p>Best regards,<br>Movvack Team</p>
            </div>
            <div class='footer'>
                <p>&copy; " . date('Y') . " Movvack. All rights reserved.</p>
            </div>
        </div>
    </body>
    </html>
    ";
    
    // Log email attempt
    $obj->MySQLQueryPerform('payment_email_logs', [
        'iMemberId' => $memberId,
        'tTransactionId' => $transactionId,
        'vEmailAddress' => $email,
        'vEmailType' => $type,
        'vStatus' => 'pending'
    ], 'insert');
    
    // Send email (using your existing email function)
    try {
        $headers = "MIME-Version: 1.0\r\n";
        $headers .= "Content-type: text/html; charset=UTF-8\r\n";
        $headers .= "From: noreply@movvack.com\r\n";
        
        $sent = mail($email, $subject, $message, $headers);
        
        if ($sent) {
            $obj->MySQLQueryPerform('payment_email_logs', [
                'vStatus' => 'sent',
                'dSentAt' => date('Y-m-d H:i:s')
            ], 'update', "tTransactionId = '$transactionId' AND vEmailType = '$type'");
            
            return true;
        } else {
            throw new Exception('Mail function failed');
        }
    } catch (Exception $e) {
        $obj->MySQLQueryPerform('payment_email_logs', [
            'vStatus' => 'failed',
            'vError' => $e->getMessage()
        ], 'update', "tTransactionId = '$transactionId' AND vEmailType = '$type'");
        
        throw $e;
    }
}
?>