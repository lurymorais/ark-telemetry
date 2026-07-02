<?php
/**
 * Statistics Collection Endpoint - v3.1.0.0
 * URL: POST https://revistacarnaubais.com.br/ark-telemetry/collect
 * 
 * Receives aggregated statistics from plugins via scheduled task push.
 * Security: Requires a valid token obtained from /validate.
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

// Validate required fields
$requiredFields = ['naan', 'arks_count', 'plugin_version', 'token'];
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

// Validate NAAN format
$naanClean = preg_replace('/^ark:/', '', $naan);
if (!preg_match('/^\d+$/', $naanClean) || strlen($naanClean) < 2) {
    $rateLimit->recordAttempt($ip, 'collect_statistics', false, 60);
    http_response_code(400);
    ark_json_response(['error' => 'Invalid NAAN format']);
}

// ========== Verify token ==========
// Token must be valid, not expired, and match the NAAN

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

// Token is valid - proceed
$validation = $tokenRecord;

// Validate ARK count
if (!is_int($arksCount) || $arksCount < 0) {
    $rateLimit->recordAttempt($ip, 'collect_statistics', false, 60);
    http_response_code(400);
    ark_json_response(['error' => 'Invalid arks_count: must be non-negative integer']);
}

// Validate plugin version (semantic versioning with 4 numbers)
if (!preg_match('/^\d+\.\d+\.\d+\.\d+(-[a-z0-9]+)?$/i', $pluginVersion)) {
    $rateLimit->recordAttempt($ip, 'collect_statistics', false, 60);
    http_response_code(400);
    ark_json_response(['error' => 'Invalid plugin_version format (expected: X.Y.Z.W)']);
}

// Store statistics
try {
    // Check if this NAAN already exists
    $stmt = $ark_pdo->prepare("
        SELECT id, arks_count, plugin_version, received_at 
        FROM ark_statistics 
        WHERE naan = ?
        ORDER BY received_at DESC 
        LIMIT 1
    ");
    $stmt->execute(['ark:' . $naanClean]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existing) {
        // Update existing record
        $stmt = $ark_pdo->prepare("
            UPDATE ark_statistics 
            SET arks_count = ?, 
                plugin_version = ?, 
                received_at = NOW() 
            WHERE id = ?
        ");
        $stmt->execute([$arksCount, $pluginVersion, $existing['id']]);
        
        ark_log("Statistics updated for ark:{$naanClean} - {$arksCount} ARKs (v{$pluginVersion})", 'info');
    } else {
        // Insert new record
        $stmt = $ark_pdo->prepare("
            INSERT INTO ark_statistics (naan, arks_count, plugin_version, received_at)
            VALUES (?, ?, ?, NOW())
        ");
        $stmt->execute(['ark:' . $naanClean, $arksCount, $pluginVersion]);
        
        ark_log("Statistics inserted for ark:{$naanClean} - {$arksCount} ARKs (v{$pluginVersion})", 'info');
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
        'token_validated_at' => $validation['created_at'],
        'received_at' => date('c')
    ]);
    
} catch (Exception $e) {
    $rateLimit->recordAttempt($ip, 'collect_statistics', false, 60);
    
    ark_log("Failed to store statistics: " . $e->getMessage(), 'error');
    
    http_response_code(500);
    ark_json_response(['error' => 'Failed to store statistics']);
}