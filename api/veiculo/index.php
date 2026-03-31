<?php
$slug = $_GET['slug'] ?? '';
if (!$slug) {
    http_response_code(404);
    echo 'Página não encontrada';
    exit;
}
$_GET['slug'] = $slug;
require_once dirname(__DIR__) . '/veiculo.php';
