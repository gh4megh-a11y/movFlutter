<?php
/**
 * network_diagnostic.php - CHECK NETWORK CONNECTIVITY TO FLUTTERWAVE
 * Place in: assets/libraries/webview/flutterwave/network_diagnostic.php
 */

header('Content-Type: application/json; charset=utf-8');

$response = [
    'timestamp' => date('Y-m-d H:i:s'),
    'connectivity_tests' => [],
    'dns_tests' => [],
    'diagnosis' => []
];

try {
    // Test 1: Simple DNS lookup
    $ip = gethostbyname('api.flutterwave.com');
    $response['dns_tests']['api_flutterwave_com'] = [
        'hostname' => 'api.flutterwave.com',
        'resolved_ip' => $ip,
        'is_ip' => ($ip !== 'api.flutterwave.com')
    ];
    
    // Test 2: cURL to Flutterwave root
    $ch = curl_init('https://api.flutterwave.com');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    
    $raw = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    $response['connectivity_tests']['root_api'] = [
        'url' => 'https://api.flutterwave.com',
        'http_code' => $httpCode,
        'curl_error' => $curlError ?: 'none',
        'response_sample' => substr($raw, 0, 100)
    ];
    
    // Test 3: Try different Flutterwave endpoints
    $endpoints = [
        'https://api.flutterwave.com/v3',
        'https://api.flutterwave.com/v3/transactions',
        'https://api.flutterwave.com/v3/status',
    ];
    
    foreach ($endpoints as $endpoint) {
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        
        $raw = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        $response['connectivity_tests'][$endpoint] = [
            'http_code' => $httpCode,
            'response_sample' => substr($raw, 0, 100)
        ];
    }
    
    // Test 4: Check server configuration
    $response['server_info'] = [
        'php_version' => phpversion(),
        'curl_enabled' => extension_loaded('curl'),
        'openssl_enabled' => extension_loaded('openssl'),
        'allow_url_fopen' => ini_get('allow_url_fopen'),
    ];
    
    // Diagnosis
    if ($response['dns_tests']['api_flutterwave_com']['is_ip']) {
        $response['diagnosis'][] = "✅ DNS resolution works for api.flutterwave.com";
    } else {
        $response['diagnosis'][] = "❌ Cannot resolve api.flutterwave.com DNS";
        $response['diagnosis'][] = "Check your server's network connectivity and firewall";
    }
    
    if ($response['connectivity_tests']['root_api']['http_code'] > 0) {
        $response['diagnosis'][] = "✅ Server can reach api.flutterwave.com (HTTP " . $response['connectivity_tests']['root_api']['http_code'] . ")";
    } else {
        $response['diagnosis'][] = "❌ Server CANNOT reach api.flutterwave.com";
        $response['diagnosis'][] = "Error: " . $response['connectivity_tests']['root_api']['curl_error'];
        $response['diagnosis'][] = "Solutions:";
        $response['diagnosis'][] = "1. Check firewall rules - port 443 must be open";
        $response['diagnosis'][] = "2. Check if your ISP/host blocks external APIs";
        $response['diagnosis'][] = "3. Contact your hosting provider";
    }
    
} catch (Exception $e) {
    $response['error'] = $e->getMessage();
}

echo json_encode($response, JSON_PRETTY_PRINT);
exit;
?>