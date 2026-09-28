<?php

// Verificação da Tabela do Sistema (Precabot) e da Tabela do Site do TJRJ.
//
// Não precisa de banco nem de rede: o Precabot roda contra um SQLite em
// memória com uma precatoriodetalhe mínima, e o TJRJ recebe um cliente HTTP
// falso com respostas no formato da API do tribunal.
//
// Uso:
//   php tests/precabot_tjrj_smoke.php

define('PRECABOT_TB_DETALHE', 'precatoriodetalhe');

require __DIR__ . '/../src/precabot_repository.php';
require __DIR__ . '/../src/tjrj_repository.php';

$falhas = 0;
$total = 0;

function verificar($descricao, $condicao, $detalhe = '') {
    global $falhas, $total;
    $total++;
    if ($condicao) {
        echo "  ok   {$descricao}\n";
        return;
    }
    $falhas++;
    echo "  FALHA {$descricao}" . ($detalhe !== '' ? " — {$detalhe}" : '') . "\n";
}

function lanca($classe, callable $fn) {
    try {
        $fn();
    } catch (Throwable $e) {
        return $e instanceof $classe;
    }
    return false;
}

// ---------------------------------------------------------------------------
echo "Precabot — filtros\n";
// ---------------------------------------------------------------------------

$f = precabot_parse_filtros(['escopo' => 'ente', 'ente_id' => '66', 'apenas_pendentes' => '1']);
verificar('escopo por ente com id', $f['escopo'] === 'ente' && $f['ente_id'] === 66 && $f['apenas_pendentes'] === true);

$f = precabot_parse_filtros(['escopo' => 'erj', 'apenas_pendentes' => '0']);
verificar('escopo ERJ dispensa ente', $f['ente_id'] === null && $f['apenas_pendentes'] === false);

verificar('escopo por ente sem ente é rejeitado',
    lanca(InvalidArgumentException::class, function () { precabot_parse_filtros(['escopo' => 'ente']); }));
verificar('ente não numérico é rejeitado',
    lanca(InvalidArgumentException::class, function () { precabot_parse_filtros(['escopo' => 'ente', 'ente_id' => '1 OR 1=1']); }));
verificar('escopo desconhecido é rejeitado',
    lanca(InvalidArgumentException::class, function () { precabot_parse_filtros(['escopo' => 'tudo']); }));

// ---------------------------------------------------------------------------
echo "Precabot — consulta\n";
// ---------------------------------------------------------------------------

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE precatoriodetalhe (Precatorio TEXT, ente_id INTEGER, Negociador INTEGER, '
    . implode(', ', array_map(function ($c) { return "{$c} TEXT"; }, array_diff(PRECABOT_COLUNAS, ['Precatorio']))) . ')');

$linhas = [
    // Precatorio, ente_id, Negociador, prec_pg
    ['ERJ-1',  PRECABOT_ENTE_ERJ,  10, null],
    ['ERJ-2',  PRECABOT_ENTE_ERJ,  11, '1'],
    ['TRF-1',  PRECABOT_ENTE_TRF2, 10, null],
    ['MUN-1',  66,                 10, null],
    ['MUN-2',  66,                 11, null],
    ['MUN-3',  66,                 10, '1'],
    ['OUT-1',  70,                 11, null],
];
$ins = $pdo->prepare('INSERT INTO precatoriodetalhe (Precatorio, ente_id, Negociador, prec_pg) VALUES (?, ?, ?, ?)');
foreach ($linhas as $l) {
    $ins->execute($l);
}

function precatorios_de(array $dados) {
    $ids = array_column($dados, 'Precatorio');
    sort($ids);
    return implode(',', $ids);
}

$casos = [
    ['ente 66',                    ['escopo' => 'ente', 'ente_id' => '66'], null, null, 'MUN-1,MUN-2,MUN-3'],
    ['ente 66 só pendentes',       ['escopo' => 'ente', 'ente_id' => '66', 'apenas_pendentes' => 1], null, null, 'MUN-1,MUN-2'],
    ['ERJ',                        ['escopo' => 'erj'], null, null, 'ERJ-1,ERJ-2'],
    ['TRF2',                       ['escopo' => 'trf2'], null, null, 'TRF-1'],
    ['todos exceto ERJ e TRF2',    ['escopo' => 'todos_exceto'], null, null, 'MUN-1,MUN-2,MUN-3,OUT-1'],
    ['consultor só vê os seus',    ['escopo' => 'ente', 'ente_id' => '66'], 2, 10, 'MUN-1,MUN-3'],
    ['outro perfil vê todos',      ['escopo' => 'ente', 'ente_id' => '66'], 1, 10, 'MUN-1,MUN-2,MUN-3'],
    ['ente sem precatório: lista vazia', ['escopo' => 'ente', 'ente_id' => '999'], null, null, ''],
];
foreach ($casos as $c) {
    list($descricao, $input, $perfil, $usuario, $esperado) = $c;
    $obtido = precatorios_de(precabot_listar($pdo, precabot_parse_filtros($input), $perfil, $usuario));
    verificar($descricao, $obtido === $esperado, "esperado [{$esperado}], obtido [{$obtido}]");
}

$uma = precabot_listar($pdo, precabot_parse_filtros(['escopo' => 'trf2']), null, null)[0];
verificar('devolve todas as colunas da tela', array_keys($uma) === PRECABOT_COLUNAS);

// ---------------------------------------------------------------------------
echo "TJRJ — filtros\n";
// ---------------------------------------------------------------------------

verificar('ordem padrão é a cronológica', tjrj_sanitize_ordem('') === 2);
verificar('ordem de rateio aceita', tjrj_sanitize_ordem('4') === 4);
verificar('ordem desabilitada é rejeitada',
    lanca(InvalidArgumentException::class, function () { tjrj_sanitize_ordem('1'); }));
verificar('ente vazio é rejeitado',
    lanca(InvalidArgumentException::class, function () { tjrj_sanitize_ente(''); }));
verificar('ente não numérico é rejeitado',
    lanca(InvalidArgumentException::class, function () { tjrj_sanitize_ente('1&x=2'); }));

// ---------------------------------------------------------------------------
echo "TJRJ — consultas (API falsa)\n";
// ---------------------------------------------------------------------------

$entes = [
    ['IdEntidade' => 66,  'EntidadeDevedora' => 'Município do Rio de Janeiro', 'IdEntidadePai' => null],
    ['IdEntidade' => 1,   'EntidadeDevedora' => 'Estado do Rio de Janeiro',    'IdEntidadePai' => null],
    ['IdEntidade' => 500, 'EntidadeDevedora' => 'Previ-Rio',                   'IdEntidadePai' => 66],
    ['IdEntidade' => 501, 'EntidadeDevedora' => 'Comlurb',                     'IdEntidadePai' => 66],
    ['IdEntidade' => 600, 'EntidadeDevedora' => 'Rioprevidência',              'IdEntidadePai' => 1],
];
$precatoriosPorEnte = [
    66  => [['NumeroPrecatorio' => 'P-66-a'], ['NumeroPrecatorio' => 'P-66-b']],
    500 => [['NumeroPrecatorio' => 'P-500']],
    501 => [],
    600 => [['NumeroPrecatorio' => 'P-600']],
];

$chamadas = [];
$httpFalso = function ($url) use ($entes, $precatoriosPorEnte, &$chamadas) {
    $chamadas[] = $url;
    $query = [];
    parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
    if (strpos($url, '/entDev') !== false) {
        return $entes;
    }
    $id = (int)$query['idEntidadeDevedora'];
    return ['Resultado' => ['Precatorios' => $precatoriosPorEnte[$id] ?? null]];
};

$raiz = tjrj_listar_entes_raiz($httpFalso);
verificar('entes raiz, sem filhas e em ordem alfabética',
    array_column($raiz, 'id') === [1, 66], json_encode($raiz));

$chamadas = [];
$lista = tjrj_buscar_precatorios(66, 2, $httpFalso);
verificar('ordem cronológica junta as filhas do ente',
    array_column($lista, 'NumeroPrecatorio') === ['P-66-a', 'P-66-b', 'P-500'], json_encode($lista));
verificar('não busca filhas de outro ente',
    count(array_filter($chamadas, function ($u) { return strpos($u, 'idEntidadeDevedora=600') !== false; })) === 0);
verificar('a URL leva ente, ordem e tamanho da página',
    strpos($chamadas[0], 'idEntidadeDevedora=66&ordemPagamento=2&pagina=1&regimeEntidade=O&tamanhoPagina=50000') !== false,
    $chamadas[0]);

$chamadas = [];
$lista = tjrj_buscar_precatorios(66, 4, $httpFalso);
verificar('rateio não busca filhas e já devolve lista plana',
    count($chamadas) === 1 && array_column($lista, 'NumeroPrecatorio') === ['P-66-a', 'P-66-b']);

verificar('ente sem precatórios devolve lista vazia', tjrj_buscar_precatorios(999, 4, $httpFalso) === []);

$foraDoAr = function ($url) { throw new RuntimeException('sem rede'); };
verificar('TJ fora do ar vira RuntimeException',
    lanca(RuntimeException::class, function () use ($foraDoAr) { tjrj_buscar_precatorios(66, 2, $foraDoAr); }));

// ---------------------------------------------------------------------------

echo "\n" . ($falhas === 0 ? "OK" : "FALHOU") . ": {$total} verificações, {$falhas} falha(s).\n";
exit($falhas === 0 ? 0 : 1);
