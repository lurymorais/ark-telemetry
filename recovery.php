<?php
/**
 * Endpoint para recuperação de token - Com backoff exponencial (sem bloqueio)
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/rate_limit_helper.php';
global $ark_pdo;

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];
$ip = $_SERVER['REMOTE_ADDR'];
$rateLimit = new RateLimitHelper($ark_pdo);

// GET para verificar status
if ($method === 'GET' && isset($_GET['check'])) {
    $check = $rateLimit->check($ip, 'recovery', 300); // 5min base, cresce exponencialmente
    
    echo json_encode([
        'canAttempt' => $check['allowed'],
        'wait_seconds' => $check['wait_seconds'],
        'wait_minutes' => $check['wait_minutes'] ?? 0,
        'attempts' => $check['attempts']
    ]);
    exit;
}

if ($method !== 'POST') {
    echo json_encode(['status' => false, 'content' => 'Método não permitido.']);
    exit;
}

// Verifica rate limit com backoff exponencial
$check = $rateLimit->check($ip, 'recovery', 300);

if (!$check['allowed']) {
    echo json_encode([
        'status' => false,
        'content' => $check['message'],
        'wait_seconds' => $check['wait_seconds'],
        'wait_minutes' => $check['wait_minutes'],
        'attempts' => $check['attempts'],
        'backoff' => true
    ]);
    exit;
}

// Busca domínio atual
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$currentUrl = $protocol . '://' . $_SERVER['HTTP_HOST'];
$currentDomain = preg_replace('#^https?://#', '', $currentUrl);

// Busca NAAN
$stmt = $ark_pdo->prepare("SELECT naan, plugin_token FROM ark_journals WHERE journal_url LIKE :domain");
$stmt->execute([':domain' => '%' . $currentDomain . '%']);
$journal = $stmt->fetch();

if (!$journal) {
    $rateLimit->recordAttempt($ip, 'recovery', false, 300);
    echo json_encode([
        'status' => false,
        'content' => 'Nenhum NAAN encontrado para este domínio.'
    ]);
    exit;
}

$naan = $journal['naan'];
$naanClean = preg_replace('/^ark:/', '', $naan);
$metadataUrl = 'https://n2t.net/ark:' . $naanClean;

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $metadataUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
curl_setopt($ch, CURLOPT_USERAGENT, 'ARK-Plugin-Recovery/2.0');

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200 || empty($response)) {
    $rateLimit->recordAttempt($ip, 'recovery', false, 300);
    echo json_encode([
        'status' => false,
        'content' => "Não foi possível verificar o NAAN. HTTP {$httpCode}"
    ]);
    exit;
}

$metadata = json_decode($response, true);
$registeredWhere = rtrim($metadata['properties']['where'] ?? '', '/');
$registeredDomain = preg_replace('#^https?://#', '', $registeredWhere);

if ($registeredDomain === $currentDomain) {
    $newToken = bin2hex(random_bytes(32));
    
    $stmt = $ark_pdo->prepare("UPDATE ark_journals SET plugin_token = :token WHERE naan = :naan");
    $stmt->execute([':token' => $newToken, ':naan' => $naan]);
    
    $stmt = $ark_pdo->prepare("
        INSERT INTO ark_sync_log (naan, action, status, message) 
        VALUES (?, 'token_recovery', 'success', 'Token recovered via n2t.net validation')
    ");
    $stmt->execute([$naan]);
    
    // Sucesso: reseta o contador
    $rateLimit->recordAttempt($ip, 'recovery', true, 300);
    
    echo json_encode([
        'status' => true,
        'content' => 'Propriedade confirmada! Token regenerado com sucesso.',
        'new_token' => $newToken
    ]);
} else {
    $rateLimit->recordAttempt($ip, 'recovery', false, 300);
    echo json_encode([
        'status' => false,
        'content' => "O domínio registrado é '{$registeredDomain}', mas seu site é '{$currentDomain}'."
    ]);
}