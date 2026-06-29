<?php
/**
 * API endpoint para total de ARKs (badge para README)
 * URL: https://revistacarnaubais.com.br/ark-telemetry/api.php
 */

require_once __DIR__ . '/bootstrap.php';
global $ark_pdo;

$cacheFile = __DIR__ . '/api_cache.json';
$cacheTime = 3600;

function getTotalArks($pdo, $cacheFile, $cacheTime) {
    if (file_exists($cacheFile)) {
        $cache = json_decode(file_get_contents($cacheFile), true);
        if ($cache && isset($cache['generated_at']) && (time() - $cache['generated_at']) < $cacheTime) {
            return $cache['total_arks'];
        }
    }
    
    try {
        $stmt = $pdo->query("SELECT SUM(arks_count) as total_global FROM ark_journals WHERE status = 'active'");
        $res = $stmt->fetch();
        $total = (int)($res['total_global'] ?? 0);
        
        $newCache = [
            'generated_at' => time(),
            'total_arks' => $total
        ];
        file_put_contents($cacheFile, json_encode($newCache));
        
        return $total;
    } catch (Exception $e) {
        return 0;
    }
}

$totalArks = getTotalArks($ark_pdo, $cacheFile, $cacheTime);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// Detecta o tema enviado pela URL (padrão é dark se não enviado)
$theme = $_GET['theme'] ?? 'dark';

if ($theme === 'light') {
    $color = '#2e4832';      // Cor de fundo no modo claro
    $labelColor = '#f5f5f5'; // Cor da label no modo claro
} else {
    $color = '#3e5c43';      // Cor de fundo no modo escuro (um pouco mais clara para contraste)
    $labelColor = '#1a1a1a'; // Cor da label no modo escuro
}

echo json_encode([
    'schemaVersion' => 1,
    'label' => 'ARKs',
    'message' => number_format($totalArks, 0, ',', '.'),
    'color' => $color,
    'labelColor' => $labelColor
]);
