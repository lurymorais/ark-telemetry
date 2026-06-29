<?php
/**
 * Dashboard administrativo do ARK Telemetry
 * URL: https://revistacarnaubais.com.br/ark-telemetry/dashboard.php
 * 
 * Acesso protegido por HTTP Basic Auth
 * Usuário: admin
 * Senha: admin
 */

$auth_user = 'admin';
$auth_password = 'admin';

if (!isset($_SERVER['PHP_AUTH_USER']) || 
    $_SERVER['PHP_AUTH_USER'] !== $auth_user || 
    $_SERVER['PHP_AUTH_PW'] !== $auth_password) {
    header('WWW-Authenticate: Basic realm="ARK Telemetry - Acesso Restrito"');
    header('HTTP/1.0 401 Unauthorized');
    echo '<h1>Acesso Negado</h1><p>Você não tem permissão para acessar esta página.</p>';
    exit;
}

require_once __DIR__ . '/bootstrap.php';
global $ark_pdo;

date_default_timezone_set('America/Fortaleza');

// Forçar atualização do cache se solicitado
$refreshStats = isset($_GET['refresh_stats']) && $_GET['refresh_stats'] == '1';
$refreshMessage = '';

if ($refreshStats) {
    $cacheFile = __DIR__ . '/stats_cache.json';
    if (file_exists($cacheFile)) {
        unlink($cacheFile);
        $refreshMessage = 'Cache de estatísticas atualizado com sucesso!';
    } else {
        $refreshMessage = 'Cache já estava vazio.';
    }
    // Redireciona para remover o parâmetro da URL
    header('Location: dashboard.php?updated=1');
    exit;
}

// Processar ações individuais do dashboard
$action = $_GET['action'] ?? '';
$message = '';
$messageType = '';

if ($action === 'force_sync' && isset($_GET['naan'])) {
    $naan = $_GET['naan'];
    try {
        $stmt = $ark_pdo->prepare("SELECT * FROM ark_journals WHERE naan = ?");
        $stmt->execute([$naan]);
        $journal = $stmt->fetch();
        
        if ($journal) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $journal['api_endpoint'] . '?naan=' . $journal['naan'] . '&token=' . $journal['plugin_token']);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode === 200) {
                $data = json_decode($response, true);
                $stmt = $ark_pdo->prepare("
                    UPDATE ark_journals 
                    SET arks_count = ?, last_sync = NOW(), status = 'active', sync_attempts = 0, error_message = NULL
                    WHERE naan = ?
                ");
                $stmt->execute([$data['arks_count'] ?? $journal['arks_count'], $naan]);
                
                $stmt = $ark_pdo->prepare("
                    INSERT INTO ark_sync_log (naan, action, status, message) 
                    VALUES (?, 'manual_sync', 'success', ?)
                ");
                $stmt->execute([$naan, "Manual sync: {$data['arks_count']} ARKs"]);
                
                $message = "Sincronização manual realizada com sucesso!";
                $messageType = "success";
                
                // Invalidar cache após sync
                $cacheFile = __DIR__ . '/stats_cache.json';
                if (file_exists($cacheFile)) unlink($cacheFile);
            } else {
                $message = "Erro na sincronização: HTTP {$httpCode}";
                $messageType = "error";
            }
        }
    } catch (Exception $e) {
        $message = "Erro: " . $e->getMessage();
        $messageType = "error";
    }
}

if ($action === 'reset_token' && isset($_GET['naan'])) {
    $naan = $_GET['naan'];
    try {
        $newToken = bin2hex(random_bytes(32));
        $stmt = $ark_pdo->prepare("UPDATE ark_journals SET plugin_token = ? WHERE naan = ?");
        $stmt->execute([$newToken, $naan]);
        
        $stmt = $ark_pdo->prepare("
            INSERT INTO ark_sync_log (naan, action, status, message) 
            VALUES (?, 'token_reset', 'success', 'Token regenerated manually')
        ");
        $stmt->execute([$naan]);
        
        $message = "Token regenerado com sucesso!";
        $messageType = "success";
    } catch (Exception $e) {
        $message = "Erro ao regenerar token: " . $e->getMessage();
        $messageType = "error";
    }
}

$showUpdateMessage = isset($_GET['updated']);

// Buscar todos os periódicos
$stmt = $ark_pdo->query("
    SELECT * FROM ark_journals 
    ORDER BY updated_at DESC
");
$journals = $stmt->fetchAll();

// Estatísticas
$stmt = $ark_pdo->query("
    SELECT 
        SUM(arks_count) as total_arks,
        COUNT(*) as total_journals,
        SUM(CASE WHEN telemetry_level = 'public' THEN 1 ELSE 0 END) as public_count,
        SUM(CASE WHEN telemetry_level = 'restricted' THEN 1 ELSE 0 END) as restricted_count,
        SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_count,
        SUM(CASE WHEN status = 'error' THEN 1 ELSE 0 END) as error_count
    FROM ark_journals
");
$stats = $stmt->fetch();

// Últimos logs
$stmt = $ark_pdo->query("
    SELECT * FROM ark_sync_log 
    ORDER BY created_at DESC 
    LIMIT 20
");
$logs = $stmt->fetchAll();

?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ARK Telemetry Dashboard</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; 
            background: #f5f5f5; 
            padding: 20px; 
        }
        .container { max-width: 1400px; margin: 0 auto; }
        .header { 
            background: white; 
            padding: 20px; 
            border-radius: 8px; 
            margin-bottom: 20px; 
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
        }
        .header h1 { color: #d00a6c; }
        .refresh-all-btn {
            background: #d00a6c;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            text-decoration: none;
            display: inline-block;
        }
        .refresh-all-btn:hover {
            background: #a00852;
        }
        .stats { 
            display: grid; 
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); 
            gap: 20px; 
            margin-bottom: 30px; 
        }
        .stat-card { 
            background: white; 
            padding: 20px; 
            border-radius: 8px; 
            box-shadow: 0 2px 4px rgba(0,0,0,0.1); 
            text-align: center;
        }
        .stat-card h3 { color: #666; font-size: 14px; margin-bottom: 10px; }
        .stat-card .number { font-size: 32px; font-weight: bold; color: #d00a6c; }
        .table-container { 
            background: white; 
            border-radius: 8px; 
            padding: 20px; 
            box-shadow: 0 2px 4px rgba(0,0,0,0.1); 
            overflow-x: auto;
            margin-bottom: 30px;
        }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #eee; }
        th { background: #f8f9fa; font-weight: 600; color: #333; }
        tr:hover { background: #f8f9fa; }
        .badge { 
            display: inline-block; 
            padding: 3px 8px; 
            border-radius: 4px; 
            font-size: 12px; 
            font-weight: 500; 
        }
        .badge-public { background: #28a745; color: white; }
        .badge-restricted { background: #ffc107; color: #333; }
        .badge-active { background: #28a745; color: white; }
        .badge-error { background: #dc3545; color: white; }
        .badge-pending { background: #ffc107; color: #333; }
        .badge-success { background: #28a745; color: white; }
        .message { 
            padding: 15px; 
            border-radius: 6px; 
            margin-bottom: 20px;
            animation: fadeOut 5s forwards;
        }
        .message-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .message-error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .btn {
            display: inline-block;
            padding: 5px 12px;
            border-radius: 4px;
            text-decoration: none;
            font-size: 12px;
            margin: 0 3px;
            cursor: pointer;
            border: none;
        }
        .btn-sync { background: #006798; color: white; }
        .btn-reset { background: #ffc107; color: #333; }
        .btn:hover { opacity: 0.8; }
        @keyframes fadeOut {
            0% { opacity: 1; }
            70% { opacity: 1; }
            100% { opacity: 0; visibility: hidden; display: none; }
        }
        .footer { text-align: center; margin-top: 30px; color: #666; font-size: 12px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>ARK Telemetry Dashboard</h1>
            <div>
                <a href="stats.php" target="_blank" style="background: #006798; color: white; padding: 10px 20px; border-radius: 6px; text-decoration: none; margin-right: 10px;">
                    📊 Ver Estatísticas Públicas
                </a>
                <a href="?refresh_stats=1" class="refresh-all-btn">
                    🔄 Atualizar Todas Estatísticas
                </a>
            </div>
        </div>
        
        <?php if ($showUpdateMessage): ?>
        <div class="message message-success">
            ✅ <?php echo $refreshMessage ?: 'Cache de estatísticas atualizado com sucesso!'; ?>
        </div>
        <?php endif; ?>
        
        <?php if ($message): ?>
        <div class="message message-<?php echo $messageType; ?>">
            <?php echo htmlspecialchars($message); ?>
        </div>
        <?php endif; ?>
        
        <div class="stats">
            <div class="stat-card">
                <h3>Total de ARKs</h3>
                <div class="number"><?php echo number_format($stats['total_arks'] ?? 0, 0, ',', '.'); ?></div>
            </div>
            <div class="stat-card">
                <h3>Total de Revistas</h3>
                <div class="number"><?php echo $stats['total_journals'] ?? 0; ?></div>
            </div>
            <div class="stat-card">
                <h3>Modo Público</h3>
                <div class="number"><?php echo $stats['public_count'] ?? 0; ?></div>
            </div>
            <div class="stat-card">
                <h3>Modo Restrito</h3>
                <div class="number"><?php echo $stats['restricted_count'] ?? 0; ?></div>
            </div>
            <div class="stat-card">
                <h3>Ativas</h3>
                <div class="number"><?php echo $stats['active_count'] ?? 0; ?></div>
            </div>
            <div class="stat-card">
                <h3>Com Erro</h3>
                <div class="number"><?php echo $stats['error_count'] ?? 0; ?></div>
            </div>
        </div>
        
        <div class="table-container">
            <h2 style="margin-bottom: 20px;">Revistas Registradas</h2>
            <table>
                <thead>
                    <tr>
                        <th>NAAN</th>
                        <th>Revista/Instituição</th>
                        <th>País</th>
                        <th>Email</th>
                        <th>Idioma</th>
                        <th>ARKs</th>
                        <th>Modo</th>
                        <th>Status</th>
                        <th>Última Sincronia</th>
                        <th>Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($journals as $journal): ?>
                    <tr>
                        <td><code><?php echo htmlspecialchars($journal['naan']); ?></code></td>
                        <td>
                            <?php if (!empty($journal['journal_url'])): ?>
                                <a href="<?php echo htmlspecialchars($journal['journal_url']); ?>" target="_blank">
                                    <?php echo htmlspecialchars($journal['journal_name'] ?: 'N/A'); ?>
                                </a>
                            <?php else: ?>
                                <?php echo htmlspecialchars($journal['journal_name'] ?: 'N/A'); ?>
                            <?php endif; ?>
                        </td>
                        <td><?php echo htmlspecialchars($journal['country'] ?: '—'); ?></td>
                        <td><?php echo htmlspecialchars($journal['email'] ?: '—'); ?></td>
                        <td><?php echo htmlspecialchars($journal['primary_language'] ?: '—'); ?></td>
                        <td><?php echo number_format($journal['arks_count'], 0, ',', '.'); ?></td>
                        <td>
                            <span class="badge badge-<?php echo $journal['telemetry_level']; ?>">
                                <?php echo $journal['telemetry_level'] === 'public' ? 'Público' : 'Restrito'; ?>
                            </span>
                        </td>
                        <td>
                            <span class="badge badge-<?php echo $journal['status']; ?>">
                                <?php echo ucfirst($journal['status']); ?>
                            </span>
                        </td>
                        <td>
                            <?php echo $journal['last_sync'] ? date('d/m/Y H:i', strtotime($journal['last_sync'])) : 'Nunca'; ?>
                        </td>
                        <td>
                            <a href="?action=force_sync&naan=<?php echo urlencode($journal['naan']); ?>" 
                               class="btn btn-sync" 
                               onclick="return confirm('Forçar sincronização desta revista?')">
                                Sync
                            </a>
                            <a href="?action=reset_token&naan=<?php echo urlencode($journal['naan']); ?>" 
                               class="btn btn-reset" 
                               onclick="return confirm('Regenerar token? O plugin precisará ser reconfigurado.')">
                                Reset Token
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <div class="table-container">
            <h2 style="margin-bottom: 20px;">Logs de Monitoramento</h2>
            <table>
                <thead>
                    <tr>
                        <th>Data</th>
                        <th>NAAN</th>
                        <th>Ação</th>
                        <th>Status</th>
                        <th>Mensagem</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $log): ?>
                    <tr>
                        <td><?php echo date('d/m/Y H:i:s', strtotime($log['created_at'])); ?></td>
                        <td><code><?php echo htmlspecialchars($log['naan'] ?: '—'); ?></code></td>
                        <td><?php echo htmlspecialchars($log['action']); ?></td>
                        <td>
                            <span class="badge badge-<?php echo $log['status']; ?>">
                                <?php echo $log['status'] === 'success' ? 'Sucesso' : 'Erro'; ?>
                            </span>
                        </td>
                        <td><?php echo htmlspecialchars($log['message'] ?: '—'); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <div class="footer">
            <p>ARK Plugin Telemetry System v3.0 | Dados sincronizados diretamente do plugin</p>
            <p><small>Dashboard protegido por autenticação HTTP Basic</small></p>
        </div>
    </div>
    
    <script>
        // Auto-hide message after 5 seconds
        const message = document.querySelector('.message');
        if (message) {
            setTimeout(() => {
                if (message) message.style.display = 'none';
            }, 5000);
        }
    </script>
</body>
</html>