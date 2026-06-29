<?php
/**
 * Monitor diário do ARK Telemetry
 * Executar via cron uma vez por dia às 2h da manhã
 * 
 * Cron recomendado:
 * 0 2 * * * php /home/u753420024/domains/revistacarnaubais.com.br/public_html/ark-telemetry/daily_monitor.php
 */

require_once __DIR__ . '/bootstrap.php';
global $ark_pdo;

date_default_timezone_set('America/Fortaleza');

echo "[" . date('Y-m-d H:i:s') . "] Iniciando monitoramento diário...\n";

// Buscar todas as revistas ativas
$stmt = $ark_pdo->prepare("
    SELECT * FROM ark_journals 
    WHERE status IN ('active', 'pending')
    ORDER BY last_sync ASC
");
$stmt->execute();
$journals = $stmt->fetchAll();

echo "[" . date('Y-m-d H:i:s') . "] Encontradas " . count($journals) . " revistas para processar.\n";

$successCount = 0;
$errorCount = 0;

foreach ($journals as $journal) {
    echo "[" . date('Y-m-d H:i:s') . "] Processando: " . $journal['naan'] . " - " . $journal['journal_name'] . "\n";
    
    // Chamar o endpoint do plugin para pegar dados atualizados
    $url = $journal['api_endpoint'] . '?naan=' . urlencode($journal['naan']) . '&token=' . urlencode($journal['plugin_token']);
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'ARK-Telemetry-Monitor/1.0');
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($httpCode === 200 && $response) {
        $data = json_decode($response, true);
        
        if ($data && isset($data['arks_count'])) {
            // Atualizar dados
            $updateStmt = $ark_pdo->prepare("
                UPDATE ark_journals 
                SET arks_count = ?,
                    last_sync = NOW(),
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
                $data['journal_name'] ?? null,
                $data['country'] ?? null,
                $data['telemetry_level'] ?? null,
                $journal['naan']
            ]);
            
            // Registrar log
            $logStmt = $ark_pdo->prepare("
                INSERT INTO ark_sync_log (naan, action, status, message) 
                VALUES (?, 'daily_sync', 'success', ?)
            ");
            $logStmt->execute([
                $journal['naan'],
                "Synced: {$data['arks_count']} ARKs, level: {$data['telemetry_level']}"
            ]);
            
            echo "  ✓ Sucesso: {$data['arks_count']} ARKs\n";
            $successCount++;
        } else {
            throw new Exception("Invalid response format");
        }
    } else {
        // Incrementar tentativas de erro
        $attempts = $journal['sync_attempts'] + 1;
        $status = ($attempts >= 5) ? 'error' : 'active';
        
        $updateStmt = $ark_pdo->prepare("
            UPDATE ark_journals 
            SET sync_attempts = ?,
                status = ?,
                error_message = ?,
                last_sync = NULL
            WHERE naan = ?
        ");
        
        $errorMsg = "HTTP {$httpCode}";
        if ($curlError) $errorMsg .= " - CURL: " . $curlError;
        
        $updateStmt->execute([$attempts, $status, $errorMsg, $journal['naan']]);
        
        // Registrar erro
        $logStmt = $ark_pdo->prepare("
            INSERT INTO ark_sync_log (naan, action, status, message) 
            VALUES (?, 'daily_sync', 'error', ?)
        ");
        $logStmt->execute([$journal['naan'], $errorMsg]);
        
        echo "  ✗ Erro: {$errorMsg}\n";
        $errorCount++;
    }
    
    // Pequena pausa para não sobrecarregar
    usleep(500000); // 0.5 segundos
}

echo "[" . date('Y-m-d H:i:s') . "] Monitoramento concluído!\n";
echo "  - Sucessos: {$successCount}\n";
echo "  - Erros: {$errorCount}\n";