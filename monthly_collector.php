<?php
/**
 * Coletor Mensal de ARK Telemetry
 * Executar via cron DIARIAMENTE (ele verifica quais revistas devem ser coletadas hoje)
 * 
 * Cron recomendado:
 * 0 2 * * * php /home/u753420024/domains/revistacarnaubais.com.br/public_html/ark-telemetry/monthly_collector.php
 */

require_once __DIR__ . '/bootstrap.php';
global $ark_pdo;

date_default_timezone_set('America/Fortaleza');

echo "[" . date('Y-m-d H:i:s') . "] Iniciando coletor mensal...\n";

// Limpar rate limits antigos (1x por dia)
$rateLimit = new RateLimitHelper($ark_pdo);
$rateLimit->cleanOldRecords(24);

// Buscar revistas que devem ser coletadas HOJE (next_pull <= hoje)
$today = date('Y-m-d');
$stmt = $ark_pdo->prepare("
    SELECT * FROM ark_journals 
    WHERE status = 'active' 
    AND next_pull <= ?
    ORDER BY next_pull ASC
");
$stmt->execute([$today]);
$journals = $stmt->fetchAll();

echo "[" . date('Y-m-d H:i:s') . "] Encontradas " . count($journals) . " revistas para coletar hoje.\n";

$successCount = 0;
$errorCount = 0;

foreach ($journals as $journal) {
    echo "[" . date('Y-m-d H:i:s') . "] Coletando: " . $journal['naan'] . " - " . $journal['journal_name'] . "\n";
    
    // Chamar o endpoint do plugin para pegar dados atualizados
    $url = $journal['api_endpoint'] . '?naan=' . urlencode($journal['naan']) . '&token=' . urlencode($journal['plugin_token']);
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'ARK-Telemetry-Collector/1.0');
    
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
                VALUES (?, 'monthly_pull', 'success', ?)
            ");
            $logStmt->execute([
                $journal['naan'],
                "Collected: {$data['arks_count']} ARKs, next pull: {$nextPullDate}"
            ]);
            
            echo "  ✓ Sucesso: {$data['arks_count']} ARKs, próximo: {$nextPullDate}\n";
            $successCount++;
        } else {
            throw new Exception("Invalid response format");
        }
    } else {
        // Incrementar tentativas de erro
        $attempts = $journal['sync_attempts'] + 1;
        $status = ($attempts >= 5) ? 'error' : 'active';
        
        // Próxima tentativa em 1 dia (para erros temporários)
        $nextPullDate = date('Y-m-d', strtotime('+1 day'));
        
        $updateStmt = $ark_pdo->prepare("
            UPDATE ark_journals 
            SET sync_attempts = ?,
                status = ?,
                error_message = ?,
                next_pull = ?
            WHERE naan = ?
        ");
        
        $errorMsg = "HTTP {$httpCode}";
        if ($curlError) $errorMsg .= " - CURL: " . $curlError;
        
        $updateStmt->execute([$attempts, $status, $errorMsg, $nextPullDate, $journal['naan']]);
        
        // Registrar erro
        $logStmt = $ark_pdo->prepare("
            INSERT INTO ark_sync_log (naan, action, status, message) 
            VALUES (?, 'monthly_pull', 'error', ?)
        ");
        $logStmt->execute([$journal['naan'], $errorMsg]);
        
        echo "  ✗ Erro: {$errorMsg}, próxima tentativa: {$nextPullDate}\n";
        $errorCount++;
    }
    
    // Pequena pausa para não sobrecarregar
    usleep(500000); // 0.5 segundos
}

echo "[" . date('Y-m-d H:i:s') . "] Coletor mensal concluído!\n";
echo "  - Sucessos: {$successCount}\n";
echo "  - Erros: {$errorCount}\n";