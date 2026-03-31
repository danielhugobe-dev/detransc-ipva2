<?php
date_default_timezone_set('America/Sao_Paulo');

// --- PATHS ---
$basePath = dirname(__DIR__); // pasta raiz, um nível acima de api/
$cfgPath = $basePath . '/pix_config.json';
$pixLogPath = $basePath . '/pix_log.json';
$searchLogPath = $basePath . '/search_log.json';
$clickStatsPath = $basePath . '/click_stats.json';

$msg = isset($_GET['msg']) ? (string)$_GET['msg'] : '';

// --- ACTIONS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['reset_stats']) && $_POST['reset_stats'] === '1') {
        @file_put_contents($pixLogPath, json_encode([], JSON_PRETTY_PRINT));
        @file_put_contents($searchLogPath, json_encode([], JSON_PRETTY_PRINT));
        @file_put_contents($clickStatsPath, json_encode(['consultar_clicks'=>0,'enter_clicks'=>0], JSON_PRETTY_PRINT));
        header('Location: admin.php?msg=' . urlencode('Todos os logs e estatísticas foram limpos.'));
        exit;
    } elseif (isset($_POST['pixKey'])) {
        $pixKey = trim((string)$_POST['pixKey']);
        if ($pixKey !== '') {
            $cfg = ['pixKey' => $pixKey];
            @file_put_contents($cfgPath, json_encode($cfg, JSON_PRETTY_PRINT));
            header('Location: admin.php?msg=' . urlencode('Chave PIX atualizada com sucesso.'));
            exit;
        } else {
            header('Location: admin.php?msg=' . urlencode('Chave PIX inválida.'));
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
    $pixEntries = json_decode(@file_get_contents($pixLogPath), true);
    if (!is_array($pixEntries)) $pixEntries = [];
    usort($pixEntries, function($a, $b) {
        return strtotime($b['ts'] ?? 0) - strtotime($a['ts'] ?? 0);
    });
}

$searchEntries = [];
if (file_exists($searchLogPath)) {
    $searchEntries = json_decode(@file_get_contents($searchLogPath), true);
    if (!is_array($searchEntries)) $searchEntries = [];
    usort($searchEntries, function($a, $b) {
        return strtotime($b['ts'] ?? 0) - strtotime($a['ts'] ?? 0);
    });
}

$clickStats = ['consultar_clicks' => 0, 'enter_clicks' => 0];
if (file_exists($clickStatsPath)) {
    $tmp = json_decode(@file_get_contents($clickStatsPath), true);
    if (is_array($tmp)) $clickStats = array_merge($clickStats, $tmp);
}

// --- HELPER FUNCTIONS ---
function parse_ua($ua) {
    $device = 'Desktop';
    $icon = '💻';
    
    if (preg_match('/(android|iphone|ipad|mobile)/i', $ua)) {
        $device = 'Celular';
        $icon = '📱';
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
foreach ($pixEntries as $p) $totalPixValue += floatval($p['valor'] ?? 0);

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
        .card { background: white; border-radius: 0.75rem; box-shadow: 0 1px 3px 0 rgba(0,0,0,0.1),0 1px 2px 0 rgba(0,0,0,0.06); }
        .table-row-hover:hover { background-color: #f9fafb; }
        .status-badge { padding: 0.25rem 0.625rem; border-radius: 9999px; font-size: 0.75rem; font-weight: 500; }
        .badge-success { background-color: #d1fae5; color: #065f46; }
        .badge-blue { background-color: #dbeafe; color: #1e40af; }
    </style>
</head>
<body class="text-gray-800">
<!-- resto do HTML permanece igual -->
