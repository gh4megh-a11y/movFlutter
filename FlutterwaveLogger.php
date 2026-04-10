<?php
/**
 * FlutterwaveLogger.php
 * Place in: assets/libraries/webview/flutterwave/FlutterwaveLogger.php
 */

class FlutterwaveLogger {
    private $cfg;
    private $log_file;

    public function __construct($cfg) {
        $this->cfg = $cfg;
        $this->log_file = __DIR__ . '/payment_errors.log';
    }

    public function info($action, $data = []) {
        $message = "[INFO] {$action}: " . json_encode($data);
        $this->log($message);
    }

    public function error($action, $data = []) {
        $message = "[ERROR] {$action}: " . json_encode($data);
        $this->log($message);
    }

    public function warning($action, $data = []) {
        $message = "[WARNING] {$action}: " . json_encode($data);
        $this->log($message);
    }

    private function log($message) {
        $timestamp = date('Y-m-d H:i:s');
        $log_message = "[$timestamp] $message\n";
        
        if (!file_exists($this->log_file)) {
            touch($this->log_file);
        }
        
        file_put_contents($this->log_file, $log_message, FILE_APPEND);
        error_log($log_message);
    }
}
?>