<?php
/**
 * init.php - Flutterwave Initialization
 * Place in: assets/libraries/webview/flutterwave/init.php
 */

// Prevent double initialization
if (defined('FLUTTERWAVE_INIT')) {
    return;
}
define('FLUTTERWAVE_INIT', true);

// Load configuration
require_once __DIR__ . '/FlutterwaveConfig.php';
require_once __DIR__ . '/FlutterwaveLogger.php';

// Initialize global objects
if (!isset($GLOBALS['_FLW_CONFIG'])) {
    $GLOBALS['_FLW_CONFIG'] = FlutterwaveConfig::getInstance();
}

if (!isset($GLOBALS['_FLW_LOGGER'])) {
    $GLOBALS['_FLW_LOGGER'] = new FlutterwaveLogger($GLOBALS['_FLW_CONFIG']);
}

// Log initialization
$GLOBALS['_FLW_LOGGER']->info('flutterwave_initialized', [
    'environment' => $GLOBALS['_FLW_CONFIG']->environment,
    'status' => $GLOBALS['_FLW_CONFIG']->status
]);
?>