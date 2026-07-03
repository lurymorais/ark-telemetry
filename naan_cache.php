#!/usr/bin/php
<?php
/**
 * NAAN Cache Updater & Cleanup - v3.1.0.0
 * Run daily via cron to fetch NAAN list and clean old records
 * 
 * @package ARKTelemetry
 * @version 3.1.0.0
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/rate_limit_helper.php';

global $ark_pdo;

define('CACHE_FILE', __DIR__ . '/naan_cache.json');
define('CACHE_EXPIRY', 86400); // 24 hours

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
    curl_setopt($ch, CURLOPT_USERAGENT, 'ARK-Telemetry-Cache/3.1.0.0');
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
 * Save cache to file (overwrites existing)
 */
function saveCache($data) {
    $cache = [
        'timestamp' => time(),
        'expires_at' => time() + CACHE_EXPIRY,
        'data' => $data,
        'count' => count($data)
    ];
    
    file_put_contents(CACHE_FILE, json_encode($cache, JSON_PRETTY_PRINT));
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
    $cache = json_decode($content, true);
    
    if (empty($cache) || !isset($cache['data'])) {
        return null;
    }
    
    return $cache;
}

/**
 * Clean up old records from database
 * - Validation logs: delete after 24 months
 * - Rate limits: delete after 24 hours
 * - Tokens: delete after expiration (expires_at)
 * - Statistics: never deleted (cumulative data)
 */
function cleanupOldRecords($pdo) {
    $deleted = [];
    
    $stmt = $pdo->prepare("
        DELETE FROM ark_validations 
        WHERE validated_at < DATE_SUB(NOW(), INTERVAL 24 MONTH)
    ");
    $stmt->execute();
    $deleted['validations'] = $stmt->rowCount();
    
    $stmt = $pdo->prepare("
        DELETE FROM ark_rate_limits 
        WHERE last_attempt < DATE_SUB(NOW(), INTERVAL 24 HOUR)
    ");
    $stmt->execute();
    $deleted['rate_limits'] = $stmt->rowCount();
    
    $stmt = $pdo->prepare("
        DELETE FROM ark_validation_tokens 
        WHERE expires_at < NOW()
    ");
    $stmt->execute();
    $deleted['tokens'] = $stmt->rowCount();
        
    return $deleted;
}

// ============ MAIN EXECUTION ============

ark_log("NAAN Cache: Starting update and cleanup", 'info');

try {
    // 1. Fetch fresh NAAN list
    $naanList = fetchNaanList();
    $count = count($naanList);
    
    // 2. Save to cache
    $cache = saveCache($naanList);
    
    // 3. Cleanup old records
    $deleted = cleanupOldRecords($ark_pdo);
    
    ark_log("NAAN Cache: Updated successfully - {$count} NAANs cached", 'info');
    ark_log("Cleanup: deleted {$deleted['validations']} validations, {$deleted['rate_limits']} rate limits, {$deleted['tokens']} tokens", 'info');
    
    echo json_encode([
        'status' => 'success',
        'message' => "NAAN cache updated and cleanup completed",
        'count' => $count,
        'cleanup' => $deleted,
        'timestamp' => date('c')
    ]);
    
} catch (Exception $e) {
    // If update fails, keep the existing cache
    $existingCache = loadCache();
    
    if ($existingCache) {
        ark_log("NAAN Cache: Update failed, keeping existing cache - " . $e->getMessage(), 'warning');
        
        echo json_encode([
            'status' => 'warning',
            'message' => 'Update failed, keeping existing cache',
            'error' => $e->getMessage(),
            'count' => $existingCache['count'] ?? 0,
            'timestamp' => date('c')
        ]);
    } else {
        ark_log("NAAN Cache: Update failed and no cache available - " . $e->getMessage(), 'error');
        
        echo json_encode([
            'status' => 'error',
            'message' => 'Update failed and no cache available',
            'error' => $e->getMessage()
        ]);
    }
}