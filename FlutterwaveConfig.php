<?php
/**
 * FlutterwaveConfig.php - FIXED WITH OFFICIAL FLUTTERWAVE ENCRYPTION KEY DERIVATION
 * Place in: assets/libraries/webview/flutterwave/FlutterwaveConfig.php
 * 
 * OFFICIAL FIX: Encryption key = first_12_chars_of_secret + last_12_chars_of_md5_of_secret
 * Reference: https://developer.flutterwave.com/docs/encryption
 */

class FlutterwaveConfig {
    public $status = 'Inactive';
    public $environment = 'Test';
    public $publicKey = '';
    public $secretKey = '';
    public $encryptionKey = '';
    public $minTransactionAmount = 1;
    public $maxTransactionAmount = 999999;
    public $defaultCurrency = 'USD';
    public $autoRefundEnabled = false;
    public $retryAttempts = 3;
    public $apiTimeout = 30;
    public $mobileMoneyyEnabled = true;
    public $configLoaded = false;

    private static $instance = null;

    private function __construct() {
        $this->loadConfig();
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function loadConfig() {
        $obj = $GLOBALS['obj'] ?? null;
        
        if (empty($obj)) {
            error_log('[FlutterwaveConfig] ERROR: Database object ($obj) not available in GLOBALS');
            $this->status = 'Inactive';
            $this->configLoaded = false;
            return;
        }

        try {
            // Determine environment
            $systemEnv = $obj->MySQLSelect("SELECT vValue FROM configurations_payment WHERE vName = 'SYSTEM_PAYMENT_ENVIRONMENT' LIMIT 1");
            $environment = ($systemEnv[0]['vValue'] ?? 'Test');
            $this->environment = $environment;
            error_log('[FlutterwaveConfig] Environment: ' . $environment);

            // Load keys based on environment
            if ($environment === 'Live' || $environment === 'live') {
                $pubResult = $obj->MySQLSelect("SELECT vValue FROM configurations_payment WHERE vName = 'FLUTTERWAVE_PUBLIC_KEY_LIVE' LIMIT 1");
                $this->publicKey = $pubResult[0]['vValue'] ?? '';
                
                $secResult = $obj->MySQLSelect("SELECT vValue FROM configurations_payment WHERE vName = 'FLUTTERWAVE_SECRET_KEY_LIVE' LIMIT 1");
                $this->secretKey = $secResult[0]['vValue'] ?? '';
                
                error_log('[FlutterwaveConfig] Loading LIVE keys');
            } else {
                $pubResult = $obj->MySQLSelect("SELECT vValue FROM configurations_payment WHERE vName = 'FLUTTERWAVE_PUBLIC_KEY_SANDBOX' LIMIT 1");
                $this->publicKey = $pubResult[0]['vValue'] ?? '';
                
                $secResult = $obj->MySQLSelect("SELECT vValue FROM configurations_payment WHERE vName = 'FLUTTERWAVE_SECRET_KEY_SANDBOX' LIMIT 1");
                $this->secretKey = $secResult[0]['vValue'] ?? '';
                
                error_log('[FlutterwaveConfig] Loading SANDBOX keys');
            }

            error_log('[FlutterwaveConfig] Loaded keys:');
            error_log('[FlutterwaveConfig]   publicKey length: ' . strlen($this->publicKey));
            error_log('[FlutterwaveConfig]   secretKey length: ' . strlen($this->secretKey));
            error_log('[FlutterwaveConfig]   secretKey: ' . $this->secretKey);

            // CRITICAL FIX: Use OFFICIAL Flutterwave encryption key derivation
            // Formula: first_12_of_secret + last_12_of_md5(secret)
            $this->encryptionKey = $this->deriveEncryptionKeyOfficial($this->secretKey);
            
            error_log('[FlutterwaveConfig]   encryptionKey derived: ' . $this->encryptionKey . ' (length: ' . strlen($this->encryptionKey) . ')');

            // Get status
            $statusResult = $obj->MySQLSelect("SELECT vValue FROM configurations_payment WHERE vName = 'FLUTTERWAVE_STATUS' LIMIT 1");
            $this->status = $statusResult[0]['vValue'] ?? 'Inactive';
            error_log('[FlutterwaveConfig] Status: ' . $this->status);
            
            // Min/Max amounts
            $minResult = $obj->MySQLSelect("SELECT vValue FROM configurations_payment WHERE vName = 'FLUTTERWAVE_MIN_AMOUNT' LIMIT 1");
            if (!empty($minResult[0]['vValue'])) {
                $this->minTransactionAmount = (float)$minResult[0]['vValue'];
            }
            
            $maxResult = $obj->MySQLSelect("SELECT vValue FROM configurations_payment WHERE vName = 'FLUTTERWAVE_MAX_AMOUNT' LIMIT 1");
            if (!empty($maxResult[0]['vValue'])) {
                $this->maxTransactionAmount = (float)$maxResult[0]['vValue'];
            }

            $autoRefundResult = $obj->MySQLSelect("SELECT vValue FROM configurations_payment WHERE vName = 'FLUTTERWAVE_AUTO_REFUND_ENABLED' LIMIT 1");
            $this->autoRefundEnabled = ($autoRefundResult[0]['vValue'] ?? 'No') === 'Yes';

            $this->configLoaded = true;
            error_log('[FlutterwaveConfig] Configuration loaded successfully');

        } catch (Exception $e) {
            error_log('[FlutterwaveConfig] Database query error: ' . $e->getMessage());
            $this->status = 'Inactive';
            $this->configLoaded = false;
        }
    }

    /**
     * OFFICIAL FLUTTERWAVE ENCRYPTION KEY DERIVATION
     * Reference: https://developer.flutterwave.com/docs/encryption
     * 
     * Algorithm:
     * 1. Hash secret key with MD5
     * 2. Take LAST 12 chars of MD5 hash
     * 3. Remove "FLWSECK-" prefix from secret key and take FIRST 12 chars
     * 4. Concatenate: first_12 + last_12_of_md5 = 24-char encryption key
     * 
     * @param string $secretKey Flutterwave secret key
     * @return string 24-character encryption key
     */
    private function deriveEncryptionKeyOfficial($secretKey) {
        if (empty($secretKey)) {
            error_log('[FlutterwaveConfig] deriveEncryptionKeyOfficial: empty secret key');
            return '';
        }

        error_log('[FlutterwaveConfig] deriveEncryptionKeyOfficial: input = ' . $secretKey);

        // Step 1: Hash the secret key with MD5
        $md5Hash = md5($secretKey);
        error_log('[FlutterwaveConfig] MD5 hash: ' . $md5Hash);

        // Step 2: Take LAST 12 characters of MD5 hash
        $last12OfMd5 = substr($md5Hash, -12);
        error_log('[FlutterwaveConfig] Last 12 of MD5: ' . $last12OfMd5);

        // Step 3: Remove "FLWSECK-" prefix and take FIRST 12 chars
        $secretKeyWithoutPrefix = str_replace('FLWSECK-', '', $secretKey);
        $first12OfSecret = substr($secretKeyWithoutPrefix, 0, 12);
        error_log('[FlutterwaveConfig] First 12 of secret (without prefix): ' . $first12OfSecret);

        // Step 4: Concatenate
        $encryptionKey = $first12OfSecret . $last12OfMd5;
        
        error_log('[FlutterwaveConfig] Derived encryption key: ' . $encryptionKey . ' (length: ' . strlen($encryptionKey) . ')');

        // Validate
        if (strlen($encryptionKey) !== 24) {
            error_log('[FlutterwaveConfig] ERROR: Encryption key is not 24 chars');
            return '';
        }

        return $encryptionKey;
    }

    public function isActive() {
        $statusOk = ($this->status === 'Active');
        $publicKeyOk = !empty($this->publicKey);
        $secretKeyOk = !empty($this->secretKey);
        $encryptionKeyOk = !empty($this->encryptionKey);
        $encryptionLengthOk = strlen($this->encryptionKey) === 24;

        error_log('[FlutterwaveConfig] isActive() check:');
        error_log('[FlutterwaveConfig]   configLoaded: ' . ($this->configLoaded ? 'YES' : 'NO'));
        error_log('[FlutterwaveConfig]   status === "Active": ' . ($statusOk ? 'YES' : 'NO') . ' (actual: ' . $this->status . ')');
        error_log('[FlutterwaveConfig]   publicKey not empty: ' . ($publicKeyOk ? 'YES' : 'NO') . ' (length: ' . strlen($this->publicKey) . ')');
        error_log('[FlutterwaveConfig]   secretKey not empty: ' . ($secretKeyOk ? 'YES' : 'NO') . ' (length: ' . strlen($this->secretKey) . ')');
        error_log('[FlutterwaveConfig]   encryptionKey not empty: ' . ($encryptionKeyOk ? 'YES' : 'NO') . ' (actual: ' . $this->encryptionKey . ', length: ' . strlen($this->encryptionKey) . ')');
        error_log('[FlutterwaveConfig]   encryptionKey length === 24: ' . ($encryptionLengthOk ? 'YES' : 'NO'));
        
        $result = $statusOk && $publicKeyOk && $secretKeyOk && $encryptionKeyOk && $encryptionLengthOk;
        error_log('[FlutterwaveConfig]   OVERALL RESULT: ' . ($result ? 'ACTIVE' : 'NOT ACTIVE'));

        return $result;
    }

    public function enabledPaymentOptions() {
        return [
            'card',
            'account',
            'ussd',
            'qr',
            'mobilemoneyghana',
            'mobilemoneyrwanda',
            'mobilemoneyug',
            'mobilemoneytanzania',
            'mobilemoneyfranco'
        ];
    }
}
?>