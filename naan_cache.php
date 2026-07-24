#!/usr/bin/php
<?php
/**
 * NAAN Cache Updater & Cleanup
 * Run daily via cron to fetch NAAN list and clean old records
 * 
 * @package ARKTelemetry
 * @version 3.1.1.0
 */

// detect cli or web execution
$isCli = (php_sapi_name() === 'cli');

if (!$isCli) {
    header('Content-Type: application/json');
    http_response_code(403);
    echo json_encode([
        'error' => 'This script can only be executed via command line (CLI)',
        'sapi' => php_sapi_name()
    ]);
    exit;
}

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/rate_limit_helper.php';

global $ark_pdo;

define('CACHE_FILE', __DIR__ . '/naan_cache.json');
define('CACHE_EXPIRY', 86400); // 24 hours

// Check if can write to the cache directory
if (!is_writable(__DIR__)) {
    error_log("[NAAN-Cache] ERROR: Directory not writable: " . __DIR__);
    echo json_encode([
        'status' => 'error',
        'message' => 'Cache directory is not writable',
        'directory' => __DIR__
    ]);
    exit(1);
}

/**
 * Fetch active NAANs from n2t.net registry
 * Source: https://cdluc3.github.io/naan_reg_priv/naan_registry.txt
 */
function fetchNaanList() {
    $url = 'https://cdluc3.github.io/naan_reg_priv/naan_registry.txt';
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'ARK-Telemetry-Cache/3.1.1.0');
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($httpCode !== 200 || empty($response)) {
        throw new Exception("Failed to fetch NAAN list: HTTP {$httpCode} - {$curlError}");
    }
    
    // Parse the registry format
    $naanMap = [];
    $lines = explode("\n", $response);
    $currentNaan = null;
    $currentWhere = null;
    $inEntry = false;
    
    foreach ($lines as $line) {
        $line = trim($line);
        
        if (empty($line) || strpos($line, '#') === 0) {
            continue;
        }
        
        if (strpos($line, 'naa:') === 0) {
            if ($currentNaan && $currentWhere) {
                $naanMap[$currentNaan] = $currentWhere;
            }
            $currentNaan = null;
            $currentWhere = null;
            $inEntry = true;
            continue;
        }
        
        if (!$inEntry) {
            continue;
        }
        
        if (strpos($line, 'what:') === 0) {
            $currentNaan = trim(substr($line, 5));
            $currentNaan = preg_replace('/[^0-9]/', '', $currentNaan);
        }
        
        if (strpos($line, 'where:') === 0) {
            $currentWhere = trim(substr($line, 6));
            $currentWhere = preg_replace('#^https?://#', '', $currentWhere);
            $currentWhere = rtrim($currentWhere, '/');
        }
        
        if ($currentNaan && $currentWhere && !empty($currentWhere)) {
            $naanMap[$currentNaan] = $currentWhere;
            $currentNaan = null;
            $currentWhere = null;
            $inEntry = false;
        }
    }
    
    if ($currentNaan && $currentWhere && !empty($currentWhere)) {
        $naanMap[$currentNaan] = $currentWhere;
    }
    
    if (empty($naanMap)) {
        throw new Exception("No NAANs found in registry response");
    }
    
    return $naanMap;
}

/**
 * Save cache to file with atomic write
 */
function saveCache($data) {
    $cache = [
        'timestamp' => time(),
        'expires_at' => time() + CACHE_EXPIRY,
        'data' => $data,
        'count' => count($data),
        'version' => '3.1.1.0'
    ];
    
    $json = json_encode($cache, JSON_PRETTY_PRINT);
    
    // Write to temp file first, then rename (atomic operation)
    $tempFile = CACHE_FILE . '.tmp';
    if (file_put_contents($tempFile, $json) === false) {
        throw new Exception("Failed to write temporary cache file");
    }
    
    // Verify JSON is valid
    $test = json_decode(file_get_contents($tempFile), true);
    if ($test === null) {
        unlink($tempFile);
        throw new Exception("Invalid JSON generated");
    }
    
    // Atomic rename
    if (!rename($tempFile, CACHE_FILE)) {
        unlink($tempFile);
        throw new Exception("Failed to move cache file");
    }
    
    // Verify file was written
    if (!file_exists(CACHE_FILE)) {
        throw new Exception("Cache file not found after write");
    }
    
    return $cache;
}

/**
 * Load existing cache (fallback)
 */
function loadCache() {
    if (!file_exists(CACHE_FILE)) {
        return null;
    }
    
    $content = file_get_contents(CACHE_FILE);
    if ($content === false) {
        return null;
    }
    
    $cache = json_decode($content, true);
    
    if (empty($cache) || !isset($cache['data'])) {
        return null;
    }
    
    return $cache;
}

/**
 * Clean up old records from database
 */
function cleanupOldRecords($pdo) {
    $deleted = [];
    
    // Validation logs: 24 months
    $stmt = $pdo->prepare("
        DELETE FROM ark_validations 
        WHERE validated_at < DATE_SUB(NOW(), INTERVAL 24 MONTH)
        AND status != 'consent_change'
    ");
    $stmt->execute();
    $deleted['validations'] = $stmt->rowCount();
    
    // Consent logs: 5 years
    $stmt = $pdo->prepare("
        DELETE FROM ark_validations 
        WHERE validated_at < DATE_SUB(NOW(), INTERVAL 5 YEAR)
        AND status = 'consent_change'
    ");
    $stmt->execute();
    $deleted['consent_logs'] = $stmt->rowCount();
    
    // Rate limits: 24 hours
    $stmt = $pdo->prepare("
        DELETE FROM ark_rate_limits 
        WHERE last_attempt < DATE_SUB(NOW(), INTERVAL 24 HOUR)
    ");
    $stmt->execute();
    $deleted['rate_limits'] = $stmt->rowCount();
    
    // Tokens: expired
    $stmt = $pdo->prepare("
        DELETE FROM ark_validation_tokens 
        WHERE expires_at < NOW()
    ");
    $stmt->execute();
    $deleted['tokens'] = $stmt->rowCount();
    
    return $deleted;
}

// ============ MAIN EXECUTION ============

$log = function($message, $level = 'info') {
    error_log("[NAAN-Cache] [{$level}] " . $message);
};

$log("Starting NAAN cache update", 'info');

try {
    // 1. Fetch fresh NAAN list
    $log("Fetching NAAN list from n2t.net...", 'info');
    $naanList = fetchNaanList();
    $count = count($naanList);
    $log("Fetched {$count} NAANs", 'info');
    
    // 2. Save to cache (always update)
    $log("Saving to cache file...", 'info');
    $cache = saveCache($naanList);
    $log("Cache saved with {$cache['count']} NAANs", 'info');
    
    // 3. Cleanup old records
    $log("Cleaning old records...", 'info');
    $deleted = cleanupOldRecords($ark_pdo);
    $log("Cleaned: {$deleted['validations']} validations, {$deleted['rate_limits']} rate limits, {$deleted['tokens']} tokens, {$deleted['consent_logs']} consent logs", 'info');
    
    // 4. Output summary
    echo json_encode([
        'status' => 'success',
        'message' => 'NAAN cache updated and cleanup completed',
        'count' => $count,
        'cleanup' => $deleted,
        'cache_file' => CACHE_FILE,
        'cache_size' => filesize(CACHE_FILE),
        'timestamp' => date('c')
    ], JSON_PRETTY_PRINT);
    
    $log("Cache update completed successfully", 'info');
    
} catch (Exception $e) {
    // If update fails, keep the existing cache
    $existingCache = loadCache();
    
    if ($existingCache) {
        $log("Update failed, keeping existing cache - " . $e->getMessage(), 'warning');
        
        echo json_encode([
            'status' => 'warning',
            'message' => 'Update failed, keeping existing cache',
            'error' => $e->getMessage(),
            'count' => $existingCache['count'] ?? 0,
            'cache_age' => time() - ($existingCache['timestamp'] ?? time()),
            'timestamp' => date('c')
        ], JSON_PRETTY_PRINT);
    } else {
        $log("Update failed and no cache available - " . $e->getMessage(), 'error');
        
        echo json_encode([
            'status' => 'error',
            'message' => 'Update failed and no cache available',
            'error' => $e->getMessage()
        ]);
    }
    exit(1);
}