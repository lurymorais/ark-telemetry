#!/usr/bin/php
<?php
/**
 * NAAN Cache Updater - v3.1.0.0
 * Run daily via cron to fetch the list of active NAANs from n2t.net
 * 
 * @package ARKTelemetry
 * @version 3.1.0.0
 */

require_once __DIR__ . '/bootstrap.php';

define('CACHE_FILE', __DIR__ . '/naan_cache.json');
define('CACHE_EXPIRY', 86400); // 24 hours

/**
 * Fetch active NAANs from n2t.net registry
 * Source: https://cdluc3.github.io/naan_reg_priv/naan_registry.txt
 * 
 * Format:
 * naa:
 * who:   Organization Name (=) ACRONYM
 * what:  16081
 * when:  2025.11.17
 * where: https://revistacarnaubais.com.br
 * how:   NP | ['NR', 'OP', 'CC', 'LC'] | 2025 |
 * 
 * @return array List of NAANs with their registered domains
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
        
        // Skip empty lines and comments (lines starting with #)
        if (empty($line) || strpos($line, '#') === 0) {
            continue;
        }
        
        // Start of a new NAAN entry
        if (strpos($line, 'naa:') === 0) {
            // If we have a previous entry, save it
            if ($currentNaan && $currentWhere) {
                $naanMap[$currentNaan] = $currentWhere;
            }
            // Reset for new entry
            $currentNaan = null;
            $currentWhere = null;
            $inEntry = true;
            continue;
        }
        
        // Only process if we're inside an entry
        if (!$inEntry) {
            continue;
        }
        
        // Extract what: (NAAN number)
        if (strpos($line, 'what:') === 0) {
            $currentNaan = trim(substr($line, 5));
            // Remove any non-numeric characters
            $currentNaan = preg_replace('/[^0-9]/', '', $currentNaan);
        }
        
        // Extract where: (domain)
        if (strpos($line, 'where:') === 0) {
            $currentWhere = trim(substr($line, 6));
            // Remove http:// or https://
            $currentWhere = preg_replace('#^https?://#', '', $currentWhere);
            // Remove trailing slash
            $currentWhere = rtrim($currentWhere, '/');
        }
        
        // Check if entry is complete (has both what and where)
        if ($currentNaan && $currentWhere && !empty($currentWhere)) {
            // Save and reset for next entry
            $naanMap[$currentNaan] = $currentWhere;
            $currentNaan = null;
            $currentWhere = null;
            $inEntry = false;
        }
    }
    
    // Save any remaining entry
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

// ============ MAIN EXECUTION ============

ark_log("NAAN Cache: Starting update", 'info');

try {
    // Fetch fresh list
    $naanList = fetchNaanList();
    $count = count($naanList);
    
    // Save to cache (overwrites existing)
    $cache = saveCache($naanList);
    
    ark_log("NAAN Cache: Updated successfully - {$count} NAANs cached", 'info');
    
    echo json_encode([
        'status' => 'success',
        'message' => "NAAN cache updated successfully",
        'count' => $count,
        'timestamp' => date('c')
    ]);
    
} catch (Exception $e) {
    // If update fails, keep the existing cache (do not overwrite)
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