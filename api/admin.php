<?php
date_default_timezone_set('America/Sao_Paulo');

// --- PATHS ---
$basePath = dirname(__DIR__); // um nível acima de /api
$cfgPath = $basePath . '/pix_config.json';
$pixLogPath = $basePath . '/pix_log.json';
$searchLogPath = $basePath . '/search_log.json';
$clickStatsPath = $basePath . '/click_stats.json';

$msg = isset($_GET['msg']) ? (string)$_GET['msg'] : '';

// --- FUNCTIONS ---
function load_json_file($path, $default = array()) {
    if (!file_exists($path)) return $default;
    $data = json_decode(file_get_contents($path), true);
    return is_array($data) ? $data : $default;
}

function save_json_file($path, $data) {
    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function parse_ua($ua) {
    $device = 'Desktop';
    $icon = '💻';
    if (preg_match('/(android|iphone|ipad|mobile)/i', $ua)) { $device='Celular'; $icon='📱'; }
    elseif (preg_match('/tablet/i', $ua)) { $device='Tablet'; $icon='📱'; }

    $browser = 'Desconhecido';
    if (preg_match('/chrome/i', $ua) && !preg_match('/edge/i', $ua)) $browser='Chrome';
    elseif (preg_match('/firefox/i', $ua)) $browser='Firefox';
    elseif (preg_match('/safari/i', $ua) && !preg_match('/chrome/i', $ua)) $browser='Safari';
    elseif (preg_match('/edge/i', $ua)) $browser='Edge';
    elseif (preg_match('/opera|opr/i', $ua)) $browser='Opera';
    elseif (preg_match('/msie|trident/i', $ua)) $browser='IE';

    return array('type'=>$device,'browser'=>$browser,'icon'=>$icon);
}

function time_elapsed_string($datetime) {
    $now = new DateTime();
    $ago = new DateTime($datetime);
    $diff = $now->diff($ago);
    $diff->w = floor($diff->d/7);
    $diff->d -= $diff->w*7;

    $string = array(
        'y'=>'ano','m'=>'mês','w'=>'semana','d'=>'dia',
        'h'=>'hora','i'=>'minuto','s'=>'segundo'
    );

    foreach($string as $k => &$v){
        if (!$diff->$k) unset($string[$k]);
        else $v = $diff->$k.' '.$v.($diff->$k>1?'s':'');
    }

    $string = array_slice($string,0,1);
    return $string ? implode(', ',$string).' atrás' : 'agora mesmo';
}

// --- POST ACTIONS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!empty($_POST['reset_stats'])) {
        save_json_file($pixLogPath, array());
        save_json_file($searchLogPath, array());
        save_json_file($clickStatsPath, array('consultar_clicks'=>0,'enter_clicks'=>0));
        header('Location: admin.php?msg=' . urlencode('Todos os logs e estatísticas foram limpos.'));
        exit;
    } elseif (!empty($_POST['pixKey'])) {
        $pixKey = trim($_POST['pixKey']);
        if ($pixKey !== '') {
            save_json_file($cfgPath, array('pixKey'=>$pixKey));
            header('Location: admin.php?msg=' . urlencode('Chave PIX atualizada com sucesso.'));
            exit;
        } else {
            header('Location: admin.php?msg=' . urlencode('Chave PIX inválida.'));
            exit;
        }
    }
}

// --- LOAD DATA ---
$cfgData = load_json_file($cfgPath, array('pixKey'=>'06721661195'));
$currentKey = isset($cfgData['pixKey']) ? $cfgData['pixKey'] : '06721661195';

$pixEntries = load_json_file($pixLogPath, array());
$searchEntries = load_json_file($searchLogPath, array());
$clickStatsData = load_json_file($clickStatsPath, array());
$clickStats = array_merge(array('consultar_clicks'=>0,'enter_clicks'=>0), $clickStatsData);

// Sort by date desc
usort($pixEntries, function($a,$b){ return strtotime(isset($b['ts'])?$b['ts']:0) - strtotime(isset($a['ts'])?$a['ts']:0); });
usort($searchEntries, function($a,$b){ return strtotime(isset($b['ts'])?$b['ts']:0) - strtotime(isset($a['ts'])?$a['ts']:0); });

// --- STATS ---
$totalPixValue=0;
foreach($pixEntries as $p) $totalPixValue += isset($p['valor']) ? floatval($p['valor']) : 0;

$uniqueIps = array();
foreach(array_merge($pixEntries,$searchEntries) as $entry){
    if (isset($entry['ip'])) $uniqueIps[$entry['ip']] = true;
}
$totalUniqueVisitors = count($uniqueIps);
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<title>Admin Dashboard</title>
<style>
body{font-family:sans-serif;background:#f3f4f6;}
.card{background:#fff;padding:1em;margin:1em;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,0.1);}
</style>
</head>
<body>
<h1>Admin Dashboard</h1>

<?php if($msg): ?>
<div style="padding:1em;margin:1em;background:#def0d8;border:1px solid #5cb85c;">
<?php echo htmlspecialchars($msg); ?>
</div>
<?php endif; ?>

<div class="card">
<h2>Chave PIX Atual</h2>
<form method="post">
<input type="text" name="pixKey" value="<?php echo htmlspecialchars($currentKey); ?>">
<button type="submit">Salvar</button>
</form>
</div>

<div class="card">
<h2>Estatísticas</h2>
<p>Total PIX: R$ <?php echo number_format($totalPixValue,2,',','.'); ?></p>
<p>Total Entradas: <?php echo $clickStats['enter_clicks']; ?></p>
<p>Buscas: <?php echo count($searchEntries); ?></p>
<p>Visitantes únicos: <?php echo $totalUniqueVisitors; ?></p>
</div>

<div class="card">
<h2>PIX Recentes</h2>
<?php if(empty($pixEntries)) echo "<p>Nenhum registro</p>"; else: ?>
<ul>
<?php foreach(array_slice($pixEntries,0,10) as $p): 
$ua = parse_ua(isset($p['ua'])?$p['ua']:''); ?>
<li><?php echo isset($p['placa'])?$p['placa']:'N/A'; ?> - <?php echo isset($p['valor_brl'])?$p['valor_brl']:'R$0,00'; ?> - <?php echo $ua['icon'].' '.$ua['type']; ?></li>
<?php endforeach; ?>
</ul>
<?php endif; ?>
</div>

<div class="card">
<h2>Buscar Histórico</h2>
<?php if(empty($searchEntries)) echo "<p>Nenhuma busca</p>"; else: ?>
<ul>
<?php foreach(array_slice($searchEntries,0,10) as $s): 
$ua = parse_ua(isset($s['ua'])?$s['ua']:''); ?>
<li><?php echo isset($s['plate'])?$s['plate']:'-'; ?> - <?php echo $ua['icon'].' '.$ua['type']; ?></li>
<?php endforeach; ?>
</ul>
<?php endif; ?>
</div>

<div class="card">
<form method="post" onsubmit="return confirm('Tem certeza?');">
<input type="hidden" name="reset_stats" value="1">
<button type="submit">Limpar Todos os Dados</button>
</form>
</div>

</body>
</html>
