<?php
header('Content-Type: application/json; charset=utf-8');
try {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!is_array($data)) { throw new Exception('JSON inválido'); }
    $userId = isset($data['userId']) ? trim($data['userId']) : '';
    if ($userId === '') { throw new Exception('userId obrigatório'); }
    // gera id único por requisição (prefixo 'r-' para evitar colisão com ids do upstream)
    $rid = 'r-' . bin2hex(random_bytes(8)) . '-' . time();
    $dir = __DIR__ . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'reqmap';
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    $file = $dir . DIRECTORY_SEPARATOR . $rid . '.json';
    $payload = [
        'userId' => $userId,
        'ts' => time()
    ];
    if (!file_put_contents($file, json_encode($payload, JSON_UNESCAPED_UNICODE))) {
        throw new Exception('Falha ao salvar mapeamento');
    }
    echo json_encode(['success' => true, 'rid' => $rid], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'erro' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
