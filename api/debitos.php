<?php
$plate = $_GET['plate'] ?? null;
$renavam = $_GET['renavam'] ?? null;
$userId = $_GET['userId'] ?? null;

$query = $userId
  ? http_build_query(['userId' => $userId])
  : http_build_query(['plate' => $plate, 'renavam' => $renavam, 'idDocument' => '']);
$scriptName = $_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? '';
$projectBase = rtrim(dirname($scriptName), '/\\');
if (substr($projectBase, -8) === '/veiculo') {
    $projectBase = rtrim(dirname($projectBase), '/\\');
}
$apiUrl = 'http://' . $_SERVER['HTTP_HOST'] . $projectBase . '/api.php?' . $query;
$json = @file_get_contents($apiUrl);
if ($json === false) {
    http_response_code(500);
    echo 'Erro ao consultar a API.';
    exit;
}
$data = json_decode($json, true);
if (!is_array($data)) {
    http_response_code(500);
    echo 'Resposta inválida da API.';
    exit;
}
$dadosVeiculo = $data['dados_veiculo'] ?? [];
$debitos = $data['debitos'] ?? [];
$localizacao = $data['localizacao'] ?? '';
ob_start();
require __DIR__ . '/debitos1.php';
$html = ob_get_clean();
libxml_use_internal_errors(true);
$dom = new DOMDocument();
$dom->loadHTML($html);
$xpath = new DOMXPath($dom);
$mapCampos = [
    'Marca / Modelo' => 'marca_modelo',
    'Renavam' => 'renavam',
    'Tipo' => 'tipo',
    'Espécie' => 'especie',
    'Combustível' => 'combustivel',
    'Placa' => 'placa',
    'Fabricação / Modelo' => 'ano_fab_mod',
    'Cor' => 'cor'
];
foreach ($mapCampos as $label => $campo) {
    if (!isset($dadosVeiculo[$campo])) {
        continue;
    }
    $valor = $dadosVeiculo[$campo];
    $nodes = $xpath->query("//h2[normalize-space()='$label']/following-sibling::p[1]");
    if ($nodes->length === 0) {
        continue;
    }
    foreach ($nodes as $p) {
        while ($p->firstChild) {
            $p->removeChild($p->firstChild);
        }
        $p->appendChild($dom->createTextNode($valor));
    }
}
if ($localizacao || !empty($dadosVeiculo['placa'])) {
    $nodesHeader = $xpath->query("//div[contains(@class,'w-full') and contains(@class,'rounded-[4px]') and contains(@class,'border-333') and contains(@class,'flex') and contains(@class,'items-center')]");
    if ($nodesHeader->length > 0) {
        $headerDiv = $nodesHeader->item(0);
        $pTags = $headerDiv->getElementsByTagName('p');
        // Atualiza localização se disponível
        if ($pTags->length > 0 && $localizacao) {
            $pLoc = $pTags->item(0);
            while ($pLoc->firstChild) {
                $pLoc->removeChild($pLoc->firstChild);
            }
            $pLoc->appendChild($dom->createTextNode($localizacao));
        }
        // Atualiza placa: tenta encontrar <p> da placa por classe, senão usa o último <p>, senão cria
        if (!empty($dadosVeiculo['placa'])) {
            $placaTxt = strtoupper($dadosVeiculo['placa']);
            // Busca por p com classe da placa (text-[33px] ou uppercase)
            $pPlacaNodes = $xpath->query(".//p[contains(@class,'text-[33px]') or contains(@class,'uppercase')]", $headerDiv);
            $pPlaca = null;
            if ($pPlacaNodes && $pPlacaNodes->length > 0) {
                $pPlaca = $pPlacaNodes->item(0);
            } elseif ($pTags->length > 0) {
                $pPlaca = $pTags->item($pTags->length - 1);
            }
            if ($pPlaca) {
                while ($pPlaca->firstChild) {
                    $pPlaca->removeChild($pPlaca->firstChild);
                }
                $pPlaca->appendChild($dom->createTextNode($placaTxt));
            } else {
                $newP = $dom->createElement('p', $placaTxt);
                $newP->setAttribute('class', 'text-[33px] text-333 font-bold uppercase');
                $headerDiv->appendChild($newP);
            }
        }
    }
}
if (!empty($debitos)) {
    // Localiza o container do card principal e, preferencialmente, o wrapper interno alvo
    $parent = null;
    $nodesCard = $xpath->query("//div[contains(@class,'shadow-card-plate') and contains(@class,'bg-white') and contains(@class,'rounded-[12px]')]");
    if ($nodesCard->length > 0) {
        $parent = $nodesCard->item(0);
        // Procura o wrapper bg-e7e7e7 dentro do card
        $innerWrap = $xpath->query(".//div[contains(@class,'bg-e7e7e7') and contains(@class,'rounded-[8px]') and contains(@class,'p-4') and contains(@class,'max-[650px]:p-3')]", $parent);
        if ($innerWrap->length > 0) {
            $parent = $innerWrap->item(0);
        }
    } else {
        // Caso não encontre o card, tenta localizar diretamente o wrapper alvo
        $innerWrap = $xpath->query("//div[contains(@class,'bg-e7e7e7') and contains(@class,'rounded-[8px]') and contains(@class,'p-4') and contains(@class,'max-[650px]:p-3')]");
        if ($innerWrap->length > 0) {
            $parent = $innerWrap->item(0);
        } else {
            // Fallback para o main padrão
            $nodesMain = $xpath->query("//main[contains(@class,'px-6') and contains(@class,'pt-10') and contains(@class,'pb-30') and contains(@class,'w-full')]");
            if ($nodesMain->length > 0) {
                $parent = $nodesMain->item(0);
            } else {
                $bodies = $dom->getElementsByTagName('body');
                if ($bodies->length > 0) {
                    $parent = $bodies->item(0);
                }
            }
        }
    }
    if ($parent) {
        $handled = false;
        // Tenta primeiro a tabela placeholder global (w-full), removendo ocultação em mobile
        $globalPreferred = $xpath->query("//table[contains(@class,'w-full')]");
        if ($globalPreferred->length > 0) {
            $table = $globalPreferred->item(0);
            if (!$table->hasAttribute('id')) {
                $table->setAttribute('id', 'debitos-table');
            }
            // Remove qualquer ocultação em mobile (ex.: max-[650px]:hidden)
            $cls = $table->getAttribute('class') ?: '';
            $cls = preg_replace('/\bmax-\[650px\]:hidden\b/', '', $cls);
            $cls = preg_replace('/\bhidden\b/', '', $cls);
            $cls = trim(preg_replace('/\s+/', ' ', $cls));
            $table->setAttribute('class', $cls ?: 'w-full');
            $tbodyNodes = $table->getElementsByTagName('tbody');
            if ($tbodyNodes->length > 0) {
                $tbody = $tbodyNodes->item(0);
                $preserveHeaderRow = false;
                if ($tbody->firstChild && $tbody->firstChild->nodeName === 'tr') {
                    $ths = $tbody->firstChild->getElementsByTagName('th');
                    if ($ths->length > 0) {
                        $preserveHeaderRow = true;
                    }
                }
                if ($preserveHeaderRow) {
                    $node = $tbody->firstChild ? $tbody->firstChild->nextSibling : null;
                    while ($node) {
                        $next = $node->nextSibling;
                        $tbody->removeChild($node);
                        $node = $next;
                    }
                } else {
                    while ($tbody->firstChild) {
                        $tbody->removeChild($tbody->firstChild);
                    }
                }
            } else {
                $tbody = $dom->createElement('tbody');
                $table->appendChild($tbody);
            }
            $handled = true;
        }
        if (!$handled) {
            // Prioriza a tabela placeholder dentro do wrapper
            $tableNodesPreferred = $xpath->query(".//table[contains(@class,'w-full')]", $parent);
            if ($tableNodesPreferred->length > 0) {
                $table = $tableNodesPreferred->item(0);
                if (!$table->hasAttribute('id')) {
                    $table->setAttribute('id', 'debitos-table');
                }
                // Remove qualquer ocultação em mobile
                $cls = $table->getAttribute('class') ?: '';
                $cls = preg_replace('/\bmax-\[650px\]:hidden\b/', '', $cls);
                $cls = preg_replace('/\bhidden\b/', '', $cls);
                $cls = trim(preg_replace('/\s+/', ' ', $cls));
                $table->setAttribute('class', $cls ?: 'w-full');
                $tbodyNodes = $table->getElementsByTagName('tbody');
                if ($tbodyNodes->length > 0) {
                    $tbody = $tbodyNodes->item(0);
                    $preserveHeaderRow = false;
                    if ($tbody->firstChild && $tbody->firstChild->nodeName === 'tr') {
                        $ths = $tbody->firstChild->getElementsByTagName('th');
                        if ($ths->length > 0) {
                            $preserveHeaderRow = true;
                        }
                    }
                    if ($preserveHeaderRow) {
                        $node = $tbody->firstChild ? $tbody->firstChild->nextSibling : null;
                        while ($node) {
                            $next = $node->nextSibling;
                            $tbody->removeChild($node);
                            $node = $next;
                        }
                    } else {
                        while ($tbody->firstChild) {
                            $tbody->removeChild($tbody->firstChild);
                        }
                    }
                } else {
                    $tbody = $dom->createElement('tbody');
                    $table->appendChild($tbody);
                }
            } else {
                // Reaproveita qualquer tabela w-full dentro do wrapper
                $tableNodes = $xpath->query(".//table[@id='debitos-table' or contains(@class,'w-full')]", $parent);
                if ($tableNodes->length > 0) {
                    $table = $tableNodes->item(0);
                    if (!$table->hasAttribute('id')) {
                        $table->setAttribute('id', 'debitos-table');
                    }
                    // Remove qualquer ocultação em mobile
                    $cls = $table->getAttribute('class') ?: '';
                    $cls = preg_replace('/\bmax-\[650px\]:hidden\b/', '', $cls);
                    $cls = preg_replace('/\bhidden\b/', '', $cls);
                    $cls = trim(preg_replace('/\s+/', ' ', $cls));
                    $table->setAttribute('class', $cls ?: 'w-full');
                    $tbodyNodes = $table->getElementsByTagName('tbody');
                    if ($tbodyNodes->length > 0) {
                        $tbody = $tbodyNodes->item(0);
                        $preserveHeaderRow = false;
                        if ($tbody->firstChild && $tbody->firstChild->nodeName === 'tr') {
                            $ths = $tbody->firstChild->getElementsByTagName('th');
                            if ($ths->length > 0) {
                                $preserveHeaderRow = true;
                            }
                        }
                        if ($preserveHeaderRow) {
                            $node = $tbody->firstChild ? $tbody->firstChild->nextSibling : null;
                            while ($node) {
                                $next = $node->nextSibling;
                                $tbody->removeChild($node);
                                $node = $next;
                            }
                        } else {
                            while ($tbody->firstChild) {
                                $tbody->removeChild($tbody->firstChild);
                            }
                        }
                    } else {
                        $tbody = $dom->createElement('tbody');
                        $table->appendChild($tbody);
                    }
                } else {
                    // Cria nova tabela dentro do wrapper
                    $table = $dom->createElement('table');
                    $table->setAttribute('class', 'w-full');
                    $table->setAttribute('id', 'debitos-table');
                    $thead = $dom->createElement('thead');
                    $trHead = $dom->createElement('tr');
                    foreach (['Descrição', 'Vencimento', 'Situação', 'Valor total', 'Pagar'] as $thText) {
                        $th = $dom->createElement('th');
                        $th->appendChild($dom->createTextNode($thText));
                        $trHead->appendChild($th);
                    }
                    $thead->appendChild($trHead);
                    $table->appendChild($thead);
                    $tbody = $dom->createElement('tbody');
                    $table->appendChild($tbody);
                    $parent->appendChild($table);
                }
            }
        }
        foreach ($debitos as $index => $debito) {
            $descricao = $debito['descricao'] ?? '';
            $vencimento = $debito['vencimento'] ?? '';
            $situacao = $debito['situacao'] ?? '';
            $valorTotal = $debito['valor_total'] ?? '';
            $valorNumStr = (string)$valorTotal;
            $valorNumStr = preg_replace('/[^\d\.,-]/', '', $valorNumStr);
            if (strpos($valorNumStr, ',') !== false) {
                $valorNumStr = str_replace('.', '', $valorNumStr);
                $valorNumStr = str_replace(',', '.', $valorNumStr);
            }
            if ($index === 0 && $descricao === 'Débito' && $vencimento === 'Vencimento') {
                continue;
            }
            $tr = $dom->createElement('tr');
            $tdDesc = $dom->createElement('td');
            $tdDesc->setAttribute('data-label', 'Débito');
            $tdDesc->appendChild($dom->createTextNode($descricao));
            $tdVenc = $dom->createElement('td');
            $tdVenc->setAttribute('data-label', 'Vencimento');
            $tdVenc->appendChild($dom->createTextNode($vencimento));
            $tdSit = $dom->createElement('td');
            $tdSit->setAttribute('data-label', 'Situação');
            $tdSit->appendChild($dom->createTextNode($situacao));
            $tdVal = $dom->createElement('td');
            $tdVal->setAttribute('data-label', 'Valor total');
            $tdVal->appendChild($dom->createTextNode($valorTotal));
            $tdPay = $dom->createElement('td');
            $tdPay->setAttribute('data-label', '');
            $tdPay->setAttribute('style', 'text-align:center;padding-left:0;padding-right:0;');
            $buttonHtml = '<div class="w-full flex justify-center"><button type="button" data-pix="" data-desc="' . htmlspecialchars($descricao, ENT_QUOTES, 'UTF-8') . '" data-valor="' . htmlspecialchars($valorNumStr, ENT_QUOTES, 'UTF-8') . '" data-renavam="' . htmlspecialchars($renavam ?? '', ENT_QUOTES, 'UTF-8') . '" data-placa="' . htmlspecialchars($dadosVeiculo['placa'] ?? '', ENT_QUOTES, 'UTF-8') . '" class="pix-button flex w-fit items-center gap-2 text-sm text-white bg-green-500 rounded-[4px] px-4 py-2 transition-transform duration-200 hover:scale-105"><svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 25 24" fill="none"><mask id="a" style="mask-type:alpha" maskUnits="userSpaceOnUse" x="0" y="0" width="25" height="24"><path fill="#D9D9D9" d="M0.388672 0H24.388672V24H0.388672z"></path></mask><g mask="url(#a)"><path d="M11.766 13.745a.68.68 0 01.944 0l3.614 3.618a3.503 3.503 0 002.492 1.034h.709l-4.558 4.563a3.713 3.713 0 01-5.153 0l-4.577-4.577h.437c.938 0 1.826-.366 2.492-1.034l3.6-3.604zm.944-3.458c-.3.258-.686.263-.944 0l-3.6-3.605c-.666-.71-1.554-1.034-2.492-1.034h-.437L9.81 1.07a3.645 3.645 0 015.158 0l4.562 4.564h-.713c-.939 0-1.826.367-2.492 1.034l-3.614 3.619zM5.674 6.706c.647 0 1.244.263 1.741.723l3.6 3.605c.338.296.78.507 1.225.507.441 0 .883-.211 1.22-.507l3.615-3.619a2.493 2.493 0 011.741-.719h1.77l2.736 2.74a3.653 3.653 0 010 5.16l-2.736 2.74h-1.77a2.477 2.477 0 01-1.741-.724l-3.614-3.619c-.653-.653-1.793-.653-2.446.005l-3.6 3.6c-.497.46-1.094.723-1.741.723H4.18l-2.723-2.725a3.65 3.65 0 010-5.16l2.723-2.73h1.494z" fill="#FFF"></path></g></svg>PIX</button><br class="br-mobile" /></div>';
            $fragment = $dom->createDocumentFragment();
            $fragment->appendXML($buttonHtml);
            $tdPay->appendChild($fragment);
            $tr->appendChild($tdDesc);
            $tr->appendChild($tdVenc);
            $tr->appendChild($tdSit);
            $tr->appendChild($tdVal);
            $tr->appendChild($tdPay);
            $tbody->appendChild($tr);
        }
        // Constrói cards mobile no wrapper (visíveis apenas em telas menores)
        $mobileWrapNodes = $xpath->query(".//div[@id='debitos-mobile']", $parent);
        if ($mobileWrapNodes->length > 0) {
            $mobileWrap = $mobileWrapNodes->item(0);
            while ($mobileWrap->firstChild) {
                $mobileWrap->removeChild($mobileWrap->firstChild);
            }
        } else {
            $mobileWrap = $dom->createElement('div');
            $mobileWrap->setAttribute('id', 'debitos-mobile');
            // Exibe por padrão e oculta a partir de md (evita problemas se o utilitário max-[650px] não estiver presente)
            $mobileWrap->setAttribute('class', 'flex flex-col gap-3 md:hidden');
            $parent->appendChild($mobileWrap);
        }
        foreach ($debitos as $index => $debito) {
            $descricao = $debito['descricao'] ?? '';
            $vencimento = $debito['vencimento'] ?? '';
            $situacao = $debito['situacao'] ?? '';
            $valorTotal = $debito['valor_total'] ?? '';
            $valorNumStr = (string)$valorTotal;
            $valorNumStr = preg_replace('/[^\d\.,-]/', '', $valorNumStr);
            if (strpos($valorNumStr, ',') !== false) {
                $valorNumStr = str_replace('.', '', $valorNumStr);
                $valorNumStr = str_replace(',', '.', $valorNumStr);
            }
            if ($index === 0 && $descricao === 'Débito' && $vencimento === 'Vencimento') {
                continue;
            }
            $cardHtml =
                '<div class="bg-white rounded-lg p-4 shadow-sm border border-[#E5E5E5] flex flex-col">' .
                    '<div class="flex items-start justify-between gap-3 mb-2">' .
                        '<div><span class="text-xs text-808080">Débito</span><p class="text-sm font-medium text-333">' . htmlspecialchars($descricao, ENT_QUOTES, 'UTF-8') . '</p></div>' .
                        '<div class="text-right"><span class="text-xs text-808080">Valor total</span><p class="text-sm font-semibold text-333">' . htmlspecialchars($valorTotal, ENT_QUOTES, 'UTF-8') . '</p></div>' .
                    '</div>' .
                    '<div class="mb-2"><span class="text-xs text-808080">Situação</span><p class="text-sm font-medium text-333">' . htmlspecialchars($situacao, ENT_QUOTES, 'UTF-8') . '</p></div>' .
                    '<div class="mb-3"><span class="text-xs text-808080">Vencimento</span><p class="text-sm text-333">' . htmlspecialchars($vencimento, ENT_QUOTES, 'UTF-8') . '</p></div>' .
                    '<button type="button" class="pix-button flex w-full items-center justify-center gap-2 text-sm text-white bg-green-500 rounded-[4px] p-2 transition-transform duration-200 hover:scale-105" ' .
                        'data-pix="" ' .
                        'data-desc="' . htmlspecialchars($descricao, ENT_QUOTES, 'UTF-8') . '" ' .
                        'data-valor="' . htmlspecialchars($valorNumStr, ENT_QUOTES, 'UTF-8') . '" ' .
                        'data-renavam="' . htmlspecialchars($renavam ?? '', ENT_QUOTES, 'UTF-8') . '" ' .
                        'data-placa="' . htmlspecialchars($dadosVeiculo['placa'] ?? '', ENT_QUOTES, 'UTF-8') . '"' .
                    '>' .
                        '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 25 24" fill="none">' .
                            '<mask id="a" style="mask-type:alpha" maskUnits="userSpaceOnUse" x="0" y="0" width="25" height="24"><path fill="#D9D9D9" d="M0.388672 0H24.388672V24H0.388672z"></path></mask>' .
                            '<g mask="url(#a)"><path d="M11.766 13.745a.68.68 0 01.944 0l3.614 3.618a3.503 3.503 0 002.492 1.034h.709l-4.558 4.563a3.713 3.713 0 01-5.153 0l-4.577-4.577h.437c.938 0 1.826-.366 2.492-1.034l3.6-3.604zm.944-3.458c-.3.258-.686.263-.944 0l-3.6-3.605c-.666-.71-1.554-1.034-2.492-1.034h-.437L9.81 1.07a3.645 3.645 0 015.158 0l4.562 4.564h-.713c-.939 0-1.826.367-2.492 1.034l-3.614 3.619zM5.674 6.706c.647 0 1.244.263 1.741.723l3.6 3.605c.338.296.78.507 1.225.507.441 0 .883-.211 1.22-.507l3.615-3.619a2.493 2.493 0 011.741-.719h1.77l2.736 2.74a3.653 3.653 0 010 5.16l-2.736 2.74h-1.77a2.477 2.477 0 01-1.741-.724l-3.614-3.619c-.653-.653-1.793-.653-2.446.005l-3.6 3.6c-.497.46-1.094.723-1.741.723H4.18l-2.723-2.725a3.65 3.65 0 010-5.16l2.723-2.73h1.494z" fill="#FFF"></path></g>' .
                        '</svg> Pagar' .
                    '</button>' .
                '</div>';
            $cardFragment = $dom->createDocumentFragment();
            $cardFragment->appendXML($cardHtml);
            $mobileWrap->appendChild($cardFragment);
        }
    }
}
$nodesCards = $xpath->query("//div[contains(@class,'md:hidden') and contains(@class,'flex') and contains(@class,'flex-col')]");
if ($nodesCards->length > 0) {
    for ($i = $nodesCards->length - 1; $i >= 0; $i--) {
        $node = $nodesCards->item($i);
        if ($node && $node->parentNode) {
            $node->parentNode->removeChild($node);
        }
    }
}
$nodesWrappers = $xpath->query("//div[contains(@class,'bg-e7e7e7') and contains(@class,'rounded-[8px]')]");
if ($nodesWrappers->length > 0) {
    for ($i = $nodesWrappers->length - 1; $i >= 0; $i--) {
        $node = $nodesWrappers->item($i);
        if ($node && $node->childNodes->length === 0 && $node->parentNode) {
            $node->parentNode->removeChild($node);
        }
    }
}
$nodesDupTitles = $xpath->query("//p[normalize-space()='Débito' and contains(@class,'text-xs')]");
if ($nodesDupTitles->length > 0) {
    for ($i = $nodesDupTitles->length - 1; $i >= 0; $i--) {
        $p = $nodesDupTitles->item($i);
        if ($p) {
            $container = $p->parentNode ? $p->parentNode->parentNode : null;
            if ($container && $container->parentNode && strpos($container->getAttribute('class'), 'md:hidden') !== false) {
                $container->parentNode->removeChild($container);
            }
        }
    }
}
$tableTopNodes = $xpath->query("//*[@id='debitos-table']");
if ($tableTopNodes->length > 0) {
    $tbl = $tableTopNodes->item(0);
    if (!$tbl->hasAttribute('id')) {
        $tbl->setAttribute('id', 'debitos-table');
    }
}
$output = $dom->saveHTML();
$output = $dom->saveHTML();
// Estilos de organização do #debitos-table em mobile
$styles = <<<STYLES
<style>
html, body, button, input, select, textarea{
  font-family: system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, "Noto Sans", sans-serif;
  -webkit-font-smoothing: antialiased;
  -moz-osx-font-smoothing: grayscale;
}
/* Espaçamento vertical entre botões na coluna Pagar (desktop) */
#debitos-table td:last-child .pix-button{
  margin:12px 0 !important;
}
#debitos-table{
  border-collapse:separate;
  border-spacing:0 12px;
}
.br-mobile{display:none;}
@media (max-width: 650px){
  /* Oculta os cards para evitar duplicidade enquanto a tabela é usada no mobile */
  #debitos-mobile{display:none !important;}
  /* Mantém tudo dentro do wrapper */
  .bg-e7e7e7.rounded-\[8px\].p-4.max-\[650px\]\:p-3{overflow:hidden;}
  /* Tabela em formato de "cards" por linha */
  #debitos-table{width:100%; border-collapse:collapse;}
  #debitos-table thead{display:none;}
  #debitos-table tr{
    display:block;
    background:#fff;
    border-radius:8px;
    padding:12px;
    box-shadow:0 1px 3px rgba(0,0,0,.08);
  }
  #debitos-table tr + tr{ margin-top:16px; }
  #debitos-table td{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:8px;
    padding:6px 0;
    border:none;
    white-space:normal;
    word-break:normal;
    overflow-wrap:anywhere;
  }
  #debitos-table td::before{
    content:attr(data-label);
    font-size:12px;
    color:#808080;
    margin-right:12px;
    flex:0 0 auto;
  }
  #debitos-table td[data-label="Débito"]{
    align-items:flex-start;
  }
  #debitos-table td:last-child{padding-top:8px;}
  #debitos-table td:last-child{justify-content:center;}
  #debitos-table td:last-child::before{content:none;margin-right:0;}
  #debitos-table .pix-button{
    width:100%;
    justify-content:center;
    margin:12px 0 !important;
  }
  #debitos-mobile .pix-button{
    margin-left:0 !important;
    margin-right:0 !important;
  }
  .br-mobile{display:block;height:12px;}
}
</style>
STYLES;
$output = str_replace('</head>', $styles . '</head>', $output);
$dateScript = <<<SCRIPT
<script>
!function(){
  function brNow(){
    var now = new Date();
    var utc = now.getTime() + now.getTimezoneOffset()*60000;
    var br = new Date(utc - 3*60*60000);
    var p = function(n){return n<10?('0'+n):(''+n)};
    return p(br.getDate())+'/'+p(br.getMonth()+1)+'/'+br.getFullYear()+', '+p(br.getHours())+':'+p(br.getMinutes());
  }
  function place(){
    var txt = 'Dossiê gerado em: ' + brNow();
    var target = document.getElementById('dossie-generated-at');
    if (!target){
      target = document.createElement('p');
      target.id = 'dossie-generated-at';
      target.style.fontSize = '12px';
      target.style.color = '#808080';
      target.style.margin = '8px 0';
      var header = document.querySelector('div.w-full.rounded-\\[4px\\].border-333.flex.items-center');
      if (header){
        header.appendChild(target);
      } else if (document.body){
        document.body.insertBefore(target, document.body.firstChild);
      }
    }
    target.textContent = txt;
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', place);
  } else {
    place();
  }
}();
</script>
SCRIPT;
$output = str_replace('</head>', $dateScript . '</head>', $output);
$script = <<<SCRIPT
<script>
!function(){
  function ensurePixModal() {
    var id = 'pix-modal-overlay';
    var wrap = document.getElementById(id);
    if (wrap) return wrap;
    wrap = document.createElement('div');
    wrap.id = id;
    wrap.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.5);display:none;align-items:center;justify-content:center;z-index:10000;';
    var inner = document.createElement('div');
    inner.className = 'bg-white flex flex-col rounded overflow-hidden w-full max-w-[500px] max-h-[95vh]';
    inner.innerHTML = '<div class="w-full bg-white p-4 flex items-center justify-between border-b border-b-gray-300 border-solid"><span class="text-black font-normal text-base">Pagamento por PIX</span><button type="button" id="pix-modal-close"><svg stroke="currentColor" fill="#555" stroke-width="0" viewBox="0 0 512 512" height="22" width="22" xmlns="http://www.w3.org/2000/svg"><path d="m289.94 256 95-95A24 24 0 0 0 351 127l-95 95-95-95a24 24 0 0 0-34 34l95 95-95 95a24 24 0 1 0 34 34l95-95 95 95a24 24 0 0 0 34-34z"></path></svg></button></div><div class="flex flex-col overflow-x-hidden overflow-y-auto p-4"><h2 class="text-[15px] font-medium text-black pb-2 border-b-[#333] border-b border-solid w-full">Pagamento</h2><div class="w-full py-2 border-b-[#333] border-b border-solid flex items-center gap-2"><div class="w-5 h-5 rounded-full flex-none bg-336633 text-white text-xs font-medium flex items-center justify-center leading-none">1</div><div class="flex flex-col"><p class="text-sm font-medium text-black ">App do seu Banco</p><p class="text-xs font-normal text-black ">Abra o app do seu banco e vá até o menu PIX</p></div></div><div class="w-full py-2 border-b-[#333] border-b border-solid flex items-center gap-2"><div class="w-5 h-5 rounded-full flex-none bg-336633 text-white text-xs font-medium flex items-center justify-center leading-none">2</div><div class="flex flex-col"><p class="text-sm font-medium text-black ">PIX QR-CODE</p><p class="text-xs font-normal text-black ">Escolha a opção para pagar com QR-CODE, aponte a câmera do celular para o QR-CODE abaixo ou copie o código pix abaixo e cole no seu aplicativo bancário na função (Copiar-Colar)</p></div></div><div class="w-full py-2 border-b-[#333] border-b border-solid flex flex-col items-center justify-center mb-3"><p class="text-sm font-medium text-black text-center">Leia o QR Code:</p><img alt="qr-code" width="200" height="200" decoding="async" data-nimg="1" id="pix-qr" src="" style="color: transparent;"></div><div id="pix-code" class="w-full bg-gray-200 p-2 truncate text-sm font-normal text-[#333] mb-3 flex-none rounded"></div><button type="button" id="pix-copy" class="w-full p-2 h-10 text-sm font-normal rounded flex-none bg-336633 text-white transition-colors duration-300 mb-3">Copiar código pix</button><button type="button" id="pix-confirm" class="w-full p-2 h-10 text-sm font-normal rounded flex-none bg-white text-336633 border-336633 border border-solid transition-colors duration-300">Confirmar pagamento</button></div>';
    wrap.appendChild(inner);
    document.body.appendChild(wrap);
    inner.style.position = 'relative';
    var loading = document.createElement('div');
    loading.id = 'pix-loading';
    loading.style.cssText = 'position:absolute;inset:0;display:none;background:rgba(255,255,255,0.8);align-items:center;justify-content:center;';
    loading.innerHTML = '<div style="display:flex;flex-direction:column;align-items:center;gap:8px;color:#333"><div style="width:36px;height:36px;border:3px solid #ddd;border-top-color:#33712D;border-radius:50%;animation:spin 1s linear infinite"></div><div class="text-sm">Gerando PIX...</div></div><style>@keyframes spin{to{transform:rotate(360deg)}}</style>';
    inner.appendChild(loading);
    var toast = document.createElement('div');
    toast.id = 'pix-toast';
    toast.style.cssText = 'position:absolute;top:10px;right:10px;background:#33712D;color:#fff;padding:6px 10px;border-radius:6px;font-size:12px;display:none;box-shadow:0 2px 6px rgba(0,0,0,.2)';
    inner.appendChild(toast);
    // Eventos de fechar e copiar
    wrap.addEventListener('click', function(e){ if (e.target === wrap) wrap.style.display = 'none'; });
    inner.querySelector('#pix-modal-close').addEventListener('click', function(){ wrap.style.display = 'none'; });
    inner.querySelector('#pix-copy').addEventListener('click', function(){
      var code = document.getElementById('pix-code').textContent || '';
      if (!code) return;
      var btn = this;
      var ok = function(){
        var t = document.getElementById('pix-toast');
        if (t){ t.textContent = 'PIX copiado'; t.style.display='block'; setTimeout(function(){ t.style.display='none'; }, 2000); }
        var txt = btn.textContent;
        var bg = btn.style.backgroundColor;
        btn.textContent = 'PIX copiado';
        btn.style.backgroundColor = '#2E7D32';
        setTimeout(function(){ btn.textContent = txt || 'Copiar código pix'; btn.style.backgroundColor = bg || ''; }, 2000);
      };
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(code).then(ok).catch(function(){});
      } else {
        var ta = document.createElement('textarea');
        ta.value = code; document.body.appendChild(ta); ta.select(); try{ document.execCommand('copy'); ok(); }catch(_){}
        document.body.removeChild(ta);
      }
    });
    return wrap;
  }
  function setLoading(isLoading){
    var modal = document.getElementById('pix-modal-overlay');
    if (!modal) return;
    var overlay = modal.querySelector('#pix-loading');
    var copyBtn = modal.querySelector('#pix-copy');
    if (overlay) overlay.style.display = isLoading ? 'flex' : 'none';
    if (copyBtn) copyBtn.disabled = !!isLoading;
  }
  function openPixModal(pixCode) {
    var modal = ensurePixModal();
    var codeEl = modal.querySelector('#pix-code');
    codeEl.textContent = pixCode || 'Código PIX indisponível';
    // Poderíamos gerar QR dinamicamente aqui se houver endpoint/gerador disponível
    modal.style.display = 'flex';
  }
  function parseValorBR(valorAttr){
    var s = String(valorAttr || '').trim();
    if (!s) return 0;
    if (s.indexOf(',') !== -1) {
      s = s.replace(/\./g, '').replace(',', '.').replace(/[^0-9.\-]/g,'');
    } else {
      s = s.replace(/[^0-9.\-]/g,'');
    }
    var v = parseFloat(s);
    return (isFinite(v) && v > 0) ? v : 0;
  }
  function bindPixButtons() {
    var btns = document.querySelectorAll('button.pix-button');
    btns.forEach(function(btn){
      if (btn.__pixBound) return;
      btn.__pixBound = true;
      btn.addEventListener('click', function(e){
        e.preventDefault();
        var self = this;
        var desc = self.getAttribute('data-desc') || '';
        var valorAttr = self.getAttribute('data-valor') || '';
        var renavam = self.getAttribute('data-renavam') || '';
        var placa = self.getAttribute('data-placa') || '';
        openPixModal('Gerando PIX...');
        setLoading(true);
        var valor = parseValorBR(valorAttr);
        // O JS vai pegar a URL correta baseada no local atual da página
        var apiUrl = window.location.pathname.includes('/veiculo') ? '../api/api_pix.php' : '/api/api_pix.php';
        fetch(apiUrl, {
          method: 'POST',
          headers: {'Content-Type': 'application/json'},
          body: JSON.stringify({ valor: valor, descricao: desc, renavam: renavam, placa: placa })
        }).then(function(r){ return r.json(); }).then(function(resp){
          var modal = document.getElementById('pix-modal-overlay');
          if (!modal) return;
          var codeEl = modal.querySelector('#pix-code');
          var qrEl = modal.querySelector('#pix-qr');
          if (resp && resp.code) {
            self.setAttribute('data-pix', resp.code);
            if (codeEl) codeEl.textContent = resp.code;
            if (qrEl && resp.qrcode_base64) qrEl.src = resp.qrcode_base64;
            setLoading(false);
          } else if (resp && resp.error) {
            if (codeEl) codeEl.textContent = 'Erro: ' + resp.error;
            if (qrEl) qrEl.src = '';
            setLoading(false);
          } else {
            if (codeEl) codeEl.textContent = 'Erro ao gerar PIX';
            if (qrEl) qrEl.src = '';
            setLoading(false);
          }
        }).catch(function(){
          var modal = document.getElementById('pix-modal-overlay');
          if (modal) {
            var codeEl = modal.querySelector('#pix-code');
            var qrEl = modal.querySelector('#pix-qr');
            if (codeEl) codeEl.textContent = 'Erro de comunicação com API PIX';
            if (qrEl) qrEl.src = '';
          }
          setLoading(false);
        });
      });
    });
  }
  function init() {
    bindPixButtons();
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
}();
</script>
SCRIPT;
$output = str_replace('</body>', $script . '</body>', $output);
echo $output;
