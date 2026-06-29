<?php
/**
 * Validação rápida de NAAN - Com backoff exponencial (sem bloqueio)
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/rate_limit_helper.php';

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['valid' => false, 'message' => 'Método não permitido']);
    exit;
}

// Rate limit por IP com backoff exponencial (sem bloqueio)
$ip = $_SERVER['REMOTE_ADDR'];
$rateLimit = new RateLimitHelper($ark_pdo);
$check = $rateLimit->check($ip, 'validate_naan', 60); // 60s base, cresce exponencialmente

if (!$check['allowed']) {
    echo json_encode([
        'valid' => false, 
        'message' => $check['message'],
        'wait_seconds' => $check['wait_seconds'],
        'wait_minutes' => $check['wait_minutes'],
        'attempts' => $check['attempts']
    ]);
    exit;
}

$naan = $_POST['naan'] ?? '';
$domain = $_POST['domain'] ?? '';

if (empty($naan) || empty($domain)) {
    $rateLimit->recordAttempt($ip, 'validate_naan', false, 60);
    echo json_encode(['valid' => false, 'message' => 'Dados incompletos']);
    exit;
}

$naanClean = preg_replace('/^ark:/', '', $naan);
$naanClean = preg_replace('/\/$/', '', $naanClean);

if (empty($naanClean)) {
    $rateLimit->recordAttempt($ip, 'validate_naan', false, 60);
    echo json_encode(['valid' => false, 'message' => 'Formato de NAAN inválido']);
    exit;
}

$metadataUrl = 'https://n2t.net/ark:' . $naanClean;

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $metadataUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_USERAGENT, 'ARK-Validator/2.0');

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200) {
    $rateLimit->recordAttempt($ip, 'validate_naan', false, 60);
    echo json_encode([
        'valid' => false, 
        'message' => "NAAN {$naanClean} não encontrado no registro da ARK Alliance."
    ]);
    exit;
}

$metadata = json_decode($response, true);
$registeredWhere = rtrim($metadata['properties']['where'] ?? '', '/');
$registeredDomain = preg_replace('#^https?://#', '', $registeredWhere);

if ($registeredDomain === $domain) {
    // Sucesso: reseta o contador
    $rateLimit->recordAttempt($ip, 'validate_naan', true, 60);
    echo json_encode(['valid' => true]);
} else {
    $rateLimit->recordAttempt($ip, 'validate_naan', false, 60);
    echo json_encode([
        'valid' => false, 
        'message' => "Este NAAN pertence ao domínio '{$registeredDomain}', não ao seu domínio '{$domain}'."
    ]);
}