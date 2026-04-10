<?php
/**
 * webhook_receiver.php - RECEIVE FLUTTERWAVE WEBHOOK EVENTS (FIXED)
 * Place in: assets/libraries/webview/flutterwave/webhook_receiver.php
 * 
 * Configure in Flutterwave Dashboard:
 * Webhook URL: https://apiservice.movvack.com/assets/libraries/webview/flutterwave/webhook_receiver.php
 */

ob_start();
header('Content-Type: application/json; charset=utf-8');

$log_file = __DIR__ . '/webhook_errors.log';
$success = false;

try {
    file_put_contents($log_file, "\n" . str_repeat("=", 80) . "\n", FILE_APPEND);
    file_put_contents($log_file, "[WEBHOOK] " . date('Y-m-d H:i:s') . "\n", FILE_APPEND);
    
    // Get webhook payload
    $rawPayload = file_get_contents('php://input');
    $payload = json_decode($rawPayload, true);
    
    file_put_contents($log_file, "Raw payload length: " . strlen($rawPayload) . "\n", FILE_APPEND);
    file_put_contents($log_file, "Payload: " . json_encode($payload) . "\n", FILE_APPEND);
    
    // Include common
    $base_path = __DIR__;
    $public_html = dirname(dirname(dirname(dirname($base_path))));
    $common_file = $public_html . '/common.php';
    
    if (!file_exists($common_file)) {
        throw new Exception('common.php not found at: ' . $common_file);
    }
    
    @include_once $common_file;
    
    if (!isset($GLOBALS['obj'])) {
        file_put_contents($log_file, "ERROR: Database object not found in GLOBALS\n", FILE_APPEND);
        throw new Exception('Database object not found');
    }
    
    $obj = $GLOBALS['obj'];
    
    file_put_contents($log_file, "✓ Database object loaded\n", FILE_APPEND);
    
    // Validate payload
    if (empty($payload)) {
        file_put_contents($log_file, "ERROR: Empty payload\n", FILE_APPEND);
        throw new Exception('Empty webhook payload');
    }
    
    // Get event type and data
    $eventType = $payload['event'] ?? '';
    $data = $payload['data'] ?? [];
    $transactionId = $data['id'] ?? '';
    $status = $data['status'] ?? '';
    
    file_put_contents($log_file, "Event: $eventType, TransactionID: $transactionId, Status: $status\n", FILE_APPEND);
    
    if (empty($eventType) || empty($transactionId)) {
        file_put_contents($log_file, "ERROR: Missing event or transaction ID\n", FILE_APPEND);
        throw new Exception('Missing event or transaction ID');
    }
    
    // Log webhook in database
    try {
        $obj->MySQLQueryPerform('flutterwave_webhooks', [
            'tTransactionId' => $transactionId,
            'tEventType' => $eventType,
            'vStatus' => $status,
            'tPayload' => json_encode($payload),
            'vResponse' => 'received'
        ], 'insert');
        
        file_put_contents($log_file, "✓ Webhook logged to database\n", FILE_APPEND);
    } catch (Exception $dbErr) {
        file_put_contents($log_file, "⚠ Database logging error: " . $dbErr->getMessage() . "\n", FILE_APPEND);
        // Don't fail - continue processing even if DB logging fails
    }
    
    // Handle different event types
    file_put_contents($log_file, "Processing event type: $eventType\n", FILE_APPEND);
    
    switch ($eventType) {
        case 'charge.completed':
            handleChargeCompleted($obj, $data, $log_file);
            $success = true;
            break;
            
        case 'charge.failed':
            handleChargeFailed($obj, $data, $log_file);
            $success = true;
            break;
            
        case 'charge.reversed':
            handleChargeReversed($obj, $data, $log_file);
            $success = true;
            break;
            
        default:
            file_put_contents($log_file, "⚠ Unknown event type: $eventType\n", FILE_APPEND);
            $success = true; // Still return 200 for unknown events
    }
    
    file_put_contents($log_file, "✓ Webhook processed successfully\n", FILE_APPEND);
    
    // Send success response to Flutterwave
    ob_end_clean();
    http_response_code(200);
    echo json_encode(['status' => 'received', 'success' => true]);
    
} catch (Exception $e) {
    file_put_contents($log_file, "ERROR: " . $e->getMessage() . "\n", FILE_APPEND);
    file_put_contents($log_file, "Stack trace: " . $e->getTraceAsString() . "\n", FILE_APPEND);
    
    ob_end_clean();
    http_response_code(200); // Still return 200 so Flutterwave doesn't retry
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

/**
 * Handle charge.completed webhook event
 */
function handleChargeCompleted($obj, $data, $log_file) {
    try {
        $transactionId = $data['id'] ?? '';
        $status = $data['status'] ?? '';
        $amount = $data['amount'] ?? 0;
        $currency = $data['currency'] ?? '';
        
        file_put_contents($log_file, "  Processing charge.completed\n", FILE_APPEND);
        
        if (empty($transactionId)) {
            throw new Exception('Missing transaction ID in charge.completed');
        }
        
        // Update transaction status
        $obj->MySQLQueryPerform('flutterwave_transactions', [
            'eStatus' => 'successful',
            'dCompletedAt' => date('Y-m-d H:i:s')
        ], 'update', "tPaymentTransactionId = '$transactionId'");
        
        file_put_contents($log_file, "  ✓ Transaction marked as successful\n", FILE_APPEND);
        
    } catch (Exception $e) {
        file_put_contents($log_file, "  ⚠ Error in charge.completed: " . $e->getMessage() . "\n", FILE_APPEND);
    }
}

/**
 * Handle charge.failed webhook event
 */
function handleChargeFailed($obj, $data, $log_file) {
    try {
        $transactionId = $data['id'] ?? '';
        $status = $data['status'] ?? '';
        
        file_put_contents($log_file, "  Processing charge.failed\n", FILE_APPEND);
        
        if (empty($transactionId)) {
            throw new Exception('Missing transaction ID in charge.failed');
        }
        
        // Update transaction status
        $obj->MySQLQueryPerform('flutterwave_transactions', [
            'eStatus' => 'failed'
        ], 'update', "tPaymentTransactionId = '$transactionId'");
        
        file_put_contents($log_file, "  ✓ Transaction marked as failed\n", FILE_APPEND);
        
        // Trigger retry logic
        triggerPaymentRetry($obj, $transactionId, $log_file);
        
    } catch (Exception $e) {
        file_put_contents($log_file, "  ⚠ Error in charge.failed: " . $e->getMessage() . "\n", FILE_APPEND);
    }
}

/**
 * Handle charge.reversed webhook event (refund)
 */
function handleChargeReversed($obj, $data, $log_file) {
    try {
        $transactionId = $data['id'] ?? '';
        $refundAmount = $data['amount'] ?? 0;
        
        file_put_contents($log_file, "  Processing charge.reversed (refund)\n", FILE_APPEND);
        
        if (empty($transactionId)) {
            throw new Exception('Missing transaction ID in charge.reversed');
        }
        
        $obj->MySQLQueryPerform('flutterwave_transactions', [
            'eStatus' => 'refunded',
            'dRefundedAt' => date('Y-m-d H:i:s')
        ], 'update', "tPaymentTransactionId = '$transactionId'");
        
        file_put_contents($log_file, "  ✓ Transaction marked as refunded\n", FILE_APPEND);
        
    } catch (Exception $e) {
        file_put_contents($log_file, "  ⚠ Error in charge.reversed: " . $e->getMessage() . "\n", FILE_APPEND);
    }
}

/**
 * Trigger automatic payment retry
 */
function triggerPaymentRetry($obj, $transactionId, $log_file) {
    try {
        file_put_contents($log_file, "  Checking retry eligibility...\n", FILE_APPEND);
        
        // Get transaction details
        $txResult = $obj->MySQLSelect("SELECT * FROM flutterwave_transactions WHERE tPaymentTransactionId = '$transactionId' LIMIT 1");
        
        if (empty($txResult)) {
            file_put_contents($log_file, "  ⚠ Transaction not found for retry\n", FILE_APPEND);
            return;
        }
        
        // Check retry count
        $retryResult = $obj->MySQLSelect("SELECT COUNT(*) as count FROM payment_retry_logs WHERE tTransactionId = '$transactionId'");
        $retryCount = (int)($retryResult[0]['count'] ?? 0);
        
        file_put_contents($log_file, "  Current retry count: $retryCount\n", FILE_APPEND);
        
        if ($retryCount >= 3) {
            file_put_contents($log_file, "  ⚠ Max retry attempts (3) reached\n", FILE_APPEND);
            return;
        }
        
        // Log retry attempt
        $obj->MySQLQueryPerform('payment_retry_logs', [
            'tTransactionId' => $transactionId,
            'iRetryAttempt' => $retryCount + 1,
            'vReason' => 'Automatic retry from webhook',
            'vStatus' => 'scheduled'
        ], 'insert');
        
        file_put_contents($log_file, "  ✓ Retry scheduled (attempt " . ($retryCount + 1) . ")\n", FILE_APPEND);
        
    } catch (Exception $e) {
        file_put_contents($log_file, "  ⚠ Error scheduling retry: " . $e->getMessage() . "\n", FILE_APPEND);
    }
}
?>