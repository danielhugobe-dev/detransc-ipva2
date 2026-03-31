<?php
date_default_timezone_set('America/Sao_Paulo');

// --- Caminhos absolutos ---
$basePath = dirname(__DIR__); // raiz do projeto
$cfgPath = $basePath . '/pix_config.json';
$pixLogPath = $basePath . '/pix_log.json';
$searchLogPath = $basePath . '/search_log.json';
$clickStatsPath = $basePath . '/click_stats.json';

$msg = isset($_GET['msg']) ? (string)$_GET['msg'] : '';

// --- Funções utilitárias ---
function load_json($path, $default) {
    if (!file_exists($path)) return $default;
    $data = json_decode(file_get_contents($path), true);
    return is_array($data) ? $data : $default;
}

function save_json($path, $data) {
    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function parse_ua($ua) {
    $device = 'Desktop'; $icon = '💻';
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

function time_elapsed($datetime) {
    $now = new DateTime();
    $ago = new DateTime($datetime);
    $diff = $now->diff($ago);
    $diff->w = floor($diff->d/7);
    $diff->d -= $diff->w*7;

    $map = array('y'=>'ano','m'=>'mês','w'=>'semana','d'=>'dia','h'=>'hora','i'=>'minuto','s'=>'segundo');
    $out = array();
    foreach($map as $k=>$v) { 
        if ($diff->$k) { $out[] = $diff->$k . ' ' . $v . ($diff->$k>1?'s':''); }
    }
    return count($out) ? $out[0].' atrás' : 'agora mesmo';
}

// --- POST Actions ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['reset_stats']) && $_POST['reset_stats']=='1') {
        save_json($pixLogPath, array());
        save_json($searchLogPath, array());
        save_json($clickStatsPath, array('consultar_clicks'=>0,'enter_clicks'=>0));
        header('Location: admin.php?msg='.urlencode('Todos os logs e estatísticas foram limpos.'));
        exit;
    }
    elseif (!empty($_POST['pixKey'])) {
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

// --- Load Data ---
$cfg = load_json($cfgPath, array('pixKey'=>'06721661195'));
$currentKey = isset($cfg['pixKey']) ? $cfg['pixKey'] : '06721661195';

$pixEntries = load_json($pixLogPath, array());
$searchEntries = load_json($searchLogPath, array());
$clickStats = load_json($clickStatsPath, array('consultar_clicks'=>0,'enter_clicks'=>0));

// Sort desc by ts
usort($pixEntries, function($a,$b){ return strtotime(isset($b['ts'])?$b['ts']:0) - strtotime(isset($a['ts'])?$a['ts']:0); });
usort($searchEntries, function($a,$b){ return strtotime(isset($b['ts'])?$b['ts']:0) - strtotime(isset($a['ts'])?$a['ts']:0); });

// Stats
$totalPix = 0; foreach($pixEntries as $p) $totalPix += isset($p['valor']) ? floatval($p['valor']) : 0;
$uniqueIps = array(); foreach(array_merge($pixEntries,$searchEntries) as $e){ if(isset($e['ip'])) $uniqueIps[$e['ip']]=true; }
$totalVisitors = count($uniqueIps);
?>

<!doctype html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<title>Admin Dashboard</title>
</head>
<body>
<h1>Admin Dashboard</h1>
<?php if($msg): ?><div style="background:#d4edda;padding:10px;margin:10px;"><?php echo htmlspecialchars($msg); ?></div><?php endif; ?>

<h2>Chave PIX</h2>
<form method="post">
<input type="text" name="pixKey" value="<?php echo htmlspecialchars($currentKey); ?>">
<button type="submit">Salvar</button>
</form>

<h2>Estatísticas</h2>
<ul>
<li>Total PIX: R$ <?php echo number_format($totalPix,2,',','.'); ?></li>
<li>Entradas: <?php echo isset($clickStats['enter_clicks']) ? $clickStats['enter_clicks'] : 0; ?></li>
<li>Buscas: <?php echo count($searchEntries); ?></li>
<li>Visitantes únicos: <?php echo $totalVisitors; ?></li>
</ul>

<h2>PIX Recentes</h2>
<?php if(empty($pixEntries)) echo "<p>Nenhum registro</p>"; else: ?>
<ul><?php foreach(array_slice($pixEntries,0,10) as $p){ $ua=parse_ua(isset($p['ua'])?$p['ua']:''); ?>
<li><?php echo isset($p['placa'])?$p['placa']:'N/A'; ?> - <?php echo isset($p['valor_brl'])?$p['valor_brl']:'R$0,00'; ?> - <?php echo $ua['icon'].' '.$ua['type']; ?></li>
<?php } ?></ul><?php endif; ?>

<h2>Histórico de Buscas</h2>
<?php if(empty($searchEntries)) echo "<p>Nenhuma busca</p>"; else: ?>
<ul><?php foreach(array_slice($searchEntries,0,10) as $s){ $ua=parse_ua(isset($s['ua'])?$s['ua']:''); ?>
<li><?php echo isset($s['plate'])?$s['plate']:'-'; ?> - <?php echo $ua['icon'].' '.$ua['type']; ?></li>
<?php } ?></ul><?php endif; ?>

<form method="post" onsubmit="return confirm('Tem certeza?');">
<input type="hidden" name="reset_stats" value="1">
<button type="submit">Limpar Todos os Dados</button>
</form>

</body>
</html>
