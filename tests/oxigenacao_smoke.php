<?php

// Harness de verificação do repositório de oxigenação.
//
// Não há banco MySQL no ambiente de desenvolvimento, então este script carrega
// os CSVs exportados das tabelas em um SQLite em memória e roda as mesmas
// funções do repositório contra eles. Por isso o SQL do repositório evita
// construções específicas de um único banco (sem CONCAT, sem DATE_ADD).
//
// Uso:
//   php tests/oxigenacao_smoke.php <diretorio-com-os-csvs>
//
// O diretório deve conter os arquivos exportados (o nome pode ter prefixo):
//   *historico_contato*.csv, *status_precatorio*.csv, *view_precatorio_detalhe*.csv
//
// Os CSVs NÃO são versionados: contêm nome e CPF de credores.

$dir = $argv[1] ?? null;
if ($dir === null || !is_dir($dir)) {
    fwrite(STDERR, "Aviso: diretório de CSVs não informado ou inexistente. Testes pulados.\n");
    fwrite(STDERR, "Uso: php tests/oxigenacao_smoke.php <diretorio-com-os-csvs>\n");
    exit(0);
}

define('OXI_TB_HISTORICO', 'HistoricoContato');
define('OXI_TB_STATUS', 'StatusPrecatorio');
define('OXI_TB_DETALHE', 'precatoriodetalhe');

require __DIR__ . '/../src/oxigenacao_repository.php';

// ---------------------------------------------------------------------------
// Infra mínima de teste
// ---------------------------------------------------------------------------

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

function achar_csv($dir, $trecho) {
    foreach (glob($dir . '/*.csv') as $arquivo) {
        if (strpos(basename($arquivo), $trecho) !== false) {
            return $arquivo;
        }
    }
    throw new RuntimeException("CSV com \"{$trecho}\" no nome não encontrado em {$dir}");
}

// Cria a tabela com todas as colunas do CSV como TEXT e carrega as linhas.
function carregar_csv(PDO $pdo, $tabela, $arquivo) {
    $fh = fopen($arquivo, 'r');
    $colunas = fgetcsv($fh);
    if (!$colunas) {
        throw new RuntimeException("CSV vazio: {$arquivo}");
    }
    $colunas[0] = preg_replace('/^\xEF\xBB\xBF/', '', $colunas[0]);

    $defs = implode(', ', array_map(function ($c) { return '"' . $c . '" TEXT'; }, $colunas));
    $pdo->exec("CREATE TABLE \"{$tabela}\" ({$defs})");

    $nomes = implode(', ', array_map(function ($c) { return '"' . $c . '"'; }, $colunas));
    $ph = implode(',', array_fill(0, count($colunas), '?'));
    $stmt = $pdo->prepare("INSERT INTO \"{$tabela}\" ({$nomes}) VALUES ({$ph})");

    $pdo->beginTransaction();
    $linhas = 0;
    while (($valores = fgetcsv($fh)) !== false) {
        if ($valores === [null] || $valores === []) {
            continue;
        }
        // Linhas truncadas na exportação: completa com nulo em vez de descartar.
        $valores = array_pad(array_slice($valores, 0, count($colunas)), count($colunas), null);
        $valores = array_map(function ($v) {
            return ($v === 'NULL' || $v === '') ? null : $v;
        }, $valores);
        $stmt->execute($valores);
        $linhas++;
    }
    $pdo->commit();
    fclose($fh);

    return $linhas;
}

// ---------------------------------------------------------------------------
// Carga
// ---------------------------------------------------------------------------

$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$qtdHistorico = carregar_csv($pdo, OXI_TB_HISTORICO, achar_csv($dir, 'historico_contato'));
$qtdStatus    = carregar_csv($pdo, OXI_TB_STATUS, achar_csv($dir, 'status_precatorio'));
$qtdDetalhe   = carregar_csv($pdo, OXI_TB_DETALHE, achar_csv($dir, 'view_precatorio_detalhe'));

echo "Carga: {$qtdHistorico} contatos, {$qtdStatus} status, {$qtdDetalhe} precatórios.\n\n";

$semTentativa = oxigenacao_status_sem_tentativa($pdo);
sort($semTentativa);

echo "Família \"Sem Tentativa\": " . implode(', ', $semTentativa) . "\n\n";

$filtrosBase = [
    'data_inicio' => '2000-01-01',
    'data_fim'    => '2100-12-31',
];

function filtros(array $extra = []) {
    return oxigenacao_parse_filtros(array_merge([
        'data_inicio' => '2000-01-01',
        'data_fim'    => '2100-12-31',
    ], $extra), isset($extra['data_ref']) ? 'foto' : 'periodo');
}

// ---------------------------------------------------------------------------
// Família "Sem Tentativa"
// ---------------------------------------------------------------------------

echo "Família de status:\n";
verificar('inclui o status pai 1', in_array(1, $semTentativa, true));
verificar('inclui os filhos 65, 70 e 71',
    in_array(65, $semTentativa, true) && in_array(70, $semTentativa, true) && in_array(71, $semTentativa, true));
verificar('não inclui status de negociação (ex.: 19, 20)',
    !in_array(19, $semTentativa, true) && !in_array(20, $semTentativa, true));

// ---------------------------------------------------------------------------
// Eventos de oxigenação
// ---------------------------------------------------------------------------

echo "\nEventos de oxigenação (período aberto):\n";
$eventos = oxigenacao_eventos($pdo, filtros());
echo "  " . count($eventos) . " precatórios oxigenados.\n";

verificar('encontrou eventos', count($eventos) > 0);

$ids = array_column($eventos, 'precatorio_id');
verificar('cada precatório aparece uma única vez',
    count($ids) === count(array_unique($ids)),
    count($ids) . ' linhas para ' . count(array_unique($ids)) . ' precatórios');

$destinosInvalidos = array_filter($eventos, function ($e) use ($semTentativa) {
    return in_array((int)$e['StatusDestinoId'], $semTentativa, true);
});
verificar('nenhum evento tem destino na família Sem Tentativa', count($destinosInvalidos) === 0);

// Conferência independente: recalcula a primeira oxigenação direto do histórico.
$esperado = [];
$stmt = $pdo->query('SELECT PrecatorioId, DataContato, ResultContatoId, historicoContato_id FROM ' . OXI_TB_HISTORICO);
foreach ($stmt->fetchAll() as $linha) {
    if ($linha['ResultContatoId'] === null || in_array((int)$linha['ResultContatoId'], $semTentativa, true)) {
        continue;
    }
    $prec = $linha['PrecatorioId'];
    if (!isset($esperado[$prec]) || $linha['DataContato'] < $esperado[$prec]) {
        $esperado[$prec] = $linha['DataContato'];
    }
}

$divergentes = [];
foreach ($eventos as $evento) {
    $prec = (string)$evento['precatorio_id'];
    $dataEsperada = substr((string)($esperado[$prec] ?? ''), 0, 10);
    if ($dataEsperada !== $evento['DataOxigenacao']) {
        $divergentes[] = $prec . ': ' . $evento['DataOxigenacao'] . ' != ' . $dataEsperada;
    }
}
verificar('data de oxigenação bate com o cálculo independente',
    count($divergentes) === 0, implode(' | ', array_slice($divergentes, 0, 3)));

// Só entram precatórios que existem na view (JOIN), então o conjunto é subconjunto do esperado.
$semNaView = array_diff(array_keys($esperado), array_map('strval', $ids));
echo "  " . count($semNaView) . " precatórios oxigenados no histórico não estão na amostra da view (esperado: amostras parciais).\n";

$comRotuloFallback = array_filter($eventos, function ($e) {
    return strpos($e['StatusDestino'], 'Status #') === 0;
});
verificar('status removidos da tabela recebem rótulo de fallback',
    count($comRotuloFallback) > 0,
    'nenhum evento com status órfão nesta amostra (aceitável se a amostra não tiver)');

// ---------------------------------------------------------------------------
// Filtros
// ---------------------------------------------------------------------------

echo "\nFiltros:\n";

$enteAlvo = (int)$eventos[0]['ente_id'];
$filtrados = oxigenacao_eventos($pdo, filtros(['ente_id' => [$enteAlvo]]));
$foraDoEnte = array_filter($filtrados, function ($e) use ($enteAlvo) {
    return (int)$e['ente_id'] !== $enteAlvo;
});
verificar('filtro de Ente devolve só o ente pedido',
    count($filtrados) > 0 && count($foraDoEnte) === 0);
verificar('filtro de Ente reduz o conjunto', count($filtrados) <= count($eventos));

$consultorAlvo = (int)$eventos[0]['ConsultorId'];
$porConsultor = oxigenacao_eventos($pdo, filtros(['consultor_id' => [$consultorAlvo]]));
$foraDoConsultor = array_filter($porConsultor, function ($e) use ($consultorAlvo) {
    return (int)$e['ConsultorId'] !== $consultorAlvo;
});
verificar('filtro de Consultor devolve só o consultor pedido',
    count($porConsultor) > 0 && count($foraDoConsultor) === 0);

$orcamentoAlvo = (int)$eventos[0]['Orcamento'];
$porOrcamento = oxigenacao_eventos($pdo, filtros(['orcamento' => [$orcamentoAlvo]]));
$foraDoOrcamento = array_filter($porOrcamento, function ($e) use ($orcamentoAlvo) {
    return (int)$e['Orcamento'] !== $orcamentoAlvo;
});
verificar('filtro de Orçamento devolve só o orçamento pedido',
    count($porOrcamento) > 0 && count($foraDoOrcamento) === 0);

$porValor = oxigenacao_eventos($pdo, filtros(['valor_min' => '100000', 'valor_max' => '500000']));
$foraDaFaixa = array_filter($porValor, function ($e) {
    return $e['ValorPrec'] < 100000 || $e['ValorPrec'] > 500000;
});
verificar('filtro de valor mínimo/máximo respeita a faixa',
    count($porValor) > 0 && count($foraDaFaixa) === 0);

$previsao = '2022-12-31';
$porPrevisao = oxigenacao_eventos($pdo, filtros(['previsao_max' => $previsao]));
$foraDaPrevisao = array_filter($porPrevisao, function ($e) use ($previsao) {
    return substr((string)$e['Datarecebimento'], 0, 10) > $previsao;
});
verificar('filtro de máxima previsão de pagamento é inclusivo na data',
    count($porPrevisao) > 0 && count($foraDaPrevisao) === 0);

// Recorte de período: a soma das janelas tem que ser o total.
$datas = array_column($eventos, 'DataOxigenacao');
sort($datas);
$corte = $datas[intdiv(count($datas), 2)];
$antes = oxigenacao_eventos($pdo, filtros(['data_inicio' => '2000-01-01', 'data_fim' => $corte]));
$depois = oxigenacao_eventos($pdo, filtros(['data_inicio' => $corte, 'data_fim' => '2100-12-31']));
$naDataDoCorte = count(array_filter($eventos, function ($e) use ($corte) {
    return $e['DataOxigenacao'] === $corte;
}));
verificar('janelas de data cobrem o período inteiro (limites inclusivos)',
    count($antes) + count($depois) - $naDataDoCorte === count($eventos),
    count($antes) . ' + ' . count($depois) . ' - ' . $naDataDoCorte . ' != ' . count($eventos));

// ---------------------------------------------------------------------------
// Agregados
// ---------------------------------------------------------------------------

echo "\nAgregados:\n";
$agregados = oxigenacao_agregar($eventos);
verificar('KPI de quantidade == número de eventos', $agregados['kpis']['qtd'] === count($eventos));

$somaDia = array_sum(array_column($agregados['por_dia'], 'qtd'));
$somaEnte = array_sum(array_column($agregados['por_ente'], 'qtd'));
$somaConsultor = array_sum(array_column($agregados['por_consultor'], 'qtd'));
$somaStatus = array_sum(array_column($agregados['por_status_destino'], 'qtd'));
verificar('todos os cortes somam o mesmo total',
    $somaDia === count($eventos) && $somaEnte === count($eventos)
    && $somaConsultor === count($eventos) && $somaStatus === count($eventos),
    "dia={$somaDia} ente={$somaEnte} consultor={$somaConsultor} status={$somaStatus}");

$valorTotal = array_sum(array_column($eventos, 'ValorPrec'));
verificar('KPI de valor bate com a soma dos eventos',
    abs($agregados['kpis']['valor'] - $valorTotal) < 0.01);

$datasOrdenadas = array_column($agregados['por_dia'], 'rotulo');
$copia = $datasOrdenadas;
sort($copia);
verificar('série diária vem em ordem cronológica', $datasOrdenadas === $copia);

// ---------------------------------------------------------------------------
// Base ainda em Sem Tentativa
// ---------------------------------------------------------------------------

echo "\nBase Sem Tentativa:\n";
$base = oxigenacao_base_sem_tentativa($pdo, filtros());
$ph = implode(',', array_fill(0, count($semTentativa), '?'));
$stmt = $pdo->prepare('SELECT COUNT(*) AS Qtd FROM ' . OXI_TB_DETALHE . " WHERE StatusId IN ({$ph})");
$stmt->execute($semTentativa);
$esperadoBase = (int)$stmt->fetch()['Qtd'];
verificar('quantidade bate com a contagem direta na view',
    $base['qtd'] === $esperadoBase, "{$base['qtd']} != {$esperadoBase}");

// ---------------------------------------------------------------------------
// Foto por data
// ---------------------------------------------------------------------------

echo "\nFoto por data:\n";
$dataRef = '2020-06-30';
$foto = oxigenacao_foto_por_data($pdo, filtros(['data_ref' => $dataRef]));

$stmt = $pdo->prepare('SELECT COUNT(*) AS Qtd FROM ' . OXI_TB_DETALHE . '
                       WHERE (DataCadastra IS NULL OR DataCadastra < ?)');
$stmt->execute([oxigenacao_dia_seguinte($dataRef)]);
$universo = (int)$stmt->fetch()['Qtd'];
verificar('a soma dos status cobre todo o universo da data',
    $foto['totais']['qtd'] === $universo, "{$foto['totais']['qtd']} != {$universo}");

$rotulos = array_column($foto['linhas'], 'Status');
verificar('não há rótulo de status repetido', count($rotulos) === count(array_unique($rotulos)),
    implode(', ', $rotulos));

$semTentativaNaFoto = array_filter($foto['linhas'], function ($l) {
    return $l['Status'] === OXI_ROTULO_SEM_TENTATIVA;
});
verificar('precatório sem contato aparece como Sem Tentativa', count($semTentativaNaFoto) === 1);

// Precatórios já oxigenados até a data não podem estar no balde "sem contato".
$oxigenadosAte = count(array_filter($eventos, function ($e) use ($dataRef) {
    return $e['DataOxigenacao'] <= $dataRef;
}));
$linhaSemContato = array_values($semTentativaNaFoto)[0] ?? ['Qtd' => 0];
verificar('quem já foi oxigenado saiu do balde "sem contato"',
    $linhaSemContato['Qtd'] + $oxigenadosAte <= $universo,
    "sem contato={$linhaSemContato['Qtd']} oxigenados={$oxigenadosAte} universo={$universo}");

$fotoPendentes = oxigenacao_foto_por_data($pdo, filtros(['data_ref' => $dataRef, 'somente_pendentes' => 1]));
verificar('filtro de pendentes de pagamento reduz o total',
    $fotoPendentes['totais']['qtd'] < $foto['totais']['qtd'],
    "{$fotoPendentes['totais']['qtd']} vs {$foto['totais']['qtd']}");

$stmt = $pdo->prepare('SELECT COUNT(*) AS Qtd FROM ' . OXI_TB_DETALHE . '
                       WHERE (DataCadastra IS NULL OR DataCadastra < ?) AND prec_pg IS NULL');
$stmt->execute([oxigenacao_dia_seguinte($dataRef)]);
$esperadoPendentes = (int)$stmt->fetch()['Qtd'];
verificar('total de pendentes bate com a contagem direta',
    $fotoPendentes['totais']['qtd'] === $esperadoPendentes,
    "{$fotoPendentes['totais']['qtd']} != {$esperadoPendentes}");

$fotoEnte = oxigenacao_foto_por_data($pdo, filtros(['data_ref' => $dataRef, 'ente_id' => [$enteAlvo]]));
verificar('filtros comuns também valem na foto',
    $fotoEnte['totais']['qtd'] > 0 && $fotoEnte['totais']['qtd'] < $foto['totais']['qtd']);

// A foto de hoje tem que reproduzir os status atuais de quem nunca teve contato.
$hoje = date('Y-m-d');
$fotoHoje = oxigenacao_foto_por_data($pdo, filtros(['data_ref' => $hoje]));
$stmt = $pdo->query('SELECT COUNT(*) AS Qtd FROM ' . OXI_TB_DETALHE);
verificar('foto de hoje cobre todos os precatórios da view',
    $fotoHoje['totais']['qtd'] === (int)$stmt->fetch()['Qtd']);

// ---------------------------------------------------------------------------
// Opções dos filtros (usadas na montagem da página)
// ---------------------------------------------------------------------------

echo "\nOpções dos filtros:\n";

$opcoesOrcamento = oxigenacao_opcoes_orcamento($pdo);
$anos = array_column($opcoesOrcamento, 'Orcamento');
verificar('lista de orçamentos vem preenchida e sem repetição',
    count($anos) > 0 && count($anos) === count(array_unique($anos)));
verificar('orçamentos vêm do mais recente para o mais antigo', $anos === array_reverse(array_values(array_unique($anos))) || $anos[0] >= $anos[count($anos) - 1]);

$opcoesConsultor = oxigenacao_opcoes_consultor($pdo);
verificar('lista de consultores vem preenchida', count($opcoesConsultor) > 0);
verificar('consultores têm id e nome',
    !array_filter($opcoesConsultor, function ($c) {
        return $c['Negociador'] === null || $c['FirstName'] === null;
    }));

$mapaStatus = oxigenacao_mapa_status($pdo);
verificar('mapa de status resolve o nome do status pai',
    ($mapaStatus['1'] ?? null) === OXI_ROTULO_SEM_TENTATIVA, $mapaStatus['1'] ?? '(ausente)');

// ---------------------------------------------------------------------------
// Validação de entrada
// ---------------------------------------------------------------------------

echo "\nValidação de filtros:\n";

function esperar_erro($descricao, callable $fn) {
    try {
        $fn();
        verificar($descricao, false, 'nenhuma exceção lançada');
    } catch (InvalidArgumentException $e) {
        verificar($descricao, true);
    }
}

esperar_erro('data final anterior à inicial é rejeitada', function () {
    filtros(['data_inicio' => '2024-05-01', 'data_fim' => '2024-04-01']);
});
esperar_erro('valor mínimo maior que o máximo é rejeitado', function () {
    filtros(['valor_min' => '500', 'valor_max' => '100']);
});
esperar_erro('ente não numérico é rejeitado', function () {
    filtros(['ente_id' => ['1 OR 1=1']]);
});
esperar_erro('data inválida é rejeitada', function () {
    filtros(['data_inicio' => '31/12/2024']);
});
esperar_erro('foto sem data é rejeitada', function () {
    oxigenacao_parse_filtros([], 'foto');
});

$vazios = oxigenacao_parse_filtros([
    'data_inicio' => '2024-01-01',
    'data_fim'    => '2024-12-31',
    'ente_id'     => [],
    'valor_min'   => '',
    'previsao_max' => '',
], 'periodo');
verificar('campos em branco viram "todos"',
    $vazios['ente_id'] === [] && $vazios['valor_min'] === null && $vazios['previsao_max'] === null);

// ---------------------------------------------------------------------------

echo "\n" . ($falhas === 0 ? "OK" : "FALHOU") . ": {$total} verificações, {$falhas} falha(s).\n";
exit($falhas === 0 ? 0 : 1);
