<?php
/**
 * index_sdk.php - REDIRECT TO FLUTTERWAVE MODAL
 * Place in: assets/libraries/webview/flutterwave/index_sdk.php
 */

ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

header('Content-Type: text/html; charset=utf-8');

try {
    // Include common
    $base_path = __DIR__;
    $public_html = dirname(dirname(dirname(dirname($base_path))));
    $common_file = $public_html . '/common.php';
    
    if (!file_exists($common_file)) {
        die(json_encode(['error' => 'common.php not found']));
    }
    
    include_once $common_file;
    
    // Simply redirect to the modal payment page with all parameters
    $modalUrl = $tconfig['tsite_url'] . 'assets/libraries/webview/flutterwave/flutterwave_payment_modal.php';
    $modalUrl .= '?' . $_SERVER['QUERY_STRING'];
    
    // Check if AJAX request
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        // For AJAX, return JSON redirect
        header('Content-Type: application/json');
        echo json_encode([
            'Action' => '1',
            'redirect' => $modalUrl,
            'message' => 'Redirecting to payment modal...'
        ]);
    } else {
        // For regular requests, redirect to modal
        header('Location: ' . $modalUrl);
    }
    
} catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode(['error' => $e->getMessage()]);
}
?>