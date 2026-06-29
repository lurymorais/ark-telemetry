<?php
/**
 * Bootstrap do sistema ARK Telemetry - Versão Simplificada
 * URL: https://revistacarnaubais.com.br/ark-telemetry/bootstrap.php
 */

/**
 * Encontra o arquivo config.inc.php do OJS
 * 
 * @return string|false Caminho do arquivo ou false se não encontrado
 */
function findOJSConfigFile() {
    $paths = [
        '/home/u753420024/domains/revistacarnaubais.com.br/public_html/config.inc.php',
        '/home/u753420024/public_html/config.inc.php',
        dirname(__DIR__, 2) . '/config.inc.php',
        dirname(__DIR__, 3) . '/config.inc.php',
        dirname(__DIR__, 4) . '/config.inc.php',
        __DIR__ . '/../config.inc.php',
        $_SERVER['DOCUMENT_ROOT'] . '/config.inc.php',
    ];
    
    foreach ($paths as $path) {
        if (file_exists($path)) {
            return $path;
        }
    }
    
    return false;
}

/**
 * Lê configuração do banco de dados do config.inc.php do OJS
 */
function getDatabaseConfigFromOJS() {
    $configFile = findOJSConfigFile();
    
    if (!$configFile) {
        throw new Exception('OJS configuration file not found.');
    }
    
    $configLines = file($configFile, FILE_IGNORE_NEW_LINES);
    $dbConfig = [];
    $inDbSection = false;
    
    foreach ($configLines as $line) {
        $line = trim($line);
        if (empty($line) || $line[0] === ';' || $line[0] === '#') continue;
        if (strpos($line, '[database]') === 0) { $inDbSection = true; continue; }
        if ($inDbSection && strpos($line, '[') === 0) { $inDbSection = false; continue; }
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
 * Inicializa conexão com o banco de dados
 */
function initializeDatabaseConnection() {
    try {
        $dbConfig = getDatabaseConfigFromOJS();
        
        if ($dbConfig['driver'] !== 'mysql' && $dbConfig['driver'] !== 'mysqli') {
            throw new Exception("Unsupported database driver: {$dbConfig['driver']}");
        }
        
        $dsn = "mysql:host={$dbConfig['host']};dbname={$dbConfig['name']};charset={$dbConfig['charset']}";
        
        $pdo = new PDO($dsn, $dbConfig['username'], $dbConfig['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]);
        
        return $pdo;
        
    } catch (Exception $e) {
        error_log("ARK Telemetry: Database connection failed - " . $e->getMessage());
        throw $e;
    }
}

// ============================================
// FUNÇÕES AUXILIARES
// ============================================

function ark_log($message, $level = 'info') {
    $logFile = dirname(__DIR__) . '/logs/ark-telemetry.log';
    $logDir = dirname($logFile);
    
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    
    $logMessage = date('Y-m-d H:i:s') . " [{$level}] " . $message . PHP_EOL;
    error_log($logMessage, 3, $logFile);
}

function ark_json_response($data, $status_code = 200) {
    http_response_code($status_code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, must-revalidate');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// ============================================
// INICIALIZAÇÃO DAS TABELAS
// ============================================

try {
    global $ark_pdo;
    $ark_pdo = initializeDatabaseConnection();
    
    // Tabela principal de periódicos
    $ark_pdo->exec("
        CREATE TABLE IF NOT EXISTS ark_journals (
            naan VARCHAR(50) PRIMARY KEY,
            plugin_token VARCHAR(64) NOT NULL,
            admin_secret VARCHAR(64) NOT NULL,
            journal_url VARCHAR(255) NOT NULL,
            journal_name VARCHAR(255),
            country VARCHAR(100),
            email VARCHAR(255),
            primary_language VARCHAR(10),
            telemetry_level ENUM('public', 'restricted') DEFAULT 'restricted',
            arks_count INT DEFAULT 0,
            plugin_version VARCHAR(20),
            api_endpoint VARCHAR(255),
            status ENUM('active', 'error', 'pending') DEFAULT 'pending',
            last_sync TIMESTAMP NULL,
            error_message TEXT,
            sync_attempts INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_status (status),
            INDEX idx_telemetry (telemetry_level),
            INDEX idx_last_sync (last_sync)
        )
    ");
    
    // Tabela de logs para monitoramento
    $ark_pdo->exec("
        CREATE TABLE IF NOT EXISTS ark_sync_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            naan VARCHAR(50),
            action VARCHAR(50),
            status ENUM('success', 'error') DEFAULT 'success',
            message TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_naan (naan),
            INDEX idx_created (created_at),
            INDEX idx_action (action)
        )
    ");
    
    ark_log("ARK Telemetry simplified system initialized successfully", 'info');
    
} catch (Exception $e) {
    $errorMsg = $e->getMessage();
    ark_log("Initialization failed: " . $errorMsg, 'error');
    
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
    if (strpos($scriptName, 'dashboard.php') === false && 
        strpos($scriptName, 'test.php') === false &&
        strpos($scriptName, 'debug.php') === false) {
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "System initialization failed"]);
        exit;
    } else {
        echo "<div style='background:#f8d7da; color:#721c24; padding:15px;'>";
        echo "<h3>Initialization Error</h3>";
        echo "<p><strong>Error:</strong> " . htmlspecialchars($errorMsg) . "</p>";
        echo "</div>";
        if (strpos($scriptName, 'debug.php') === false) {
            throw $e;
        }
    }
}