<?php
/**
 * ARK Plugin Statistics
 * URL: https://revistacarnaubais.com.br/ark-telemetry/stats.php
 * 
 * @package ARKTelemetry
 * @version 3.1.0.0
 */

require_once __DIR__ . '/bootstrap.php';

// Cache HTTP: 12 hours to reduce server load
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
        'journals_over_time' => 'Crescimento de Revistas',
        'arks_over_time' => 'Crescimento de ARKs',
        'footer_text' => 'Estatísticas atualizadas automaticamente uma vez por semana.',
        'plugin_link' => 'ARK Plugin',
        'day' => 'd',
        'hour' => 'h',
        'min' => 'min',
        'tooltip_text' => 'Tempo restante para próxima atualização',
        'no_data' => 'Aguardando dados...'
    ],
    'es' => [
        'title' => 'ARK Plugin Stats',
        'subtitle' => 'Identificadores persistentes para OJS',
        'total_arks' => 'Total de ARKs generados',
        'active_journals' => 'Revistas activas',
        'last_update' => 'Última actualización',
        'next_update' => 'Próxima actualización',
        'journals_over_time' => 'Crecimiento de Revistas',
        'arks_over_time' => 'Crecimiento de ARKs',
        'footer_text' => 'Estadísticas actualizadas automáticamente una vez por semana.',
        'plugin_link' => 'Plugin ARK',
        'day' => 'd',
        'hour' => 'h',
        'min' => 'min',
        'tooltip_text' => 'Tiempo restante para la próxima actualización',
        'no_data' => 'Esperando datos...'
    ],
    'en' => [
        'title' => 'ARK Plugin Stats',
        'subtitle' => 'Persistent identifiers for OJS',
        'total_arks' => 'Total ARKs generated',
        'active_journals' => 'Active journals',
        'last_update' => 'Last update',
        'next_update' => 'Next update',
        'journals_over_time' => 'Journal Growth',
        'arks_over_time' => 'ARK Growth',
        'footer_text' => 'Statistics are automatically updated once per week.',
        'plugin_link' => 'ARK Plugin',
        'day' => 'd',
        'hour' => 'h',
        'min' => 'min',
        'tooltip_text' => 'Time remaining until next update',
        'no_data' => 'Waiting for data...'
    ]
];

$t = $translations[$lang];

// Cache file
$cacheFile = __DIR__ . '/stats_cache.json';
$weekInSeconds = 7 * 24 * 3600;

function generateCache($pdo) {
    try {
        // Total ARKs
        $stmtTotal = $pdo->query("SELECT SUM(arks_count) as total_global FROM ark_statistics");
        $resTotal = $stmtTotal->fetch();
        $totalGlobal = $resTotal['total_global'] ?? 0;
        
        // Total unique journals
        $stmtJournals = $pdo->query("SELECT COUNT(DISTINCT naan) as total_revistas FROM ark_statistics");
        $resJournals = $stmtJournals->fetch();
        $totalRevistas = $resJournals['total_revistas'] ?? 0;
        
        // ===== HISTORICAL DATA FOR CHARTS =====
        // Group by month for the last 12 months
        $stmtHistory = $pdo->query("
            SELECT 
                DATE_FORMAT(received_at, '%Y-%m') as month,
                COUNT(DISTINCT naan) as journals,
                SUM(arks_count) as arks
            FROM ark_statistics
            WHERE received_at > DATE_SUB(NOW(), INTERVAL 12 MONTH)
            GROUP BY DATE_FORMAT(received_at, '%Y-%m')
            ORDER BY month ASC
        ");
        $history = $stmtHistory->fetchAll();
        
        // Prepare data for charts
        $months = [];
        $journalsHistory = [];
        $arksHistory = [];
        
        $cumulativeJournals = 0;
        $cumulativeArks = 0;
        
        foreach ($history as $row) {
            $months[] = $row['month'];
            $cumulativeJournals += (int)$row['journals'];
            $cumulativeArks += (int)$row['arks'];
            $journalsHistory[] = $cumulativeJournals;
            $arksHistory[] = $cumulativeArks;
        }
        
        return [
            'generated_at' => time(),
            'total_arks' => $totalGlobal,
            'total_journals' => $totalRevistas,
            'months' => $months,
            'journals_history' => $journalsHistory,
            'arks_history' => $arksHistory
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

// Prepare chart data
$monthsJson = isset($stats['months']) ? json_encode($stats['months']) : '[]';
$journalsHistoryJson = isset($stats['journals_history']) ? json_encode($stats['journals_history']) : '[]';
$arksHistoryJson = isset($stats['arks_history']) ? json_encode($stats['arks_history']) : '[]';

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
    <link rel="stylesheet" href="css/stats.css?v=1.002">
    <style>.progress-bar-fill{height:100%;background-color:var(--progress-bar-fill);border-radius:4px;transition:width 0.3s ease;width:<?php echo $progressPercent;?>%;}</style>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
            
            <div class="update-info">
                <span class="info-text"><?php echo htmlspecialchars($t['last_update']); ?>: <?php echo isset($stats['generated_at']) ? date('d/m/Y H:i:s', $stats['generated_at']) : 'Never'; ?></span>
                <div class="progress-bar-container tooltip" id="progressTooltip">
                    <div class="progress-bar-bg" id="progressBar">
                        <div class="progress-bar-fill" id="progressFill" style="width: <?php echo $progressPercent; ?>%;"></div>
                    </div>
                    <div class="progress-label">
                        <span><?php echo htmlspecialchars($t['next_update']); ?></span>
                    </div>
                    <div class="tooltip-text" id="tooltipText">
                        <?php echo htmlspecialchars($t['tooltip_text']); ?>: Carregando...
                    </div>
                </div>
            </div>
            
            <div class="charts-container">
                <div class="chart-box">
                    <h3><?php echo htmlspecialchars($t['journals_over_time']); ?></h3>
                    <canvas id="journalsChart"></canvas>
                </div>
                <div class="chart-box">
                    <h3><?php echo htmlspecialchars($t['arks_over_time']); ?></h3>
                    <canvas id="arksChart"></canvas>
                </div>
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
        
        // Progress bar and countdown
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
        
        // ===== CHARTS =====
        document.addEventListener('DOMContentLoaded', function() {
            const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
            const textColor = isDark ? '#e0e0e0' : '#2c3e50';
            const gridColor = isDark ? '#3d4a5c' : '#e9ecef';
            
            const months = <?php echo $monthsJson; ?>;
            const journalsData = <?php echo $journalsHistoryJson; ?>;
            const arksData = <?php echo $arksHistoryJson; ?>;
            
            // Journal Growth Chart
            const ctx1 = document.getElementById('journalsChart').getContext('2d');
            new Chart(ctx1, {
                type: 'line',
                data: {
                    labels: months,
                    datasets: [{
                        label: '<?php echo htmlspecialchars($t['active_journals']); ?>',
                        data: journalsData,
                        borderColor: '#2e4832',
                        backgroundColor: 'rgba(46, 72, 50, 0.1)',
                        fill: true,
                        tension: 0.4,
                        pointRadius: 4,
                        pointBackgroundColor: '#2e4832'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    plugins: {
                        legend: {
                            display: false
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                color: textColor,
                                stepSize: 1
                            },
                            grid: {
                                color: gridColor
                            }
                        },
                        x: {
                            ticks: {
                                color: textColor,
                                maxTicksLimit: 12
                            },
                            grid: {
                                color: gridColor
                            }
                        }
                    }
                }
            });
            
            // ARK Growth Chart
            const ctx2 = document.getElementById('arksChart').getContext('2d');
            new Chart(ctx2, {
                type: 'line',
                data: {
                    labels: months,
                    datasets: [{
                        label: '<?php echo htmlspecialchars($t['total_arks']); ?>',
                        data: arksData,
                        borderColor: '#d00a6c',
                        backgroundColor: 'rgba(208, 10, 108, 0.1)',
                        fill: true,
                        tension: 0.4,
                        pointRadius: 4,
                        pointBackgroundColor: '#d00a6c'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    plugins: {
                        legend: {
                            display: false
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                color: textColor,
                                stepSize: 1
                            },
                            grid: {
                                color: gridColor
                            }
                        },
                        x: {
                            ticks: {
                                color: textColor,
                                maxTicksLimit: 12
                            },
                            grid: {
                                color: gridColor
                            }
                        }
                    }
                }
            });
        });
    </script>
</body>
</html>