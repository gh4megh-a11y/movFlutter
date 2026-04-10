<?php
/**
 * check_merchant_status.php - CHECK MERCHANT ACCOUNT STATUS
 * Place in: assets/libraries/webview/flutterwave/check_merchant_status.php
 */

header('Content-Type: application/json; charset=utf-8');

$response = [
    'timestamp' => date('Y-m-d H:i:s'),
    'merchant_tests' => [],
    'diagnosis' => []
];

try {
    $base_path = __DIR__;
    $public_html = dirname(dirname(dirname(dirname($base_path))));
    $common_file = $public_html . '/common.php';
    include_once $common_file;
    
    $obj = $GLOBALS['obj'];
    
    // Get LIVE credentials
    $secResult = $obj->MySQLSelect("SELECT vValue FROM configurations_payment WHERE vName = 'FLUTTERWAVE_SECRET_KEY_LIVE' LIMIT 1");
    $secretKey = !empty($secResult) ? $secResult[0]['vValue'] : '';
    
    $pubResult = $obj->MySQLSelect("SELECT vValue FROM configurations_payment WHERE vName = 'FLUTTERWAVE_PUBLIC_KEY_LIVE' LIMIT 1");
    $publicKey = !empty($pubResult) ? $pubResult[0]['vValue'] : '';
    
    // Test 1: Get merchant info using simple API (non-encrypted)
    $ch = curl_init('https://api.flutterwave.com/v3/merchants/profile');
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
    
    $decoded = json_decode($raw, true) ?? [];
    
    $response['merchant_tests']['get_profile'] = [
        'http_code' => $httpCode,
        'status' => $decoded['status'] ?? null,
        'data' => $decoded['data'] ?? null,
        'message' => $decoded['message'] ?? null,
    ];
    
    // Test 2: Try unencrypted payment (V2 style)
    $payload = [
        'tx_ref' => 'test-' . time(),
        'amount' => 1,
        'currency' => 'GHS',
        'redirect_url' => 'https://movvack.com',
        'customer' => ['email' => 'test@test.com']
    ];
    
    $ch = curl_init('https://api.flutterwave.com/v3/charges?type=card');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $secretKey,
        ],
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload)
    ]);
    
    $raw = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    $decoded = json_decode($raw, true) ?? [];
    
    $response['merchant_tests']['unencrypted_charge'] = [
        'http_code' => $httpCode,
        'status' => $decoded['status'] ?? null,
        'message' => $decoded['message'] ?? null,
    ];
    
    // Diagnosis
    if (isset($response['merchant_tests']['get_profile']['message']) && 
        strpos($response['merchant_tests']['get_profile']['message'], 'not enabled') !== false) {
        $response['diagnosis'][] = "❌ Merchant account is NOT enabled for V3 API";
        $response['diagnosis'][] = "✓ Solution: Contact Flutterwave support to enable V3 API on your merchant account";
    }
    
    if (isset($response['merchant_tests']['unencrypted_charge']['message']) && 
        strpos($response['merchant_tests']['unencrypted_charge']['message'], 'not enabled') !== false) {
        $response['diagnosis'][] = "❌ Merchant account is NOT enabled for encrypted charges";
    } else if ($response['merchant_tests']['unencrypted_charge']['http_code'] === 200) {
        $response['diagnosis'][] = "✓ Unencrypted charges work! API is accessible.";
    }
    
} catch (Exception $e) {
    $response['error'] = $e->getMessage();
}

echo json_encode($response, JSON_PRETTY_PRINT);
exit;
?>