<?php
/**
 * NAAN Validation Endpoint
 * URL: POST https://revistacarnaubais.com.br/ark-telemetry/validate
 * 
 * Validates NAAN using local cache first, then n2t.net API as fallback.
 * 
 * @package ARKTelemetry
 * @version 3.1.1.0
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/rate_limit_helper.php';

global $ark_pdo;

header('Content-Type: application/json');

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    ark_json_response(['error' => 'Method not allowed']);
}

// Get client IP for rate limiting
$ip = $_SERVER['REMOTE_ADDR'];
$rateLimit = new RateLimitHelper($ark_pdo);

// Check rate limit (60 second base window, exponential backoff)
$check = $rateLimit->check($ip, 'validate_naan', 60);

if (!$check['allowed']) {
    http_response_code(429);
    ark_json_response([
        'valid' => false,
        'error' => 'Too many validation attempts',
        'wait_seconds' => $check['wait_seconds'],
        'wait_minutes' => ceil($check['wait_seconds'] / 60)
    ]);
}

// Parse JSON input
$input = json_decode(file_get_contents('php://input'), true);

// Validate required fields
if (!isset($input['naan']) || !isset($input['domain'])) {
    $rateLimit->recordAttempt($ip, 'validate_naan', false, 60);
    http_response_code(400);
    ark_json_response(['valid' => false, 'error' => 'Missing naan or domain']);
}

$naan = trim($input['naan']);
$domain = trim($input['domain']);

// Sanitize and validate NAAN format
$naanClean = preg_replace('/^ark:/', '', $naan);
$naanClean = preg_replace('/\/$/', '', $naanClean);

if (!preg_match('/^\d+$/', $naanClean) || strlen($naanClean) < 2) {
    $rateLimit->recordAttempt($ip, 'validate_naan', false, 60);
    http_response_code(400);
    ark_json_response(['valid' => false, 'error' => 'Invalid NAAN format']);
}

// Sanitize domain
$domain = preg_replace('#^https?://#', '', $domain);
$domain = rtrim($domain, '/');

if (empty($domain) || !preg_match('/^([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$/i', $domain)) {
    $rateLimit->recordAttempt($ip, 'validate_naan', false, 60);
    http_response_code(400);
    ark_json_response(['valid' => false, 'error' => 'Invalid domain format']);
}

// ========== VALIDATION ==========

$registeredDomain = null;
$cacheFile = __DIR__ . '/naan_cache.json';
$cacheCreated = false;

// Try to get from cache first
if (file_exists($cacheFile)) {
    $cache = json_decode(file_get_contents($cacheFile), true);
    if ($cache && isset($cache['data'][$naanClean])) {
        $registeredDomain = $cache['data'][$naanClean];
        ark_log("NAAN {$naanClean} found in cache", 'debug');
    } else {
        ark_log("NAAN {$naanClean} not found in cache", 'debug');
    }
} else {
    // Cache doesn't exist - create it directly (without including naan_cache.php)
    ark_log("Cache file not found, creating from n2t.net...", 'info');
    
    try {
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
        curl_close($ch);
        
        if ($httpCode === 200 && !empty($response)) {
            // Parse registry
            $naanMap = [];
            $lines = explode("\n", $response);
            $currentNaan = null;
            $currentWhere = null;
            $inEntry = false;
            
            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line) || strpos($line, '#') === 0) continue;
                
                if (strpos($line, 'naa:') === 0) {
                    if ($currentNaan && $currentWhere) {
                        $naanMap[$currentNaan] = $currentWhere;
                    }
                    $currentNaan = null;
                    $currentWhere = null;
                    $inEntry = true;
                    continue;
                }
                
                if (!$inEntry) continue;
                
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
            
            if (!empty($naanMap)) {
                $cacheData = [
                    'timestamp' => time(),
                    'expires_at' => time() + 86400,
                    'data' => $naanMap,
                    'count' => count($naanMap),
                    'version' => '3.1.1.0'
                ];
                
                file_put_contents($cacheFile, json_encode($cacheData, JSON_PRETTY_PRINT));
                $registeredDomain = $naanMap[$naanClean] ?? null;
                $cacheCreated = true;
                
                ark_log("Cache created with " . count($naanMap) . " NAANs", 'info');
            }
        }
    } catch (Exception $e) {
        ark_log("Failed to create cache: " . $e->getMessage(), 'warning');
    }
}

// ========== FALLBACK ==========

if (!$registeredDomain) {
    
    $metadataUrl = 'https://n2t.net/ark:' . $naanClean;
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $metadataUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'ARK-Plugin-Validator/3.1.1.0');
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($httpCode !== 200 || empty($response)) {
        $rateLimit->recordAttempt($ip, 'validate_naan', false, 60);
        ark_log("NAAN validation failed for ark:{$naanClean} - HTTP {$httpCode}", 'warning');
        http_response_code(400);
        ark_json_response([
            'valid' => false,
            'error' => 'NAAN not found on n2t.net registry'
        ]);
    }
    
    $metadata = json_decode($response, true);
    
    if (empty($metadata) || empty($metadata['properties']['where'])) {
        $rateLimit->recordAttempt($ip, 'validate_naan', false, 60);
        ark_log("Invalid metadata response for ark:{$naanClean}", 'warning');
        http_response_code(400);
        ark_json_response([
            'valid' => false,
            'error' => 'NAAN metadata incomplete'
        ]);
    }
    
    $registeredWhere = rtrim($metadata['properties']['where'] ?? '', '/');
    $registeredDomain = preg_replace('#^https?://#', '', $registeredWhere);
    $registeredDomain = rtrim($registeredDomain, '/');
}


// ========== DOMAIN COMPARISON ==========

// Normalize both domains (remove www., lowercase)
$domainNormalized = preg_replace('/^www\./', '', $domain);
$domainNormalized = strtolower($domainNormalized);

$registeredNormalized = preg_replace('/^www\./', '', $registeredDomain);
$registeredNormalized = strtolower($registeredNormalized);

$isValid = ($registeredNormalized === $domainNormalized);

// ========== GENERATE TEMPORARY TOKEN ==========

$token = null;
if ($isValid) {
    // Generate a secure random token
    $token = bin2hex(random_bytes(32));
    $tokenExpiry = 300; // 5 minutes
    
    $stmt = $ark_pdo->prepare("
        INSERT INTO ark_validation_tokens (naan, token, expires_at, created_at)
        VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND), NOW())
        ON DUPLICATE KEY UPDATE 
            token = VALUES(token),
            expires_at = VALUES(expires_at),
            created_at = NOW()
    ");
    $stmt->execute(['ark:' . $naanClean, $token, $tokenExpiry]);
}

// Log validation attempt
try {
    $stmt = $ark_pdo->prepare("
        INSERT INTO ark_validations (naan, domain, status, message) 
        VALUES (?, ?, ?, ?)
    ");
    
    $status = $isValid ? 'success' : 'failed';
    $message = $isValid ? 
        'Domain match verified - token generated' : 
        "Domain mismatch: registered={$registeredDomain}, provided={$domain}";
    
    $stmt->execute([
        'ark:' . $naanClean,
        $domain,
        $status,
        $message
    ]);
} catch (Exception $e) {
    ark_log("Failed to log validation: " . $e->getMessage(), 'error');
}

// ========== RESPONSE ==========

if ($isValid) {
    // Validation successful
    $rateLimit->recordAttempt($ip, 'validate_naan', true, 60);
    
    ark_log("NAAN ark:{$naanClean} validated successfully for domain {$domain}", 'info');
    
    http_response_code(200);
    ark_json_response([
        'valid' => true,
        'message' => 'NAAN is valid for this domain',
        'token' => $token,
        'expires_in' => 300,
        'expires_at' => date('c', strtotime('+5 minutes'))
    ]);
} else {
    // Validation failed - domain mismatch
    $rateLimit->recordAttempt($ip, 'validate_naan', false, 60);
    
    ark_log("Domain validation failed for ark:{$naanClean} - Expected: {$registeredDomain}, Got: {$domain}", 'info');
    
    http_response_code(200);
    ark_json_response([
        'valid' => false,
        'error' => 'NAAN belongs to different domain',
        'details' => "This NAAN (ark:{$naanClean}) is registered to '{$registeredDomain}', not '{$domain}'"
    ]);
}