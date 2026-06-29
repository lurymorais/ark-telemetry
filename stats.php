<?php
/**
 * ARK Plugin Statistics
 * URL: https://revistacarnaubais.com.br/ark-telemetry/stats.php
 */

require_once __DIR__ . '/bootstrap.php';

// Cache HTTP: 12 horas para reduzir carga no servidor
$httpCacheSeconds = 12 * 3600;
header('Cache-Control: public, max-age=' . $httpCacheSeconds);
header('Expires: ' . gmdate('D, d M Y H:i:s', time() + $httpCacheSeconds) . ' GMT');
global $ark_pdo;

// Supported languages with circular SVG flags
$languages = [
    'pt_BR' => [
        'name' => 'Português', 
        'flag' => '🇧🇷', 
        'flag_svg' => 'https://hatscripts.github.io/circle-flags/flags/br.svg',
        'code' => 'pt_BR'
    ],
    'es' => [
        'name' => 'Español', 
        'flag' => '🇪🇸', 
        'flag_svg' => 'https://hatscripts.github.io/circle-flags/flags/es.svg',
        'code' => 'es'
    ],
    'en' => [
        'name' => 'English', 
        'flag' => '🇺🇸', 
        'flag_svg' => 'https://hatscripts.github.io/circle-flags/flags/us.svg',
        'code' => 'en'
    ]
];

// Get selected language from cookie or query parameter
$lang = isset($_GET['lang']) ? $_GET['lang'] : (isset($_COOKIE['ark_stats_lang']) ? $_COOKIE['ark_stats_lang'] : 'pt_BR');
if (!isset($languages[$lang])) {
    $lang = 'pt_BR';
}
setcookie('ark_stats_lang', $lang, time() + 365 * 24 * 3600, '/');

// Translations
$translations = [
    'pt_BR' => [
        'title' => 'ARK Plugin Stats',
        'subtitle' => 'Identificadores persistentes para OJS',
        'total_arks' => 'Total de ARKs gerados',
        'active_journals' => 'Revistas ativas',
        'last_update' => 'Última atualização',
        'next_update' => 'Próxima atualização',
        'journals_list' => 'Listagem de revistas',
        'journal' => 'Revista',
        'country' => 'País',
        'arks_generated' => 'ARKs gerados',
        'no_data' => 'Nenhuma revista em modo público ainda.',
        'wait_message' => 'As primeiras revistas aparecerão aqui quando ativarem o modo completo.',
        'footer_text' => 'Estatísticas atualizadas automaticamente uma vez por semana.',
        'plugin_link' => 'ARK Plugin',
        'day' => 'd',
        'hour' => 'h',
        'min' => 'min',
        'anonymous_publication' => 'revistas anônimas publicaram',
        'tooltip_text' => 'Tempo restante para próxima atualização'
    ],
    'es' => [
        'title' => 'ARK Plugin Stats',
        'subtitle' => 'Identificadores persistentes para OJS',
        'total_arks' => 'Total de ARKs generados',
        'active_journals' => 'Revistas activas',
        'last_update' => 'Última actualización',
        'next_update' => 'Próxima actualización',
        'journals_list' => 'Listado de revistas',
        'journal' => 'Revista',
        'country' => 'País',
        'arks_generated' => 'ARKs generados',
        'no_data' => 'Ninguna revista en modo público todavía.',
        'wait_message' => 'Las primeras revistas aparecerán aquí cuando activen el modo completo.',
        'footer_text' => 'Estadísticas actualizadas automáticamente una vez por semana.',
        'plugin_link' => 'Plugin ARK',
        'day' => 'd',
        'hour' => 'h',
        'min' => 'min',
        'anonymous_publication' => 'revistas anónimas publicaron',
        'tooltip_text' => 'Tiempo restante para la próxima actualización'
    ],
    'en' => [
        'title' => 'ARK Plugin Stats',
        'subtitle' => 'Persistent identifiers for OJS',
        'total_arks' => 'Total ARKs generated',
        'active_journals' => 'Active journals',
        'last_update' => 'Last update',
        'next_update' => 'Next update',
        'journals_list' => 'Journals list',
        'journal' => 'Journal',
        'country' => 'Country',
        'arks_generated' => 'ARKs generated',
        'no_data' => 'No journals in public mode yet.',
        'wait_message' => 'The first journals will appear here when they enable complete mode.',
        'footer_text' => 'Statistics are automatically updated once per week.',
        'plugin_link' => 'ARK Plugin',
        'day' => 'd',
        'hour' => 'h',
        'min' => 'min',
        'anonymous_publication' => 'anonymous journals published',
        'tooltip_text' => 'Time remaining until next update'
    ]
];

$t = $translations[$lang];

// Cache file
$cacheFile = __DIR__ . '/stats_cache.json';
$weekInSeconds = 7 * 24 * 3600;

function generateCache($pdo) {
    try {
        // ALTERADO: Agora usa tabela ark_journals
        $stmtTotal = $pdo->query("SELECT SUM(arks_count) as total_global, COUNT(*) as total_revistas FROM ark_journals WHERE status = 'active'");
        $resTotal = $stmtTotal->fetch();
        
        $totalGlobal = $resTotal['total_global'] ?? 0;
        $totalRevistas = $resTotal['total_revistas'] ?? 0;
        
        $stmtRestricted = $pdo->query("SELECT SUM(arks_count) as total FROM ark_journals WHERE telemetry_level = 'restricted' AND status = 'active'");
        $resRestricted = $stmtRestricted->fetch();
        $totalAnonimo = $resRestricted['total'] ?? 0;
        
        // ALTERADO: Busca apenas revistas públicas
        $stmtPublic = $pdo->query("
            SELECT journal_name, journal_url, country, arks_count, updated_at 
            FROM ark_journals 
            WHERE telemetry_level = 'public' 
            AND status = 'active'
            AND journal_name IS NOT NULL 
            AND journal_name != ''
            ORDER BY arks_count DESC
        ");
        $revistasPublicas = $stmtPublic->fetchAll();
        
        $stmtAnonCount = $pdo->query("SELECT COUNT(*) as total FROM ark_journals WHERE telemetry_level = 'restricted' AND status = 'active'");
        $resAnonCount = $stmtAnonCount->fetch();
        $totalAnonJournals = $resAnonCount['total'] ?? 0;
        
        return [
            'generated_at' => time(),
            'total_arks' => $totalGlobal,
            'total_journals' => $totalRevistas,
            'total_anonimo' => $totalAnonimo,
            'total_anon_journals' => $totalAnonJournals,
            'public_journals' => $revistasPublicas
        ];
    } catch (Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

// Check if cache exists and is valid
$cacheValid = false;
$stats = [];

if (file_exists($cacheFile)) {
    $cache = json_decode(file_get_contents($cacheFile), true);
    if ($cache && isset($cache['generated_at'])) {
        $age = time() - $cache['generated_at'];
        if ($age < $weekInSeconds) {
            $cacheValid = true;
            $stats = $cache;
        }
    }
}

// If cache is invalid, generate new data
if (!$cacheValid) {
    $stats = generateCache($ark_pdo);
    if (!isset($stats['error'])) {
        file_put_contents($cacheFile, json_encode($stats));
    }
}

$nextTimestamp = isset($stats['generated_at']) ? $stats['generated_at'] + $weekInSeconds : time() + $weekInSeconds;
$remaining = $nextTimestamp - time();
$remainingDays = floor($remaining / 86400);
$remainingHours = floor(($remaining % 86400) / 3600);
$remainingMinutes = floor(($remaining % 3600) / 60);

$totalSeconds = $weekInSeconds;
$elapsedSeconds = time() - ($stats['generated_at'] ?? time());
$progressPercent = ($elapsedSeconds / $totalSeconds) * 100;
if ($progressPercent > 100) $progressPercent = 100;
if ($progressPercent < 0) $progressPercent = 0;

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="<?php echo $lang; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo 'ARK Plugin Stats'; ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        :root {
            --bg-primary: #f5f7fa;
            --bg-secondary: #ffffff;
            --text-primary: #2c3e50;
            --text-secondary: #6c757d;
            --border-color: #e9ecef;
            --card-shadow: 0 4px 12px rgba(0,0,0,0.08);
            --accent-color: #2e4832;
            --accent-hover: #1f3322;
            --timer-bg: #e8f4e8;
            --timer-color: #2e4832;
            --progress-bar-bg: #e0e0e0;
            --progress-bar-fill: #2e4832;
            --sidebar-bg: rgba(255,255,255,0.95);
            --success-bg: #d4edda;
            --success-text: #155724;
            --success-border: #c3e6cb;
        }
        
        [data-theme="dark"] {
            --bg-primary: #1a1a2e;
            --bg-secondary: #16213e;
            --text-primary: #e0e0e0;
            --text-secondary: #a0a0a0;
            --border-color: #2c3e50;
            --card-shadow: 0 4px 12px rgba(0,0,0,0.3);
            --accent-color: #4a7c59;
            --accent-hover: #3d6649;
            --timer-bg: #0f2a1a;
            --timer-color: #4a7c59;
            --progress-bar-bg: #2c3e50;
            --progress-bar-fill: #4a7c59;
            --sidebar-bg: rgba(22,33,62,0.95);
            --success-bg: #1e3a2e;
            --success-text: #81c784;
            --success-border: #2e7d32;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: var(--bg-primary);
            color: var(--text-primary);
            min-height: 100vh;
            transition: background 0.3s ease, color 0.3s ease;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px 40px;
            position: relative;
        }
        
        .main-content {
            max-width: 1000px;
            margin: 0 auto;
            padding-right: 0;
            transition: all 0.3s ease;
        }
        
        .header {
            text-align: center;
            margin-bottom: 48px;
            margin-top: 20px;
            padding: 0 20px;
        }
        
        .header h1 {
            font-size: 2.2rem;
            color: var(--text-primary);
            margin-bottom: 12px;
            letter-spacing: -0.5px;
        }
        
        .header p {
            color: var(--text-secondary);
            font-size: 1rem;
        }
        
        @keyframes fadeOut {
            0% { opacity: 1; }
            70% { opacity: 1; }
            100% { opacity: 0; visibility: hidden; }
        }
        
        .stats-grid {
            display: flex;
            gap: 20px;
            margin-bottom: 40px;
            justify-content: center;
            align-items: center;
        }
        
        .stat-card {
            background: var(--bg-secondary);
            border-radius: 16px;
            padding: 24px 20px;
            text-align: center;
            box-shadow: var(--card-shadow);
            transition: transform 0.2s;
        }
        
        .stat-card:hover {
            transform: translateY(-4px);
        }
        
        .stat-card .number {
            font-weight: bold;
            color: var(--accent-color);
            margin-bottom: 8px;
        }
        
        .stat-card .label {
            color: var(--text-secondary);
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .stat-card.principal {
            flex: 2;
            padding: 40px 20px;
            background: linear-gradient(135deg, var(--bg-secondary) 0%, var(--bg-primary) 100%);
            border: 2px solid var(--accent-color);
        }
        
        .stat-card.principal .number {
            font-size: 4rem;
        }
        
        .stat-card.principal .label {
            font-size: 0.85rem;
        }
        
        .stat-card.secondary {
            flex: 1;
        }
        
        .stat-card.secondary .number {
            font-size: 1.8rem;
        }
        
        .stat-card.secondary .label {
            font-size: 0.7rem;
        }
        
        .update-info {
            background: var(--bg-secondary);
            border-radius: 12px;
            padding: 16px 24px;
            margin-bottom: 32px;
            box-shadow: var(--card-shadow);
        }
        
        .update-info .info-text {
            font-size: 0.85rem;
            color: var(--text-secondary);
            margin-bottom: 12px;
            display: block;
        }
        
        .progress-bar-container {
            width: 100%;
            position: relative;
            cursor: pointer;
        }
        
        .progress-bar-bg {
            width: 100%;
            height: 8px;
            background-color: var(--progress-bar-bg);
            border-radius: 4px;
            overflow: hidden;
        }
        
        .progress-bar-fill {
            height: 100%;
            background-color: var(--progress-bar-fill);
            border-radius: 4px;
            transition: width 0.3s ease;
            width: <?php echo $progressPercent; ?>%;}
        
        .progress-label {
            margin-top: 8px;
            font-size: 0.75rem;
            color: var(--text-secondary);
            text-align: center;
        }
        
        .tooltip {
            position: relative;
            display: inline-block;
            width: 100%;
        }
        
        .tooltip .tooltip-text {
            visibility: hidden;
            background-color: var(--bg-secondary);
            color: var(--text-primary);
            text-align: center;
            padding: 8px 12px;
            border-radius: 8px;
            position: absolute;
            z-index: 1;
            bottom: 125%;
            left: 50%;
            transform: translateX(-50%);
            white-space: nowrap;
            font-size: 0.8rem;
            box-shadow: var(--card-shadow);
            border: 1px solid var(--border-color);
            opacity: 0;
            transition: opacity 0.3s;
            pointer-events: none;
        }
        
        .tooltip:hover .tooltip-text {
            visibility: visible;
            opacity: 1;
        }
        
        .table-container {
            background: var(--bg-secondary);
            border-radius: 16px;
            overflow: hidden;
            box-shadow: var(--card-shadow);
        }
        
        .table-container h2 {
            padding: 20px 24px;
            background: var(--bg-secondary);
            border-bottom: 1px solid var(--border-color);
            font-size: 1.2rem;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        th, td {
            padding: 12px 16px;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
        }
        
        th {
            background: var(--bg-primary);
            font-weight: 600;
            color: var(--text-primary);
        }
        
        tr:hover {
            background: var(--bg-primary);
        }
        
        .journal-link {
            text-decoration: none;
            color: var(--accent-color);
            font-weight: 500;
        }
        
        .journal-link:hover {
            text-decoration: underline;
        }
        
        .empty-state {
            text-align: center;
            padding: 48px;
            color: var(--text-secondary);
        }
        
        .footer {
            text-align: center;
            margin-top: 40px;
            color: var(--text-secondary);
            font-size: 0.7rem;
        }
        
        .subline {
            text-align: center;
            margin-top: -20px;
            margin-bottom: 30px;
            color: var(--text-secondary);
            font-size: 0.9rem;
        }
        
        .sidebar {
            position: fixed;
            right: 30px;
            top: 50%;
            transform: translateY(-50%);
            width: 100px;
            z-index: 1000;
            transition: all 0.3s ease;
        }
        
        .sidebar.collapsed {
            transform: translateY(-50%) translateX(calc(100% - 40px));
        }
        
        .sidebar.collapsed .lang-selector {
            opacity: 0.3;
        }
        
        .sidebar.collapsed:hover {
            transform: translateY(-50%) translateX(0);
        }
        
        .sidebar.collapsed:hover .lang-selector {
            opacity: 1;
        }
        
        .lang-selector {
            background: var(--sidebar-bg);
            backdrop-filter: blur(10px);
            border-radius: 60px;
            padding: 16px 12px;
            display: flex;
            flex-direction: column;
            gap: 16px;
            box-shadow: var(--card-shadow);
            border: 1px solid var(--border-color);
            transition: opacity 0.3s ease;
        }
        
        .lang-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 6px;
            padding: 10px 0;
            cursor: pointer;
            border-radius: 50px;
            transition: all 0.2s;
            text-decoration: none;
        }
        
        .lang-item:hover {
            background: var(--bg-primary);
            transform: scale(1.05);
        }
        
        .lang-item.active {
            background: var(--accent-color);
        }
        
        .lang-item.active .lang-flag img,
        .lang-item.active .lang-name {
            filter: brightness(1.1);
        }
        
        .lang-flag {
            display: flex;
            justify-content: center;
            align-items: center;
            width: 32px;
            height: 32px;
        }
        
        .lang-flag img {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            object-fit: cover;
            box-shadow: 0 1px 3px rgba(0,0,0,0.2);
        }
        
        .lang-name {
            font-size: 10px;
            color: var(--text-secondary);
            text-align: center;
        }
        
        .lang-item.active .lang-name {
            color: white;
        }
        
        .theme-toggle {
            margin-top: 16px;
            background: var(--sidebar-bg);
            backdrop-filter: blur(10px);
            border-radius: 50px;
            padding: 12px;
            text-align: center;
            cursor: pointer;
            box-shadow: var(--card-shadow);
            border: 1px solid var(--border-color);
            transition: all 0.2s;
        }
        
        .theme-toggle:hover {
            transform: scale(1.05);
            background: var(--bg-primary);
        }
        
        .theme-toggle span {
            font-size: 24px;
        }
        
        .success-message {
            background-color: var(--success-bg);
            color: var(--success-text);
            border: 1px solid var(--success-border);
            border-radius: 8px;
            padding: 12px 20px;
            margin-bottom: 20px;
            text-align: center;
            animation: fadeOut 3s forwards;
        }
        
        @media (max-width: 768px) {
            .container {
                padding: 20px;
            }
            
            .main-content {
                padding-right: 0;
            }
            
            .sidebar {
                right: 10px;
                width: 80px;
            }
            
            .sidebar.collapsed {
                transform: translateY(-50%) translateX(calc(100% - 30px));
            }
            
            .lang-flag {
                width: 28px;
                height: 28px;
            }
            
            .theme-toggle span {
                font-size: 18px;
            }
            
            .stats-grid {
                flex-direction: column;
            }
            
            .stat-card.principal {
                width: 100%;
            }
            
            .stat-card.secondary {
                width: 100%;
            }
            
            .stat-card.principal .number {
                font-size: 3rem;
            }
            
            .stat-card.secondary .number {
                font-size: 2rem;
            }
            
            th, td {
                padding: 8px 10px;
                font-size: 0.75rem;
            }
            
            .header h1 {
                font-size: 1.5rem;
            }
            
            .subline {
                font-size: 0.75rem;
            }
            
            .tooltip .tooltip-text {
                white-space: normal;
                width: 200px;
                font-size: 0.7rem;
            }
        }
        
        @media (max-width: 480px) {
            .sidebar {
                display: none;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="main-content">
                      
            <div class="header">
                <h1><?php echo 'ARK Plugin Stats'; ?></h1>
                <p><?php echo htmlspecialchars($t['subtitle']); ?></p>
            </div>
            
            <?php if (isset($stats['error'])): ?>
                <div class="stat-card" style="text-align: center; color: #e74c3c;">
                    <p>Error: <?php echo htmlspecialchars($stats['error']); ?></p>
                </div>
            <?php else: ?>
            
            <div class="stats-grid">
                <div class="stat-card secondary">
                    <div class="number"><?php echo number_format($stats['total_journals'] ?? 0, 0, ',', '.'); ?></div>
                    <div class="label"><?php echo htmlspecialchars($t['active_journals']); ?></div>
                </div>
                <div class="stat-card principal">
                    <div class="number"><?php echo number_format($stats['total_arks'] ?? 0, 0, ',', '.'); ?></div>
                    <div class="label"><?php echo htmlspecialchars($t['total_arks']); ?></div>
                </div>
            </div>
            
            <div class="subline">
                <strong><?php echo number_format($stats['total_anon_journals'] ?? 0, 0, ',', '.'); ?></strong> 
                <?php echo htmlspecialchars($t['anonymous_publication']); ?> 
                <strong><?php echo number_format($stats['total_anonimo'] ?? 0, 0, ',', '.'); ?></strong> ARKs
            </div>
            
            <div class="update-info">
                <span class="info-text"><?php echo htmlspecialchars($t['last_update']); ?>: <?php echo isset($stats['generated_at']) ? date('d/m/Y', $stats['generated_at']) : 'Never'; ?></span>
                <div class="progress-bar-container tooltip" id="progressTooltip">
                    <div class="progress-bar-bg" id="progressBar">
                        <div class="progress-bar-fill" id="progressFill"></div>
                    </div>
                    <div class="progress-label">
                        <span><?php echo htmlspecialchars($t['next_update']); ?></span>
                    </div>
                    <div class="tooltip-text" id="tooltipText">
                        <?php echo htmlspecialchars($t['tooltip_text']); ?>: Carregando...
                    </div>
                </div>
            </div>
            
            <div class="table-container">
                <h2><?php echo htmlspecialchars($t['journals_list']); ?></h2>
                <?php if (!empty($stats['public_journals'])): ?>
                <table>
                    <thead>
                        <tr>
                            <th><?php echo htmlspecialchars($t['journal']); ?></th>
                            <th><?php echo htmlspecialchars($t['country']); ?></th>
                            <th><?php echo htmlspecialchars($t['arks_generated']); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($stats['public_journals'] as $rev): ?>
                        <tr>
                            <td>
                                <?php if (!empty($rev['journal_url'])): ?>
                                    <a href="<?php echo htmlspecialchars($rev['journal_url']); ?>" target="_blank" class="journal-link">
                                        <?php echo htmlspecialchars($rev['journal_name']); ?>
                                    </a>
                                <?php else: ?>
                                    <?php echo htmlspecialchars($rev['journal_name']); ?>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($rev['country'] ?: '—'); ?></td>
                            <td><?php echo number_format($rev['arks_count'], 0, ',', '.'); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                <div class="empty-state">
                    <p><?php echo htmlspecialchars($t['no_data']); ?></p>
                    <p><?php echo htmlspecialchars($t['wait_message']); ?></p>
                </div>
                <?php endif; ?>
            </div>
            
            <div class="footer">
                <p><?php echo htmlspecialchars($t['footer_text']); ?></p>
                <p><a href="https://github.com/lurymorais/ark-plugin" target="_blank" style="color: var(--accent-color);"><?php echo htmlspecialchars($t['plugin_link']); ?></a></p>
            </div>
            
            <?php endif; ?>
        </div>
        
        <div class="sidebar" id="sidebar">
            <div class="lang-selector">
                <?php foreach ($languages as $code => $info): ?>
                <a href="?lang=<?php echo $code; ?>" class="lang-item <?php echo $lang === $code ? 'active' : ''; ?>" data-lang="<?php echo $code; ?>">
                <span class="lang-flag"><img src="<?php echo $info['flag_svg']; ?>" alt="<?php echo $info['name']; ?>" width="32" height="32"></span>
                <span class="lang-name"><?php echo $info['name']; ?></span>
                </a>
                <?php endforeach; ?>
                <div class="theme-toggle" id="themeToggle" title="Toggle dark/light mode">
                    <span>🌓</span>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        // Theme detection and persistence
        const themeToggle = document.getElementById('themeToggle');
        const htmlElement = document.documentElement;
        
        function setTheme(theme) {
            htmlElement.setAttribute('data-theme', theme);
            localStorage.setItem('ark_stats_theme', theme);
        }
        
        function getSystemTheme() {
            return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
        }
        
        const savedTheme = localStorage.getItem('ark_stats_theme');
        if (savedTheme) {
            setTheme(savedTheme);
        } else {
            setTheme(getSystemTheme());
        }
        
        themeToggle.addEventListener('click', () => {
            const currentTheme = htmlElement.getAttribute('data-theme');
            const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
            setTheme(newTheme);
        });
        
        window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', (e) => {
            if (!localStorage.getItem('ark_stats_theme')) {
                setTheme(e.matches ? 'dark' : 'light');
            }
        });
        
        // Auto-hide sidebar after 5 seconds of inactivity
        const sidebar = document.getElementById('sidebar');
        let hideTimeout;
        
        function resetHideTimer() {
            if (hideTimeout) clearTimeout(hideTimeout);
            sidebar.classList.remove('collapsed');
            hideTimeout = setTimeout(() => {
                sidebar.classList.add('collapsed');
            }, 5000);
        }
        
        sidebar.addEventListener('mouseenter', resetHideTimer);
        sidebar.addEventListener('mouseleave', () => {
            if (hideTimeout) clearTimeout(hideTimeout);
            hideTimeout = setTimeout(() => {
                sidebar.classList.add('collapsed');
            }, 5000);
        });
        
        document.addEventListener('mousemove', resetHideTimer);
        document.addEventListener('click', resetHideTimer);
        document.addEventListener('scroll', resetHideTimer);
        
        resetHideTimer();
        
        // Progress bar and countdown (auto-reload when cache expires)
        const progressFill = document.getElementById('progressFill');
        const tooltipText = document.getElementById('tooltipText');
        
        const nextTimestamp = <?php echo $nextTimestamp; ?> * 1000;
        const totalSeconds = <?php echo $weekInSeconds; ?>;
        const t = <?php echo json_encode($t); ?>;
        
        function formatTimeRemaining(distance) {
            const days = Math.floor(distance / (1000 * 60 * 60 * 24));
            const hours = Math.floor((distance % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
            const minutes = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));
            
            let text = '';
            if (days > 0) text += days + t.day + ' ';
            if (hours > 0 || days > 0) text += hours + t.hour + ' ';
            text += minutes + t.min;
            return text;
        }
        
        function updateProgressBar() {
            const now = new Date().getTime();
            const distance = nextTimestamp - now;
            
            if (distance < 0) {
                tooltipText.innerHTML = '<?php echo htmlspecialchars($t['tooltip_text']); ?>: Atualizando...';
                // AUTO-RELOAD ESSENCIAL: recarrega a página quando o cache expira
                setTimeout(function() { location.reload(); }, 5000);
                return;
            }
            
            const elapsed = totalSeconds - (distance / 1000);
            const percent = (elapsed / totalSeconds) * 100;
            if (progressFill) {
                progressFill.style.width = percent + '%';
            }
            const timeRemainingText = formatTimeRemaining(distance);
            tooltipText.innerHTML = '<?php echo htmlspecialchars($t['tooltip_text']); ?>: ' + timeRemainingText;
        }
        
        setInterval(updateProgressBar, 60000);
        updateProgressBar();
    </script>
</body>
</html>