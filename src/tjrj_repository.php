<?php

// Consultas ao Portal do Conhecimento do TJRJ, usadas pela Tabela do Site do
// TJRJ (public/tjrj.php). Não usa o banco: tudo vem da API pública do tribunal.
//
// Na ordem cronológica (2) o TJ separa as entidades "filhas" (autarquias,
// fundações etc.) da entidade principal; a tela mostra tudo junto, então os
// precatórios das filhas são buscados um a um e somados aos do ente escolhido.
//
// As funções que falam com o TJ recebem o cliente HTTP como parâmetro para que
// o teste possa trocá-lo por respostas fixas (não há acesso ao TJ em teste).

const TJRJ_API_BASE       = 'https://www3.tjrj.jus.br/PortalConhecimento/api/precatorios';
const TJRJ_TIMEOUT        = 10;
const TJRJ_TAMANHO_PAGINA = 50000;

const TJRJ_ORDEM_CRONOLOGICA = 2;

const TJRJ_ORDENS = [
    1 => 'Consulta Precatórios Pagos',
    2 => 'Consulta Ordem Cronológica',
    3 => 'Consulta Ordem Prioridade',
    4 => 'Consulta Ordem de Rateio (Lista Única de Ordem Cronológica)',
    5 => 'Consulta Ordem Cronológica de Precatórios Parcelados',
];

// Só estas estão liberadas na tela; as outras ainda não têm colunas definidas.
const TJRJ_ORDENS_HABILITADAS = [2, 4];

// GET que devolve o JSON já decodificado (arrays associativos). Lança
// RuntimeException quando o TJ não responde ou responde algo que não é JSON.
function tjrj_http_get_json($url) {
    $contexto = stream_context_create(['http' => ['timeout' => TJRJ_TIMEOUT]]);
    $corpo = @file_get_contents($url, false, $contexto);
    if ($corpo === false) {
        throw new RuntimeException("O site do TJRJ não respondeu ({$url}).");
    }
    $dados = json_decode($corpo, true);
    if (!is_array($dados)) {
        throw new RuntimeException("Resposta inválida do site do TJRJ ({$url}).");
    }
    return $dados;
}

function tjrj_sanitize_ordem($raw) {
    $raw = trim((string)$raw);
    if ($raw === '') {
        return TJRJ_ORDEM_CRONOLOGICA;
    }
    if (!ctype_digit($raw) || !in_array((int)$raw, TJRJ_ORDENS_HABILITADAS, true)) {
        throw new InvalidArgumentException('Tipo de consulta inválido.');
    }
    return (int)$raw;
}

function tjrj_sanitize_ente($raw) {
    $raw = trim((string)$raw);
    if ($raw === '') {
        throw new InvalidArgumentException('Selecione um Ente.');
    }
    if (!ctype_digit($raw) || (int)$raw <= 0) {
        throw new InvalidArgumentException('Ente inválido.');
    }
    return (int)$raw;
}

// Entes principais (sem entidade pai), para o select da tela.
function tjrj_listar_entes_raiz(?callable $httpGet = null) {
    $httpGet = $httpGet ?? 'tjrj_http_get_json';
    $entes = [];
    foreach ($httpGet(TJRJ_API_BASE . '/entDev') as $ente) {
        if (!isset($ente['IdEntidadePai']) && isset($ente['IdEntidade'])) {
            $entes[] = ['id' => (int)$ente['IdEntidade'], 'nome' => (string)$ente['EntidadeDevedora']];
        }
    }
    usort($entes, function ($a, $b) {
        return strcmp($a['nome'], $b['nome']);
    });
    return $entes;
}

function tjrj_url_ordem_pagamento($enteId, $ordem) {
    return TJRJ_API_BASE . '/ordemPagamento?' . http_build_query([
        'idEntidadeDevedora' => $enteId,
        'ordemPagamento'     => $ordem,
        'pagina'             => 1,
        'regimeEntidade'     => 'O',
        'tamanhoPagina'      => TJRJ_TAMANHO_PAGINA,
    ]);
}

// Lista de precatórios de uma resposta de ordemPagamento (vazia se não houver).
function tjrj_extrair_precatorios(array $resposta) {
    $lista = $resposta['Resultado']['Precatorios'] ?? [];
    return is_array($lista) ? array_values($lista) : [];
}

// Precatórios do ente na ordem escolhida, sempre como lista plana. Na ordem
// cronológica inclui os das entidades filhas do ente.
function tjrj_buscar_precatorios($enteId, $ordem, ?callable $httpGet = null) {
    $httpGet = $httpGet ?? 'tjrj_http_get_json';

    $precatorios = tjrj_extrair_precatorios($httpGet(tjrj_url_ordem_pagamento($enteId, $ordem)));

    if ($ordem !== TJRJ_ORDEM_CRONOLOGICA) {
        return $precatorios;
    }

    $entes = $httpGet(TJRJ_API_BASE . '/entDev?ordemPagamento=' . TJRJ_ORDEM_CRONOLOGICA);
    foreach ($entes as $ente) {
        if (isset($ente['IdEntidadePai']) && (int)$ente['IdEntidadePai'] === (int)$enteId) {
            $filha = $httpGet(tjrj_url_ordem_pagamento((int)$ente['IdEntidade'], $ordem));
            $precatorios = array_merge($precatorios, tjrj_extrair_precatorios($filha));
        }
    }

    return $precatorios;
}
