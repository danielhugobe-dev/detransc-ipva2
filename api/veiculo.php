<?php

/**
 * Script de roteamento para veículos (substituto para .htaccess)
 * Aceita slug via $_GET['slug'] ou PATH_INFO
 */

// Tenta obter o slug de várias formas
$slug = $_GET['slug'] ?? '';

if (empty($slug) && !empty($_SERVER['PATH_INFO'])) {
    $slug = trim($_SERVER['PATH_INFO'], '/');
}

// Se ainda vazio, tenta extrair da query string se for a única chave
if (empty($slug) && !empty($_SERVER['QUERY_STRING'])) {
    // Se a query string for apenas o slug (ex: ?r-123...)
    if (strpos($_SERVER['QUERY_STRING'], '=') === false) {
        $slug = $_SERVER['QUERY_STRING'];
    }
}

if (empty($slug)) {
    http_response_code(404);
    echo "Veículo não especificado.";
    exit;
}

// Sanitização básica do slug para evitar path traversal
$slug = basename($slug);

// Verifica se é um ID de mapeamento (começa com r-)
$userId = $slug; // Default: assume que o slug já é o userId

if (strpos($slug, 'r-') === 0) {
    // É um RID, busca no mapeamento
    $mapFile = __DIR__ . '/data/reqmap/' . $slug . '.json';
    if (file_exists($mapFile)) {
        $content = @file_get_contents($mapFile);
        $data = @json_decode($content, true);
        if (isset($data['userId'])) {
            $userId = $data['userId'];
        }
    }
}

// Configura o ambiente para debitos.php
$_GET['userId'] = $userId;

// Inclui o script de exibição
require __DIR__ . '/debitos.php';
