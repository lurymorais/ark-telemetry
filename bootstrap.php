<?php
/**
 * Bootstrap for ARK Telemetry System - v3.1.1.0
 * URL: https://revistacarnaubais.com.br/ark-telemetry/bootstrap.php
 */

/**
 * Finds the database configuration from OJS config file
 * 
 * @return array Database configuration
 * @throws Exception If config file not found or incomplete
 */
function getDatabaseConfigFromOJS() {
    $configFile = dirname(__FILE__, 2) . '/config.inc.php';
    
    if (!file_exists($configFile)) {
        throw new Exception('OJS configuration file not found at: ' . $configFile);
    }
    
    $configLines = file($configFile, FILE_IGNORE_NEW_LINES);
    $dbConfig = [];
    $inDbSection = false;
    
    foreach ($configLines as $line) {
        $line = trim($line);
        if (empty($line) || $line[0] === ';' || $line[0] === '#') {
            continue;
        }
        
        if (strpos($line, '[database]') === 0) {
            $inDbSection = true;
            continue;
        }
        
        if ($inDbSection && strpos($line, '[') === 0) {
            $inDbSection = false;
            continue;
        }
        
        if ($inDbSection && strpos($line, '=') !== false) {
            $parts = explode('=', $line, 2);
            $key = trim($parts[0]);
            $value = trim(trim($parts[1]), "\"'");
            $dbConfig[$key] = $value;
        }
    }
    
    $required = ['driver', 'host', 'username', 'password', 'name'];
    foreach ($required as $field) {
        if (empty($dbConfig[$field])) {
            throw new Exception("Missing database configuration: {$field}");
        }
    }
    
    if (empty($dbConfig['charset'])) {
        $dbConfig['charset'] = 'utf8mb4';
    }
    
    return $dbConfig;
}

/**
 * Initializes database connection using PDO
 * 
 * @return PDO Database connection object
 * @throws Exception If connection fails
 */
function initializeDatabaseConnection() {
    try {
        $dbConfig = getDatabaseConfigFromOJS();
        
        $driver = ($dbConfig['driver'] === 'mysqli') ? 'mysql' : $dbConfig['driver'];
        
        if (!in_array($driver, ['mysql', 'pgsql'])) {
            throw new Exception("Unsupported database driver: {$dbConfig['driver']}");
        }
        
        if ($driver === 'pgsql') {
            $dsn = "pgsql:host={$dbConfig['host']};dbname={$dbConfig['name']}";
        } else {
            $dsn = "mysql:host={$dbConfig['host']};dbname={$dbConfig['name']};charset={$dbConfig['charset']}";
        }
        
        $pdo = new PDO($dsn, $dbConfig['username'], $dbConfig['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]);
        
        return $pdo;
        
    } catch (Exception $e) {
        ark_log("Database connection failed: " . $e->getMessage(), 'error');
        throw $e;
    }
}

/**
 * Log messages to PHP error log only
 */
function ark_log($message, $level = 'info') {
    error_log("[ARK-Telemetry] [{$level}] " . $message);
}

/**
 * Sends standardized JSON response with proper headers
 */
function ark_json_response($data, $status_code = 200) {
    http_response_code($status_code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, must-revalidate');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

/**
 * Verify plugin identity by checking if identity.txt exists
 * 
 * @param string $domain The domain to check
 * @return bool True if identity file exists
 */
function verifyPluginIdentity($domain) {
    $identityUrl = "https://{$domain}/plugins/pubIds/ark/identity.txt";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $identityUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'ARK-Telemetry/3.1.1.0');
    curl_setopt($ch, CURLOPT_NOBODY, true); // Only check if file exists
    
    $httpCode = 0;
    
    try {
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    } catch (Exception $e) {
        $httpCode = 0;
    }
    
    curl_close($ch);
    
    return ($httpCode === 200);
}

// ============================================
// DATABASE INITIALIZATION
// ============================================

try {
    global $ark_pdo;
    $ark_pdo = initializeDatabaseConnection();
    
    // Table 1: ARK Statistics (aggregated data from plugins via push)
    $ark_pdo->exec("
        CREATE TABLE IF NOT EXISTS ark_statistics (
            id INT AUTO_INCREMENT PRIMARY KEY,
            naan VARCHAR(50) NOT NULL,
            arks_count INT NOT NULL,
            plugin_version VARCHAR(20) NOT NULL,
            received_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_naan (naan),
            INDEX idx_received (received_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    
    // Table 2: NAAN Validations (audit trail with consent tracking)
    $ark_pdo->exec("
        CREATE TABLE IF NOT EXISTS ark_validations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            naan VARCHAR(50) NOT NULL,
            domain VARCHAR(255) NOT NULL,
            status ENUM('success', 'failed', 'consent_change') DEFAULT 'success',
            message TEXT,
            validated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            consent_action ENUM('enabled', 'disabled', 'unchanged') NULL,
            consent_previous_value VARCHAR(10) NULL,
            consent_changed_at TIMESTAMP NULL,
            INDEX idx_status (status),
            INDEX idx_naan (naan),
            INDEX idx_domain (domain),
            INDEX idx_consent (consent_action)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    
    // Table 3: Validation Tokens (temporary, one-time use)
    $ark_pdo->exec("
        CREATE TABLE IF NOT EXISTS ark_validation_tokens (
            id INT AUTO_INCREMENT PRIMARY KEY,
            naan VARCHAR(50) NOT NULL,
            token VARCHAR(64) NOT NULL UNIQUE,
            expires_at TIMESTAMP NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            used_at TIMESTAMP NULL,
            INDEX idx_token (token),
            INDEX idx_naan (naan),
            INDEX idx_expires (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    
    // Table 4: Rate Limiting
    $ark_pdo->exec("
        CREATE TABLE IF NOT EXISTS ark_rate_limits (
            id INT AUTO_INCREMENT PRIMARY KEY,
            identifier VARCHAR(100) NOT NULL,
            action VARCHAR(50) NOT NULL,
            attempts INT DEFAULT 1,
            first_attempt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            last_attempt TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            next_allowed_at TIMESTAMP NULL,
            INDEX idx_identifier (identifier),
            INDEX idx_next_allowed (next_allowed_at),
            UNIQUE KEY uk_identifier_action (identifier, action)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    
    // Add consent columns if they don't exist (for existing installations)
    try {
        $ark_pdo->exec("
            ALTER TABLE ark_validations 
            ADD COLUMN IF NOT EXISTS consent_action ENUM('enabled', 'disabled', 'unchanged') NULL AFTER status,
            ADD COLUMN IF NOT EXISTS consent_previous_value VARCHAR(10) NULL AFTER consent_action,
            ADD COLUMN IF NOT EXISTS consent_changed_at TIMESTAMP NULL AFTER consent_previous_value,
            ADD INDEX IF NOT EXISTS idx_consent (consent_action),
            MODIFY status ENUM('success', 'failed', 'consent_change') DEFAULT 'success'
        ");
    } catch (PDOException $e) {
        // Columns may already exist or version differences - ignore
        error_log("[ARK] Notice during schema update: " . $e->getMessage());
    }
        
} catch (Exception $e) {
    $errorMsg = $e->getMessage();
    ark_log("Initialization failed: " . $errorMsg, 'error');
    
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'System initialization failed'
    ]);
    exit;
}