<?php
$spath = __DIR__ . '/stats.json';
$stats = ['index_clicks2'=>0,'pix_generated'=>0];
if (is_file($spath)) {
    $prev = json_decode(@file_get_contents($spath), true);
    if (is_array($prev)) { $stats = array_merge($stats, $prev); }
}
$stats['index_clicks2'] = (int)($stats['index_clicks2'] ?? 0) + 1;
@file_put_contents($spath, json_encode($stats, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['status'=>'ok','index_clicks2'=>$stats['index_clicks2']]);
