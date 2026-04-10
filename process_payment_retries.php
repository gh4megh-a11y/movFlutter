<?php
/**
 * process_payment_retries.php - AUTOMATED PAYMENT RETRY PROCESSOR
 * Place in: assets/libraries/webview/flutterwave/process_payment_retries.php
 * 
 * Run via cron job every 30 minutes:
 * */30 * * * * /usr/bin/php /var/www/html/assets/libraries/webview/flutterwave/process_payment_retries.php
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

$log_file = __DIR__ . '/retry_errors.log';

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
    
    file_put_contents($log_file, "\n" . str_repeat("=", 80) . "\n", FILE_APPEND);
    file_put_contents($log_file, "[RETRY PROCESSOR] " . date('Y-m-d H:i:s') . "\n", FILE_APPEND);
    
    // Get failed transactions that need retry
    $failedTx = $obj->MySQLSelect("SELECT 
        t.id,
        t.tPaymentTransactionId,
        t.iMemberId,
        t.vMemberType,
        t.amount,
        t.currency,
        COUNT(r.id) as retry_count
    FROM flutterwave_transactions t
    LEFT JOIN payment_retry_logs r ON t.tPaymentTransactionId = r.tTransactionId
    WHERE t.eStatus = 'failed'
    AND t.dCreatedAt > DATE_SUB(NOW(), INTERVAL 7 DAY)
    GROUP BY t.id
    HAVING retry_count < 3
    ORDER BY t.dCreatedAt ASC
    LIMIT 10");
    
    file_put_contents($log_file, "Found " . count($failedTx) . " transactions to retry\n", FILE_APPEND);
    
    $processedCount = 0;
    $successCount = 0;
    
    foreach ($failedTx as $tx) {
        try {
            $transactionId = $tx['tPaymentTransactionId'];
            $retryCount = $tx['retry_count'] + 1;
            
            file_put_contents($log_file, "\nProcessing retry #$retryCount for TX: $transactionId\n", FILE_APPEND);
            
            // Verify transaction status with Flutterwave
            $secResult = $obj->MySQLSelect("SELECT vValue FROM configurations_payment WHERE vName = 'FLUTTERWAVE_SECRET_KEY_LIVE' LIMIT 1");
            $secretKey = !empty($secResult) ? $secResult[0]['vValue'] : '';
            
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
            
            file_put_contents($log_file, "Verification HTTP: $httpCode\n", FILE_APPEND);
            
            if ($httpCode === 200 && ($response['status'] ?? '') === 'success') {
                $status = $response['data']['status'] ?? '';
                
                if (in_array($status, ['successful', 'completed'])) {
                    // Transaction is actually successful!
                    file_put_contents($log_file, "✓ Transaction became successful!\n", FILE_APPEND);
                    
                    $obj->MySQLQueryPerform('flutterwave_transactions', [
                        'eStatus' => 'successful',
                        'dCompletedAt' => date('Y-m-d H:i:s')
                    ], 'update', "tPaymentTransactionId = '$transactionId'");
                    
                    $successCount++;
                }
            }
            
            // Log retry attempt
            $obj->MySQLQueryPerform('payment_retry_logs', [
                'tTransactionId' => $transactionId,
                'iRetryAttempt' => $retryCount,
                'vReason' => 'Scheduled retry from cron',
                'vStatus' => 'processed'
            ], 'insert');
            
            $processedCount++;
            
        } catch (Exception $e) {
            file_put_contents($log_file, "ERROR processing retry: " . $e->getMessage() . "\n", FILE_APPEND);
        }
    }
    
    file_put_contents($log_file, "\nCompleted: $processedCount processed, $successCount recovered\n", FILE_APPEND);
    
} catch (Exception $e) {
    file_put_contents($log_file, "CRITICAL ERROR: " . $e->getMessage() . "\n", FILE_APPEND);
}
?>
