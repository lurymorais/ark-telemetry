<?php
/**
 * Statistics Collection Endpoint - v3.1.0.0
 * URL: POST https://revistacarnaubais.com.br/ark-telemetry/collect
 * 
 * Receives aggregated statistics from plugins via scheduled task push.
 * Security: Requires valid token + plugin identity verification.
 * 
 * @package ARKTelemetry
 * @version 3.1.0.0
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

// Rate limiting by IP
$ip = $_SERVER['REMOTE_ADDR'];
$rateLimit = new RateLimitHelper($ark_pdo);
$check = $rateLimit->check($ip, 'collect_statistics', 60);

if (!$check['allowed']) {
    http_response_code(429);
    ark_json_response([
        'error' => 'Too many collection attempts',
        'wait_seconds' => $check['wait_seconds'],
        'wait_minutes' => ceil($check['wait_seconds'] / 60)
    ]);
}

// Parse JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (empty($input)) {
    $rateLimit->recordAttempt($ip, 'collect_statistics', false, 60);
    http_response_code(400);
    ark_json_response(['error' => 'Invalid JSON input']);
}

// ========== REGISTRATION REQUEST ==========
if (isset($input['action']) && $input['action'] === 'register') {
    $naan = trim($input['naan'] ?? '');
    $domain = trim($input['domain'] ?? '');
    $privateKey = trim($input['private_key'] ?? '');
    $pluginVersion = trim($input['plugin_version'] ?? '');
    
    if (empty($naan) || empty($domain) || empty($privateKey)) {
        http_response_code(400);
        ark_json_response(['error' => 'Missing required fields: naan, domain, private_key']);
    }
    
    try {
        // Check if this NAAN already exists
        $stmt = $ark_pdo->prepare("
            SELECT id FROM ark_statistics WHERE naan = ?
        ");
        $stmt->execute([$naan]);
        $existing = $stmt->fetch();
        
        if ($existing) {
            // Update existing record with private key
            $stmt = $ark_pdo->prepare("
                UPDATE ark_statistics 
                SET private_key = ?, 
                    plugin_version = ?,
                    received_at = NOW()
                WHERE naan = ?
            ");
            $stmt->execute([$privateKey, $pluginVersion, $naan]);
        } else {
            // Insert new record with private key
            $stmt = $ark_pdo->prepare("
                INSERT INTO ark_statistics (naan, private_key, plugin_version, received_at)
                VALUES (?, ?, ?, NOW())
            ");
            $stmt->execute([$naan, $privateKey, $pluginVersion]);
        }
        
        error_log("[ARK-Telemetry] Plugin registered: {$naan} at {$domain}");
        
        http_response_code(200);
        ark_json_response([
            'status' => 'registered',
            'message' => 'Plugin key registered successfully',
            'naan' => $naan
        ]);
        
    } catch (Exception $e) {
        error_log("[ARK-Telemetry] Registration error: " . $e->getMessage());
        http_response_code(500);
        ark_json_response(['error' => 'Failed to register plugin key']);
    }
    
    exit;
}

// ========== STATISTICS COLLECTION ==========

// Validate required fields
$requiredFields = ['naan', 'arks_count', 'plugin_version', 'token', 'private_key'];
foreach ($requiredFields as $field) {
    if (!isset($input[$field])) {
        $rateLimit->recordAttempt($ip, 'collect_statistics', false, 60);
        http_response_code(400);
        ark_json_response(['error' => "Missing required field: {$field}"]);
    }
}

$naan = trim($input['naan']);
$arksCount = (int) $input['arks_count'];
$pluginVersion = trim($input['plugin_version']);
$token = trim($input['token']);
$privateKey = trim($input['private_key']);
$domain = trim($input['domain'] ?? '');

// Validate NAAN format
$naanClean = preg_replace('/^ark:/', '', $naan);
if (!preg_match('/^\d+$/', $naanClean) || strlen($naanClean) < 2) {
    $rateLimit->recordAttempt($ip, 'collect_statistics', false, 60);
    http_response_code(400);
    ark_json_response(['error' => 'Invalid NAAN format']);
}

// Extract domain from request if not provided
if (empty($domain)) {
    $domain = preg_replace('#^https?://#', '', $_SERVER['HTTP_HOST'] ?? '');
    $domain = rtrim($domain, '/');
}

// ========== LAYER 1: VERIFY identity.txt ==========
$identityUrl = "https://{$domain}/plugins/pubIds/ark/identity.txt";

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $identityUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 5);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_USERAGENT, 'ARK-Telemetry/3.1.0.0');
curl_setopt($ch, CURLOPT_NOBODY, true);

$httpCode = 0;
try {
    curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
} catch (Exception $e) {
    $httpCode = 0;
}
curl_close($ch);

if ($httpCode !== 200) {
    $rateLimit->recordAttempt($ip, 'collect_statistics', false, 60);
    http_response_code(403);
    ark_json_response([
        'error' => 'Plugin identity file not found',
        'message' => 'The plugin is not installed on this domain',
        'required_file' => $identityUrl
    ]);
}

// ========== LAYER 2: VERIFY PRIVATE KEY ==========
$stmt = $ark_pdo->prepare("
    SELECT private_key FROM ark_statistics 
    WHERE naan = ?
    ORDER BY received_at DESC 
    LIMIT 1
");
$stmt->execute([$naan]);
$record = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$record || empty($record['private_key'])) {
    $rateLimit->recordAttempt($ip, 'collect_statistics', false, 60);
    http_response_code(403);
    ark_json_response([
        'error' => 'Plugin not registered',
        'message' => 'This NAAN has not registered a private key',
        'action' => 'Please install the plugin properly'
    ]);
}

// Compare received key with stored key using hash_equals for timing safety
if (!hash_equals($record['private_key'], $privateKey)) {
    $rateLimit->recordAttempt($ip, 'collect_statistics', false, 60);
    http_response_code(403);
    ark_json_response([
        'error' => 'Invalid private key',
        'message' => 'The plugin private key does not match our records'
    ]);
}

// ========== VERIFY TOKEN ==========
$stmt = $ark_pdo->prepare("
    SELECT token, naan, expires_at, created_at
    FROM ark_validation_tokens
    WHERE token = ?
      AND naan = ?
      AND expires_at > NOW()
    LIMIT 1
");
$stmt->execute([$token, 'ark:' . $naanClean]);
$tokenRecord = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$tokenRecord) {
    $rateLimit->recordAttempt($ip, 'collect_statistics', false, 60);
    http_response_code(403);
    ark_json_response([
        'error' => 'Invalid or expired token',
        'message' => 'Please validate your NAAN again to obtain a new token'
    ]);
}

// ========== CHECK TELEMETRY CONSENT ==========
$stmt = $ark_pdo->prepare("
    SELECT setting_value FROM plugin_settings
    WHERE plugin_name = 'arkpubidplugin'
    AND context_id = (
        SELECT context_id FROM plugin_settings 
        WHERE setting_name = 'arkPrefix' 
        AND setting_value = ?
        LIMIT 1
    )
    AND setting_name = 'telemetryEnabled'
");
$stmt->execute(['ark:' . $naanClean]);
$telemetryEnabled = $stmt->fetchColumn();

if ($telemetryEnabled !== '1') {
    $rateLimit->recordAttempt($ip, 'collect_statistics', false, 60);
    http_response_code(403);
    ark_json_response([
        'error' => 'Telemetry is disabled for this journal',
        'message' => 'Please enable telemetry in the plugin settings to send statistics',
        'consent_required' => true
    ]);
}

// Validate ARK count
if (!is_int($arksCount) || $arksCount < 0) {
    $rateLimit->recordAttempt($ip, 'collect_statistics', false, 60);
    http_response_code(400);
    ark_json_response(['error' => 'Invalid arks_count: must be non-negative integer']);
}

// Validate plugin version
if (!preg_match('/^\d+\.\d+\.\d+\.\d+(-[a-z0-9]+)?$/i', $pluginVersion)) {
    $rateLimit->recordAttempt($ip, 'collect_statistics', false, 60);
    http_response_code(400);
    ark_json_response(['error' => 'Invalid plugin_version format (expected: X.Y.Z.W)']);
}

// ========== STORE STATISTICS ==========
try {
    // Check if this NAAN already exists
    $stmt = $ark_pdo->prepare("
        SELECT id, arks_count, plugin_version, private_key, received_at 
        FROM ark_statistics 
        WHERE naan = ?
        ORDER BY received_at DESC 
        LIMIT 1
    ");
    $stmt->execute(['ark:' . $naanClean]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existing) {
        // Update existing record preserving the private key
        $stmt = $ark_pdo->prepare("
            UPDATE ark_statistics 
            SET arks_count = ?, 
                plugin_version = ?, 
                received_at = NOW() 
            WHERE id = ?
        ");
        $stmt->execute([$arksCount, $pluginVersion, $existing['id']]);
        
        error_log("[ARK-Telemetry] Statistics updated for ark:{$naanClean} - {$arksCount} ARKs (v{$pluginVersion})");
    } else {
        // Insert new record with private key
        $stmt = $ark_pdo->prepare("
            INSERT INTO ark_statistics (naan, arks_count, private_key, plugin_version, received_at)
            VALUES (?, ?, ?, ?, NOW())
        ");
        $stmt->execute(['ark:' . $naanClean, $arksCount, $privateKey, $pluginVersion]);
        
        error_log("[ARK-Telemetry] Statistics inserted for ark:{$naanClean} - {$arksCount} ARKs (v{$pluginVersion})");
    }
    
    // Delete used token (one-time use)
    $stmt = $ark_pdo->prepare("DELETE FROM ark_validation_tokens WHERE token = ?");
    $stmt->execute([$token]);
    
    // Record successful attempt (resets rate limit counter)
    $rateLimit->recordAttempt($ip, 'collect_statistics', true, 60);
    
    http_response_code(202);
    ark_json_response([
        'status' => 'accepted',
        'message' => 'Statistics recorded successfully',
        'identity_verified' => true,
        'key_verified' => true,
        'token_validated_at' => $tokenRecord['created_at'],
        'received_at' => date('c')
    ]);
    
} catch (Exception $e) {
    $rateLimit->recordAttempt($ip, 'collect_statistics', false, 60);
    
    // Safe logging - no path exposure
    error_log("[ARK-Telemetry] Failed to store statistics for ark:{$naanClean}");
    
    http_response_code(500);
    ark_json_response(['error' => 'Failed to store statistics']);
}