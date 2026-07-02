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
 * Fetch active NAANs from n2t.net
 * 
 * @return array List of NAANs with their registered domains
 */
function fetchNaanList() {
    $url = 'https://n2t.net/naan_list.json';
    
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
    
    $data = json_decode($response, true);
    
    if (!is_array($data)) {
        throw new Exception("Invalid response format from n2t.net");
    }
    
    // Build a simple array: naan => domain
    $naanMap = [];
    foreach ($data as $naan => $info) {
        $domain = isset($info['where']) ? preg_replace('#^https?://#', '', $info['where']) : '';
        $domain = rtrim($domain, '/');
        if (!empty($domain)) {
            $naanMap[$naan] = $domain;
        }
    }
    
    return $naanMap;
}

/**
 * Save cache to file
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
    
    // Save to cache
    $cache = saveCache($naanList);
    
    ark_log("NAAN Cache: Updated successfully - {$count} NAANs cached", 'info');
    
    echo json_encode([
        'status' => 'success',
        'message' => "NAAN cache updated successfully",
        'count' => $count,
        'timestamp' => date('c')
    ]);
    
} catch (Exception $e) {
    // If update fails, try to keep the existing cache
    $existingCache = loadCache();
    
    if ($existingCache) {
        ark_log("NAAN Cache: Update failed, using existing cache - " . $e->getMessage(), 'warning');
        
        echo json_encode([
            'status' => 'warning',
            'message' => 'Update failed, using existing cache',
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