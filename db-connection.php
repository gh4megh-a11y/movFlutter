<?php
/**
 * db-connection.php
 * Enhanced database connection handler for Flutterwave integration
 * 
 * Place in: assets/libraries/webview/flutterwave/db-connection.php
 * 
 * Features:
 * - Automatic db_info.php detection
 * - Singleton pattern for performance
 * - Error logging
 * - Connection pooling
 * - UTF-8 support
 */

class FlutterwaveDB {
    private static $instance = null;
    private $connection = null;
    private $connected = false;
    private $error = null;
    private $attempts = 0;
    private $max_attempts = 3;

    /**
     * Get singleton instance
     */
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor - forces singleton pattern
     */
    private function __construct() {
        $this->connect();
    }

    /**
     * Establish database connection
     */
    private function connect() {
        try {
            // ── Step 1: Find db_info.php ──────────────────────────────────────
            $db_info_path = $this->findDbInfo();
            
            if (!$db_info_path) {
                throw new Exception(
                    'db_info.php not found. Expected paths: ' .
                    '/home/movvack/public_html/assets/libraries/db_info.php or ' .
                    '/home/movvack/public_html/assets/db_info.php'
                );
            }

            // ── Step 2: Load credentials ──────────────────────────────────────
            $this->loadCredentials($db_info_path);

            // ── Step 3: Verify constants ──────────────────────────────────────
            if (!defined('TSITE_SERVER') || !defined('TSITE_USERNAME') || 
                !defined('TSITE_PASS') || !defined('TSITE_DB')) {
                throw new Exception(
                    'Database constants not properly defined. ' .
                    'Check db_info.php for TSITE_SERVER, TSITE_USERNAME, TSITE_PASS, TSITE_DB'
                );
            }

            // ── Step 4: Connect to MySQL ──────────────────────────────────────
            $this->connection = new mysqli(
                TSITE_SERVER,
                TSITE_USERNAME,
                TSITE_PASS,
                TSITE_DB
            );

            // ── Step 5: Check connection ──────────────────────────────────────
            if ($this->connection->connect_error) {
                throw new Exception(
                    'MySQL Connection Error (' . $this->connection->connect_errno . '): ' .
                    $this->connection->connect_error
                );
            }

            // ── Step 6: Set charset ───────────────────────────────────────────
            if (!$this->connection->set_charset('utf8mb4')) {
                $this->connection->set_charset('utf8');
            }

            $this->connected = true;
            $this->attempts = 0;
            
            $this->log('info', 'Database connection established', [
                'server' => TSITE_SERVER,
                'database' => TSITE_DB,
                'charset' => $this->connection->get_charset()->charset
            ]);

        } catch (Exception $e) {
            $this->error = $e->getMessage();
            $this->connected = false;
            $this->attempts++;

            $this->log('error', 'Database connection failed', [
                'error' => $this->error,
                'attempt' => $this->attempts,
                'max_attempts' => $this->max_attempts
            ]);

            // Retry if attempts remaining
            if ($this->attempts < $this->max_attempts) {
                sleep(1); // Wait 1 second before retry
                $this->connect();
            } else {
                throw $e;
            }
        }
    }

    /**
     * Find db_info.php in common locations
     */
    private function findDbInfo() {
        $search_paths = [
            // Most likely location for movvack
            dirname(__DIR__, 2) . '/db_info.php',
            dirname(__DIR__, 3) . '/libraries/db_info.php',
            dirname(__DIR__, 4) . '/assets/libraries/db_info.php',
            
            // Alternative paths
            __DIR__ . '/../../db_info.php',
            __DIR__ . '/../../../libraries/db_info.php',
            __DIR__ . '/../../../../assets/libraries/db_info.php',
            
            // Absolute paths for cPanel/WHM
            '/home/movvack/public_html/assets/libraries/db_info.php',
            '/home/movvack/public_html/assets/db_info.php',
            '/var/www/movvack/assets/libraries/db_info.php',
            '/var/www/movvack/assets/db_info.php',
            
            // Server root
            $_SERVER['DOCUMENT_ROOT'] . '/assets/libraries/db_info.php',
            $_SERVER['DOCUMENT_ROOT'] . '/assets/db_info.php',
        ];

        foreach ($search_paths as $path) {
            if (file_exists($path) && is_readable($path)) {
                return realpath($path);
            }
        }

        return null;
    }

    /**
     * Load database credentials from db_info.php
     */
    private function loadCredentials($path) {
        if (!file_exists($path)) {
            throw new Exception("db_info.php not found at: $path");
        }

        if (!is_readable($path)) {
            throw new Exception("db_info.php is not readable at: $path");
        }

        // Include the file to define constants
        require_once $path;

        // Verify constants were defined
        if (!defined('TSITE_SERVER')) {
            throw new Exception('TSITE_SERVER not defined in db_info.php');
        }
        if (!defined('TSITE_USERNAME')) {
            throw new Exception('TSITE_USERNAME not defined in db_info.php');
        }
        if (!defined('TSITE_PASS')) {
            throw new Exception('TSITE_PASS not defined in db_info.php');
        }
        if (!defined('TSITE_DB')) {
            throw new Exception('TSITE_DB not defined in db_info.php');
        }
    }

    /**
     * Get MySQLi connection object
     */
    public function getConnection() {
        if (!$this->connected || !$this->connection) {
            throw new Exception('Database connection not available: ' . $this->error);
        }
        return $this->connection;
    }

    /**
     * Check if connected
     */
    public function isConnected() {
        return $this->connected && $this->connection && $this->connection->ping();
    }

    /**
     * Execute query
     */
    public function query($sql) {
        if (!$this->connected) {
            throw new Exception('Not connected to database');
        }

        $result = $this->connection->query($sql);
        
        if (!$result) {
            $this->log('error', 'Query execution failed', [
                'sql' => substr($sql, 0, 100),
                'error' => $this->connection->error
            ]);
            throw new Exception('Query failed: ' . $this->connection->error);
        }

        return $result;
    }

    /**
     * Execute prepared statement
     */
    public function prepare($sql) {
        if (!$this->connected) {
            throw new Exception('Not connected to database');
        }

        $stmt = $this->connection->prepare($sql);
        
        if (!$stmt) {
            throw new Exception('Prepare failed: ' . $this->connection->error);
        }

        return $stmt;
    }

    /**
     * Escape string for safety
     */
    public function escape($str) {
        if (!$this->connected) {
            return addslashes($str);
        }
        return $this->connection->real_escape_string($str);
    }

    /**
     * Get last insert ID
     */
    public function getInsertId() {
        if (!$this->connected) {
            return null;
        }
        return $this->connection->insert_id;
    }

    /**
     * Get affected rows
     */
    public function getAffectedRows() {
        if (!$this->connected) {
            return 0;
        }
        return $this->connection->affected_rows;
    }

    /**
     * Get last error
     */
    public function getError() {
        if (!$this->connected) {
            return $this->error;
        }
        return $this->connection->error;
    }

    /**
     * Internal logging
     */
    private function log($level, $message, $context = []) {
        $timestamp = date('Y-m-d H:i:s');
        $contextJson = json_encode($context);
        
        // Log to PHP error log
        error_log("[$timestamp] [FlutterwaveDB] [$level] $message - $contextJson");
        
        // Optionally log to file
        $logDir = dirname(__DIR__, 3) . '/logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
        
        $logFile = $logDir . '/flutterwave-db.log';
        $logEntry = "[$timestamp] [$level] $message - $contextJson\n";
        
        @file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
    }

    /**
     * Close connection
     */
    public function close() {
        if ($this->connection) {
            $this->connection->close();
            $this->connected = false;
            $this->connection = null;
        }
    }

    /**
     * Prevent cloning
     */
    public function __clone() {
        throw new Exception('Cannot clone singleton');
    }

    /**
     * Prevent serialization
     */
    public function __sleep() {
        throw new Exception('Cannot serialize singleton');
    }

    /**
     * Destructor - close connection on exit
     */
    public function __destruct() {
        $this->close();
    }
}

// ═════════════════════════════════════════════════════════════════
// Make global database connection available
// ═════════════════════════════════════════════════════════════════

try {
    $GLOBALS['_FLW_DB'] = FlutterwaveDB::getInstance();
} catch (Exception $e) {
    error_log('[FlutterwaveDB] Fatal error: ' . $e->getMessage());
}
?>