<?php
/**
 * payment_history.php - GET USER PAYMENT HISTORY
 * Place in: assets/libraries/webview/flutterwave/payment_history.php
 * 
 * Access: /payment_history.php?member_id=50&member_type=Passenger
 */

header('Content-Type: application/json; charset=utf-8');

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
    
    // Get parameters
    $memberId = $_REQUEST['member_id'] ?? '';
    $memberType = $_REQUEST['member_type'] ?? 'User';
    $limit = (int)($_REQUEST['limit'] ?? 20);
    $offset = (int)($_REQUEST['offset'] ?? 0);
    $status = $_REQUEST['status'] ?? ''; // optional filter
    $startDate = $_REQUEST['start_date'] ?? '';
    $endDate = $_REQUEST['end_date'] ?? '';
    
    if (empty($memberId)) {
        throw new Exception('member_id is required');
    }
    
    // Build query
    $where = "iMemberId = '$memberId' AND vMemberType = '" . addslashes($memberType) . "'";
    
    if (!empty($status)) {
        $where .= " AND eStatus = '" . addslashes($status) . "'";
    }
    
    if (!empty($startDate)) {
        $where .= " AND dCreatedAt >= '" . addslashes($startDate) . " 00:00:00'";
    }
    
    if (!empty($endDate)) {
        $where .= " AND dCreatedAt <= '" . addslashes($endDate) . " 23:59:59'";
    }
    
    // Get total count
    $countResult = $obj->MySQLSelect("SELECT COUNT(*) as total FROM flutterwave_transactions WHERE $where");
    $total = (int)($countResult[0]['total'] ?? 0);
    
    // Get paginated results
    $query = "SELECT 
        id,
        tPaymentTransactionId,
        tTxRef,
        amount,
        currency,
        eStatus,
        vPageType,
        dCreatedAt,
        dCompletedAt,
        dRefundedAt,
        tRefundTransactionId
    FROM flutterwave_transactions 
    WHERE $where
    ORDER BY dCreatedAt DESC
    LIMIT $limit OFFSET $offset";
    
    $transactions = $obj->MySQLSelect($query);
    
    // Format response
    $formatted = [];
    foreach ($transactions as $tx) {
        $formatted[] = [
            'id' => $tx['id'],
            'transaction_id' => $tx['tPaymentTransactionId'],
            'reference' => $tx['tTxRef'],
            'amount' => (float)$tx['amount'],
            'currency' => $tx['currency'],
            'status' => $tx['eStatus'],
            'type' => $tx['vPageType'],
            'created_at' => $tx['dCreatedAt'],
            'completed_at' => $tx['dCompletedAt'],
            'refunded_at' => $tx['dRefundedAt'],
            'refund_id' => $tx['tRefundTransactionId'],
            'is_refundable' => ($tx['eStatus'] === 'successful'),
            'amount_formatted' => number_format($tx['amount'], 2)
        ];
    }
    
    echo json_encode([
        'status' => 'success',
        'data' => [
            'total' => $total,
            'count' => count($formatted),
            'limit' => $limit,
            'offset' => $offset,
            'transactions' => $formatted
        ]
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
?>