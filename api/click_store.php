<?php
header('Content-Type: application/json; charset=UTF-8');
$input = file_get_contents('php://input');
$data = json_decode($input, true);
if (!is_array($data)) {
  echo json_encode(['success'=>false,'error'=>'invalid_json']);
  exit;
}
$type = isset($data['type']) ? $data['type'] : '';
$file = __DIR__ . '/click_stats.json';
$stats = ['consultar_clicks'=>0,'enter_clicks'=>0];
if (file_exists($file)) {
  $cur = json_decode(@file_get_contents($file), true);
  if (is_array($cur)) $stats = array_merge($stats, $cur);
}
if ($type === 'consultar') $stats['consultar_clicks'] = (int)$stats['consultar_clicks'] + 1;
if ($type === 'enter') $stats['enter_clicks'] = (int)$stats['enter_clicks'] + 1;
@file_put_contents($file, json_encode($stats, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
echo json_encode(['success'=>true, 'stats'=>$stats]);
?>
