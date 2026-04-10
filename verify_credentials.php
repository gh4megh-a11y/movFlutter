<?php
/**
 * verify_credentials.php - VERIFY SECRET KEY IS VALID
 * Place in: assets/libraries/webview/flutterwave/verify_credentials.php
 */

header('Content-Type: application/json; charset=utf-8');

$response = [
    'timestamp' => date('Y-m-d H:i:s'),
    'secret_key_validation' => [],
    'test_with_simple_get' => [],
    'diagnosis' => []
];

try {
    $base_path = __DIR__;
    $public_html = dirname(dirname(dirname(dirname($base_path))));
    $common_file = $public_html . '/common.php';
    include_once $common_file;
    
    $obj = $GLOBALS['obj'];
    
    // Get LIVE secret key
    $secResult = $obj->MySQLSelect("SELECT vValue FROM configurations_payment WHERE vName = 'FLUTTERWAVE_SECRET_KEY_LIVE' LIMIT 1");
    $secretKey = !empty($secResult) ? $secResult[0]['vValue'] : '';
    
    $response['secret_key_validation'] = [
        'key' => $secretKey,
        'length' => strlen($secretKey),
        'starts_with_FLWSECK' => strpos($secretKey, 'FLWSECK') === 0,
        'contains_dashes' => substr_count($secretKey, '-'),
        'ends_with_X' => substr($secretKey, -1) === 'X',
    ];
    
    // Test 1: Simple GET request to verify API access
    $ch = curl_init('https://api.flutterwave.com/v3/me');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $secretKey,
        ],
    ]);
    
    $raw = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlInfo = curl_getinfo($ch);
    curl_close($ch);
    
    $decoded = json_decode($raw, true) ?? [];
    
    $response['test_with_simple_get'] = [
        'endpoint' => 'GET /v3/me',
        'http_code' => $httpCode,
        'status' => $decoded['status'] ?? null,
        'message' => $decoded['message'] ?? null,
        'data' => $decoded['data'] ?? null,
        'raw_response' => substr($raw, 0, 200)
    ];
    
    // Diagnosis
    if ($httpCode === 401 || $httpCode === 403) {
        $response['diagnosis'][] = "❌ ERROR 401/403: Secret key is INVALID or expired";
        $response['diagnosis'][] = "Action: Get a NEW secret key from Flutterwave dashboard";
        $response['diagnosis'][] = "URL: https://dashboard.flutterwave.com/settings/apis";
    } else if ($httpCode === 404) {
        $response['diagnosis'][] = "❌ ERROR 404: Endpoint not found - possible API domain issue";
        $response['diagnosis'][] = "Check if Flutterwave API endpoint has changed";
    } else if ($httpCode === 200 || $httpCode === 400) {
        $response['diagnosis'][] = "✅ Secret key appears VALID - API is accessible";
        $response['diagnosis'][] = "The 400/404 errors we saw before may be due to merchant account not being fully set up";
    }
    
    // Get all keys from database for reference
    $allKeys = $obj->MySQLSelect("SELECT vName, vValue FROM configurations_payment WHERE vName LIKE 'FLUTTERWAVE%KEY%' ORDER BY vName");
    
    $response['all_keys_in_database'] = [];
    foreach ($allKeys as $key) {
        $response['all_keys_in_database'][] = [
            'name' => $key['vName'],
            'value' => substr($key['vValue'], 0, 20) . '...',
            'length' => strlen($key['vValue'])
        ];
    }
    
} catch (Exception $e) {
    $response['error'] = $e->getMessage();
}

echo json_encode($response, JSON_PRETTY_PRINT);
exit;
?>