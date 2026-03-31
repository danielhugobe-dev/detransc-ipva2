<?php
date_default_timezone_set('America/Sao_Paulo');

// --- Caminhos dos arquivos ---
$basePath = dirname(__DIR__); // pasta raiz
$cfgPath = $basePath.'/pix_config.json';
$pixLogPath = $basePath.'/pix_log.json';
$searchLogPath = $basePath.'/search_log.json';
$clickStatsPath = $basePath.'/click_stats.json';

// --- Mensagem ---
$msg = isset($_GET['msg']) ? (string)$_GET['msg'] : '';

// --- Funções utilitárias ---
function load_json($path, $default) {
    if (!file_exists($path)) return $default;
    $data = json_decode(file_get_contents($path), true);
    if (!is_array($data)) return $default;
    return $data;
}

function save_json($path, $data) {
    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function parse_ua($ua) {
    $device='Desktop'; $icon='💻';
    if (preg_match('/(android|iphone|ipad|mobile)/i', $ua)) { $device='Celular'; $icon='📱'; }
    elseif (preg_match('/tablet/i', $ua)) { $device='Tablet'; $icon='📱'; }

    $browser='Desconhecido';
    if (preg_match('/chrome/i', $ua) && !preg_match('/edge/i', $ua)) $browser='Chrome';
    elseif (preg_match('/firefox/i', $ua)) $browser='Firefox';
    elseif (preg_match('/safari/i', $ua) && !preg_match('/chrome/i', $ua)) $browser='Safari';
    elseif (preg_match('/edge/i', $ua)) $browser='Edge';
    elseif (preg_match('/opera|opr/i', $ua)) $browser='Opera';
    elseif (preg_match('/msie|trident/i', $ua)) $browser='IE';

    return array('type'=>$device,'browser'=>$browser,'icon'=>$icon);
}

// --- Ações POST ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['reset_stats']) && $_POST['reset_stats']=='1') {
        save_json($pixLogPath, array());
        save_json($searchLogPath, array());
        save_json($clickStatsPath, array('consultar_clicks'=>0,'enter_clicks'=>0));
        header('Location: admin.php?msg='.urlencode('Todos os logs e estatísticas foram limpos.'));
        exit;
    } elseif (!empty($_POST['pixKey'])) {
        $pixKey = trim($_POST['pixKey']);
        if ($pixKey != '') {
            save_json($cfgPath, array('pixKey'=>$pixKey));
            header('Location: admin.php?msg='.urlencode('Chave PIX atualizada com sucesso.'));
            exit;
        } else {
            header('Location: admin.php?msg='.urlencode('Chave PIX inválida.'));
            exit;
        }
    }
}

// --- Carregar dados ---
$cfg = load_json($cfgPath, array('pixKey'=>'06721661195'));
$currentKey = isset($cfg['pixKey']) ? $cfg['pixKey'] : '06721661195';

$pixEntries = load_json($pixLogPath, array());
$searchEntries = load_json($searchLogPath, array());
$clickStats = load_json($clickStatsPath, array('consultar_clicks'=>0,'enter_clicks'=>0));

// Ordenar por timestamp descendente
usort($pixEntries, function($a,$b){ return strtotime(isset($b['ts'])?$b['ts']:'0') - strtotime(isset($a['ts'])?$a['ts']:'0'); });
usort($searchEntries, function($a,$b){ return strtotime(isset($b['ts'])?$b['ts']:'0') - strtotime(isset($a['ts'])?$a['ts']:'0'); });

// --- Estatísticas ---
$totalPix = 0; 
foreach($pixEntries as $p) $totalPix += isset($p['valor']) ? floatval($p['valor']) : 0;

$uniqueIps = array();
foreach(array_merge($pixEntries,$searchEntries) as $e){ 
    if(isset($e['ip'])) $uniqueIps[$e['ip']]=true; 
}
$totalVisitors = count($uniqueIps);
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Dashboard</title>
<style>
body { font-family: Arial, sans-serif; background: #f3f4f6; margin: 0; padding: 20px; }
.card { background: #fff; padding: 15px; margin-bottom: 20px; border-radius: 6px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
</style>
</head>
<body>

<h1>Admin Dashboard</h1>

<?php
if (!empty($msg)) {
    echo '<div class="card" style="background:#d4edda;color:#155724;">'.htmlspecialchars($msg).'</div>';
}
?>

<div class="card">
<h2>Chave PIX</h2>
<form method="post">
<input type="text" name="pixKey" value="<?php echo htmlspecialchars($currentKey); ?>" style="width:100%;padding:5px;">
<button type="submit" style="margin-top:5px;">Salvar</button>
</form>
</div>

<div class="card">
<h2>Estatísticas</h2>
<ul>
<li>Total PIX: R$ <?php echo number_format($totalPix,2,',','.'); ?></li>
<li>Entradas: <?php echo isset($clickStats['enter_clicks']) ? $clickStats['enter_clicks'] : 0; ?></li>
<li>Buscas: <?php echo count($searchEntries); ?></li>
<li>Visitantes únicos: <?php echo $totalVisitors; ?></li>
</ul>
</div>

<div class="card">
<h2>PIX Recentes</h2>
<?php
if (empty($pixEntries)) {
    echo "<p>Nenhum registro</p>";
} else {
    echo "<ul>";
    foreach(array_slice($pixEntries,0,10) as $p) {
        $ua=parse_ua(isset($p['ua'])?$p['ua']:'');
        echo "<li>".(isset($p['placa'])?$p['placa']:'N/A')." - ".(isset($p['valor_brl'])?$p['valor_brl']:'R$0,00')." - ".$ua['icon']." ".$ua['type']."</li>";
    }
    echo "</ul>";
}
?>
</div>

<div class="card">
<h2>Histórico de Buscas</h2>
<?php
if (empty($searchEntries)) {
    echo "<p>Nenhuma busca</p>";
} else {
    echo "<ul>";
    foreach(array_slice($searchEntries,0,10) as $s) {
        $ua=parse_ua(isset($s['ua'])?$s['ua']:'');
        echo "<li>".(isset($s['plate'])?$s['plate']:'-')." - ".$ua['icon']." ".$ua['type']."</li>";
    }
    echo "</ul>";
}
?>
</div>

<div class="card">
<form method="post" onsubmit="return confirm('Tem certeza que deseja apagar todos os dados?');">
<input type="hidden" name="reset_stats" value="1">
<button type="submit" style="background:#f8d7da;color:#721c24;padding:5px;">Limpar Todos os Dados</button>
</form>
</div>

</body>
</html>
