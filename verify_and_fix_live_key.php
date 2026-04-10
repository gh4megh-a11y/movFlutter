<?php
/**
 * verify_and_fix_live_key.php - VERIFY LIVE KEY LENGTH AND FIX
 * Place in: assets/libraries/webview/flutterwave/verify_and_fix_live_key.php
 */

header('Content-Type: application/json; charset=utf-8');

$response = [
    'timestamp' => date('Y-m-d H:i:s'),
    'issue' => 'LIVE encryption key is 23 chars, needs to be 24',
    'analysis' => [],
    'fix' => []
];

try {
    $base_path = __DIR__;
    $public_html = dirname(dirname(dirname(dirname($base_path))));
    $common_file = $public_html . '/common.php';
    include_once $common_file;
    
    $obj = $GLOBALS['obj'];
    
    // Get LIVE secret key
    $secResult = $obj->MySQLSelect("SELECT vValue FROM configurations_payment WHERE vName = 'FLUTTERWAVE_SECRET_KEY_LIVE' LIMIT 1");
    $liveSecretKey = !empty($secResult) ? $secResult[0]['vValue'] : '';
    
    $response['analysis']['live_secret_key'] = $liveSecretKey;
    
    // Derive correct encryption key
    $md5Hash = md5($liveSecretKey);
    $last12OfMd5 = substr($md5Hash, -12);
    $liveKeyWithoutPrefix = str_replace('FLWSECK-', '', $liveSecretKey);
    $first12OfLive = substr($liveKeyWithoutPrefix, 0, 12);
    $derivedLiveEnc = $first12OfLive . $last12OfMd5;
    
    $response['analysis']['md5_hash'] = $md5Hash;
    $response['analysis']['last_12_of_md5'] = $last12OfMd5;
    $response['analysis']['first_12_of_secret'] = $first12OfLive;
    $response['analysis']['derived_encryption_key'] = $derivedLiveEnc;
    $response['analysis']['derived_length'] = strlen($derivedLiveEnc);
    
    // Compare with dashboard key
    $dashboardKey = '8fcf8d473285d8736cebd97';
    $response['analysis']['dashboard_key'] = $dashboardKey;
    $response['analysis']['dashboard_length'] = strlen($dashboardKey);
    $response['analysis']['keys_match'] = ($derivedLiveEnc === $dashboardKey);
    
    // If they don't match, use derived
    if ($derivedLiveEnc !== $dashboardKey) {
        $response['fix']['action'] = 'Derived key differs from dashboard key, using derived';
        $response['fix']['reason'] = 'Derived key is authoritative from official derivation formula';
        $correctKey = $derivedLiveEnc;
    } else {
        $response['fix']['action'] = 'Dashboard key is correct';
        $correctKey = $dashboardKey;
    }
    
    // Update database with correct key
    if (strlen($correctKey) === 24) {
        try {
            $obj->MySQLQueryPerform('configurations_payment', 
                ['vValue' => $correctKey], 
                'update', 
                "vName = 'FLUTTERWAVE_ENCRYPTION_KEY_LIVE'"
            );
            $response['fix']['result'] = "✓ Updated FLUTTERWAVE_ENCRYPTION_KEY_LIVE to: $correctKey";
        } catch (Exception $e) {
            $response['fix']['error'] = $e->getMessage();
        }
    } else {
        $response['fix']['error'] = "Derived key is not 24 chars: " . strlen($correctKey);
    }
    
    // Verify final value
    $verify = $obj->MySQLSelect("SELECT vValue FROM configurations_payment WHERE vName = 'FLUTTERWAVE_ENCRYPTION_KEY_LIVE' LIMIT 1");
    $finalKey = !empty($verify) ? $verify[0]['vValue'] : '';
    
    $response['verification'] = [
        'final_key' => $finalKey,
        'length' => strlen($finalKey),
        'is_24_chars' => strlen($finalKey) === 24,
        'is_valid' => strlen($finalKey) === 24 && preg_match('/^[a-z0-9]{24}$/i', $finalKey)
    ];
    
} catch (Exception $e) {
    $response['error'] = $e->getMessage();
}

echo json_encode($response, JSON_PRETTY_PRINT);
exit;
?>