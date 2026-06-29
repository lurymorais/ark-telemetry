<?php
/**
 * pull_single.php - Executa pull para uma revista específica
 * Chamado pelos jobs do executor
 * 
 * Uso: php pull_single.php "naan=ark:16081" "force=1"
 */

$baseDir = '/home/u753420024/domains/revistacarnaubais.com.br/public_html';
$arkDir = $baseDir . '/ark-telemetry';

require_once $arkDir . '/bootstrap.php';
global $ark_pdo;

// Parse arguments
$naan = null;
$force = false;

foreach ($argv as $arg) {
    if (strpos($arg, 'naan=') === 0) {
        $naan = urldecode(substr($arg, 5));
    }
    if (strpos($arg, 'force=') === 0) {
        $force = substr($arg, 6) == '1';
    }
}

if (!$naan) {
    echo "ERROR: Missing naan parameter\n";
    exit(1);
}

echo "[" . date('Y-m-d H:i:s') . "] Pulling data for {$naan}\n";

// Buscar dados da revista
$stmt = $ark_pdo->prepare("SELECT * FROM ark_journals WHERE naan = ?");
$stmt->execute([$naan]);
$journal = $stmt->fetch();

if (!$journal) {
    echo "ERROR: Journal not found: {$naan}\n";
    exit(1);
}

// Chamar o endpoint do plugin
$url = $journal['api_endpoint'] . '?naan=' . urlencode($journal['naan']) . '&token=' . urlencode($journal['plugin_token']);

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
curl_setopt($ch, CURLOPT_USERAGENT, 'ARK-Telemetry-Pull/1.0');

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($httpCode === 200 && $response) {
    $data = json_decode($response, true);
    
    if ($data && isset($data['arks_count'])) {
        // Calcular próxima coleta (mesmo dia do próximo mês)
        $currentDay = $journal['scheduled_day'] ?: date('j');
        $nextPullDate = date('Y-m-d', strtotime('first day of next month +' . ($currentDay - 1) . ' days'));
        
        // Atualizar dados
        $updateStmt = $ark_pdo->prepare("
            UPDATE ark_journals 
            SET arks_count = ?,
                last_pull = NOW(),
                next_pull = ?,
                status = 'active',
                sync_attempts = 0,
                error_message = NULL,
                journal_name = COALESCE(?, journal_name),
                country = COALESCE(?, country),
                telemetry_level = COALESCE(?, telemetry_level)
            WHERE naan = ?
        ");
        
        $updateStmt->execute([
            $data['arks_count'],
            $nextPullDate,
            $data['journal_name'] ?? null,
            $data['country'] ?? null,
            $data['telemetry_level'] ?? null,
            $journal['naan']
        ]);
        
        // Registrar log
        $logStmt = $ark_pdo->prepare("
            INSERT INTO ark_sync_log (naan, action, status, message) 
            VALUES (?, 'scheduled_pull', 'success', ?)
        ");
        $logStmt->execute([
            $journal['naan'],
            "Collected: {$data['arks_count']} ARKs, next: {$nextPullDate}"
        ]);
        
        echo "SUCCESS: {$data['arks_count']} ARKs collected\n";
        exit(0);
    } else {
        echo "ERROR: Invalid response format\n";
        exit(1);
    }
} else {
    $errorMsg = "HTTP {$httpCode}";
    if ($curlError) $errorMsg .= " - CURL: " . $curlError;
    
    // Atualizar contador de erros
    $attempts = $journal['sync_attempts'] + 1;
    $status = ($attempts >= 5) ? 'error' : 'active';
    
    // Próxima tentativa em 1 dia
    $nextPullDate = date('Y-m-d', strtotime('+1 day'));
    
    $updateStmt = $ark_pdo->prepare("
        UPDATE ark_journals 
        SET sync_attempts = ?,
            status = ?,
            error_message = ?,
            next_pull = ?
        WHERE naan = ?
    ");
    $updateStmt->execute([$attempts, $status, $errorMsg, $nextPullDate, $journal['naan']]);
    
    // Registrar erro
    $logStmt = $ark_pdo->prepare("
        INSERT INTO ark_sync_log (naan, action, status, message) 
        VALUES (?, 'scheduled_pull', 'error', ?)
    ");
    $logStmt->execute([$journal['naan'], $errorMsg]);
    
    echo "ERROR: {$errorMsg}\n";
    exit(1);
}