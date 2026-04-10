<?php
/**
 * index.php (ENHANCED)
 * Flutterwave library entry point with proper initialization
 * 
 * Place in: assets/libraries/webview/flutterwave/index.php
 */

// Prevent double-loading
if (defined('FLUTTERWAVE_LOADED')) return;
define('FLUTTERWAVE_LOADED', true);

// Enable error reporting (disable in production)
if (defined('DEBUG_MODE') && DEBUG_MODE) {
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
}

// Set base path
$_flw_base = __DIR__;

// Load dependencies in correct order
require_once $_flw_base . '/index_sdk.php';
require_once $_flw_base . '/db-connection.php';
require_once $_flw_base . '/FlutterwaveConfig.php';
require_once $_flw_base . '/FlutterwaveLogger.php';
require_once $_flw_base . '/FlutterwavePayment.php';
require_once $_flw_base . '/FlutterwaveWebhook.php';


// Clean up
unset($_flw_base);

// Global initialization
try {
    $GLOBALS['_FLW_CONFIG'] = FlutterwaveConfig::getInstance();
    $GLOBALS['_FLW_LOGGER'] = new FlutterwaveLogger($GLOBALS['_FLW_CONFIG']);
    
    if (!$GLOBALS['_FLW_CONFIG']->isActive()) {
        error_log('[Flutterwave] Gateway not properly configured or inactive');
    }
} catch (Exception $e) {
    error_log('[Flutterwave] Initialization error: ' . $e->getMessage());
}
?>