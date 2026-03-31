<?php

/*
|--------------------------------------------------------------------------
| 1️⃣ VALIDAR PARÂMETROS
|--------------------------------------------------------------------------
*/

$plate = $_GET['plate'] ?? null;
$renavam = $_GET['renavam'] ?? null;
$idDocument = $_GET['idDocument'] ?? null;
$userIdParam = $_GET['userId'] ?? null;

function jsonError($message, $extra = [])
{
    header('Content-Type: application/json; charset=utf-8');
    $payload = array_merge(['success' => false, 'erro' => $message], $extra);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if (!$userIdParam && (!$plate || !$renavam)) {
    jsonError("Parâmetros obrigatórios: ?plate=&renavam= (ou ?userId=)");
}

/*
|--------------------------------------------------------------------------
| 2️⃣ PRIMEIRA REQUISIÇÃO - POST (Obter Redirecionamento)
|--------------------------------------------------------------------------
*/

if ($userIdParam) {
    $userId = $userIdParam;
    $response = '';
} else {
    $urlPost = "https://secretariaveicularsc-govbr.vercel.app/api/scrape7";
    $payload = [
        "plate" => $plate,
        "renavam" => $renavam,
        "idDocumentIsMandatory" => false,
        "idDocument" => $idDocument,
    ];
    $headersPost = [
        "Content-Type: application/json",
        "Accept: */*",
        "Authorization: eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJwbGF0ZSI6Ik1ISzA1ODEiLCJyZW5hdmFtIjoiMDA1OTQwNDIzNTYiLCJpYXQiOjE3NzQ4MTk5NTF9.lcUobS1f8caTnzWiUFNI6KgPhNI4NmJHuyTmsiYG76U",
        "Origin: https://secretariaveicularsc-govbr.vercel.app/",
        "Referer: https://secretariaveicularsc-govbr.vercel.app/"
    ];
    $ch = curl_init($urlPost);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => $headersPost,
        CURLOPT_ENCODING => ""
    ]);
    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        jsonError("Erro cURL (POST): " . curl_error($ch));
    }
    curl_close($ch);
}

/*
|--------------------------------------------------------------------------
| 3️⃣ CAPTURA O ID (via REGEX no HTML de Redirecionamento)
|--------------------------------------------------------------------------
*/

// O retorno é um HTML: <a href="/veiculo/69b0902e...">here</a>
// Vamos extrair o ID alfanumérico que vem após /veiculo/
if (!isset($userId) || !$userId) {
    if ($response && preg_match('/\/veiculo\/([a-z0-9]+)/i', $response, $matches)) {
        $userId = $matches[1];
    } else {
        // Caso o retorno seja JSON
        $data = json_decode($response, true);
        $userId = $data['userId'] ?? null;
    }
}

if (!$userId) {
    jsonError("Erro: Não foi possível localizar o ID do veículo na resposta.", ['raw' => $response]);
}

/*
|--------------------------------------------------------------------------
| 4️⃣ SEGUNDA REQUISIÇÃO - GET DINÂMICO (HTML do Veículo)
|--------------------------------------------------------------------------
*/

$urlGet = "https://secretariaveicularsc-govbr.vercel.app/veiculo/" . $userId;

$headersGet = [
    "Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
    "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36"
];

$ch2 = curl_init($urlGet);

curl_setopt_array($ch2, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPGET => true,
    CURLOPT_HTTPHEADER => $headersGet,
    CURLOPT_ENCODING => ""
]);

$responseGet = curl_exec($ch2);

if (curl_errno($ch2)) {
    jsonError("Erro cURL (GET): " . curl_error($ch2));
}
curl_close($ch2);

/*
|--------------------------------------------------------------------------
| 5️⃣ PARSING DO HTML PARA JSON
|--------------------------------------------------------------------------
*/

libxml_use_internal_errors(true);
$dom = new DOMDocument();
// Tratamento para caracteres especiais
@$dom->loadHTML('<?xml encoding="utf-8" ?>' . $responseGet);
$xpath = new DOMXPath($dom);

$veiculo = [];

// Normaliza texto (remove múltiplos espaços e dois-pontos do fim)
function norm($s) {
    $t = trim(preg_replace('/\s+/u', ' ', $s));
    return rtrim($t, " :\xC2\xA0"); // remove ":" e &nbsp;
}

// Constrói um mapa label -> valor percorrendo estrutura comum (strong/b, dt/dd, tabela)
$labelMap = [];

// h2 / p (estrutura atual do site)
$h2Nodes = $xpath->query("//h2");
foreach ($h2Nodes as $h2) {
    $label = norm($h2->textContent);
    $p = $xpath->query("following-sibling::p[1]", $h2);
    if ($p && $p->length > 0) {
        $value = norm($p->item(0)->textContent);
        if ($label !== "" && $value !== "") {
            $labelMap[$label] = $value;
        }
    }
}

// strong / b
$labelNodes = $xpath->query("//strong | //b");
foreach ($labelNodes as $node) {
    $label = norm($node->textContent);
    // tenta texto do próximo irmão
    $nextText = $xpath->query("following-sibling::text()[1]", $node);
    $value = "";
    if ($nextText && $nextText->length > 0) {
        $value = norm($nextText->item(0)->nodeValue);
    }
    if ($value === "") {
        // tenta próximo elemento
        $nextElem = $xpath->query("following-sibling::*[1]", $node);
        if ($nextElem && $nextElem->length > 0) {
            $value = norm($nextElem->item(0)->textContent);
        }
    }
    if ($label !== "" && $value !== "") {
        $labelMap[$label] = $value;
    }
}

// dt / dd
$dtNodes = $xpath->query("//dt");
foreach ($dtNodes as $dt) {
    $label = norm($dt->textContent);
    $dd = $xpath->query("following-sibling::dd[1]", $dt);
    if ($dd && $dd->length > 0) {
        $value = norm($dd->item(0)->textContent);
        if ($label !== "" && $value !== "") {
            $labelMap[$label] = $value;
        }
    }
}

// tabela: primeira célula label, segunda valor
$rows = $xpath->query("//table//tr");
foreach ($rows as $row) {
    $cells = $xpath->query("td", $row);
    if ($cells->length >= 2) {
        $label = norm($cells->item(0)->textContent);
        $value = norm($cells->item(1)->textContent);
        if ($label !== "" && $value !== "") {
            // não sobrescreve se já existe com valor diferente
            if (!isset($labelMap[$label])) {
                $labelMap[$label] = $value;
            }
        }
    }
}

function getFromMap($map, $labels) {
    foreach ($labels as $l) {
        // tenta match exato
        if (isset($map[$l]) && $map[$l] !== "") {
            return $map[$l];
        }
        // tenta variantes sem acento
        $noAccent = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $l);
        foreach ($map as $k => $v) {
            $kNorm = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $k);
            if (norm($kNorm) === norm($noAccent) && $v !== "") {
                return $v;
            }
        }
    }
    return "Não encontrado";
}

$veiculo['sucesso'] = true;
$veiculo['userId'] = $userId;
$veiculo['dados_veiculo'] = [
    'marca_modelo' => getFromMap($labelMap, ['Marca / Modelo','Marca/Modelo']),
    'renavam'      => getFromMap($labelMap, ['Renavam']),
    'tipo'         => getFromMap($labelMap, ['Tipo']),
    'especie'      => getFromMap($labelMap, ['Espécie','Especie']),
    'combustivel'  => getFromMap($labelMap, ['Combustível','Combustivel']),
    'placa'        => getFromMap($labelMap, ['Placa']),
    'ano_fab_mod'  => getFromMap($labelMap, ['Fabricação / Modelo']),
    'cor'          => getFromMap($labelMap, ['Cor']),
];

// Extração de Débitos
$debitos = [];
$rows = $xpath->query("//table//tr"); 

foreach ($rows as $row) {
    $cols = $xpath->query("td", $row);
    if ($cols->length >= 4) {
        $desc = trim($cols->item(0)->nodeValue);
        $venc = trim($cols->item(1)->nodeValue);
        
        // Determina dinamicamente onde está o valor (procurando R$ ou formato numérico)
        $sit = '';
        $valor = '';
        
        // Pega todos os valores a partir da 3ª coluna
        $colTexts = [];
        for ($i = 2; $i < $cols->length; $i++) {
            $colTexts[] = trim($cols->item($i)->nodeValue);
        }
        
        // Tenta achar a coluna de valor (que contém R$ ou apenas números e vírgula)
        foreach ($colTexts as $text) {
            if (strpos($text, 'R$') !== false || preg_match('/^\d{1,3}(\.\d{3})*,\d{2}$/', $text)) {
                $valor = $text;
                break;
            }
        }
        
        // Se achou o valor, tenta definir a situação
        if ($valor !== '') {
            foreach ($colTexts as $text) {
                if ($text !== $valor && stripos($text, 'PIX') === false && $text !== '') {
                    $sit = $text;
                    break;
                }
            }
        } else {
            // Fallback para o comportamento anterior se não achar R$
            $sit  = trim($cols->item(2)->nodeValue);
            $valor= trim($cols->item(3)->nodeValue);
        }
        
        // Ignora cabeçalhos
        $isHeader = ($desc === "Descrição" || $desc === "Débito" || $venc === "Vencimento" || $sit === "Situação" || $valor === "Valor total");
        if (!$isHeader && $desc !== "") {
            $debitos[] = [
                'descricao'   => $desc,
                'vencimento'  => $venc,
                'situacao'    => $sit,
                'valor_total' => trim(str_ireplace('PIX', '', $valor))
            ];
        }
    }
}

$veiculo['debitos'] = $debitos;

// --- LOGGING ---
// Só loga se não for uma requisição interna (vinda do próprio servidor)
// e se tiver placa/renavam definidos ou extraídos
$isInternal = ($_SERVER['REMOTE_ADDR'] === '127.0.0.1' || $_SERVER['REMOTE_ADDR'] === '::1') && 
              strpos($_SERVER['HTTP_USER_AGENT'] ?? '', 'curl') !== false;

// Ou podemos verificar se é a chamada de validação (tem $plate e $renavam no GET) 
// vs chamada de exibição (tem apenas userId no GET)
// Vamos logar APENAS quando for a validação inicial (que tem plate/renavam explícitos no GET)
// ou se for userId, verificamos se já não foi logado recentemente (deduplicação simples)

$shouldLog = false;
if ($plate && $renavam) {
    // Chamada inicial do index.php
    $shouldLog = true;
}

if ($shouldLog) {
    try {
        $logData = [
            'ts' => date('c'),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
            'ua' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
            'plate' => $plate ?? ($veiculo['dados_veiculo']['placa'] ?? 'N/A'),
            'renavam' => $renavam ?? ($veiculo['dados_veiculo']['renavam'] ?? 'N/A'),
            'success' => true
        ];
        $logFile = sys_get_temp_dir() . '/search_log.json';
        $logs = [];
        if (file_exists($logFile)) {
            $logs = json_decode(file_get_contents($logFile), true);
            if (!is_array($logs)) $logs = [];
        }
        
        // Deduplicação: verifica se o último log é idêntico (mesmo IP, placa e renavam, e tempo < 5s)
        $last = end($logs);
        $isDuplicate = false;
        if ($last) {
            $lastTime = strtotime($last['ts']);
            $currentTime = time();
            if (
                ($currentTime - $lastTime) < 5 && 
                $last['ip'] === $logData['ip'] && 
                $last['plate'] === $logData['plate']
            ) {
                $isDuplicate = true;
            }
        }

        if (!$isDuplicate) {
            // Limit log size to last 1000 entries
            if (count($logs) > 1000) array_shift($logs);
            $logs[] = $logData;
           @file_put_contents($logFile, json_encode($logs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }
    } catch (Exception $e) {
        // Silent fail for logging
    }
}
// --- END LOGGING ---

// Retorno Final em JSON
header('Content-Type: application/json; charset=utf-8');
echo json_encode($veiculo, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
