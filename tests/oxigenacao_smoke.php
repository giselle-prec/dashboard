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
define('OXI_TB_BATCH', 'BatchControl');
define('OXI_TB_PRECATORIO', 'Precatorio');
define('OXI_TB_USUARIO', 'Usuario');
define('OXI_TB_NATUREZA', 'NaturezaPrec');

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
$qtdBatch     = carregar_csv($pdo, OXI_TB_BATCH, achar_csv($dir, 'historico_batch'));

// A exportação enviada é da view; as consultas pesadas rodam contra a tabela
// Precatorio, então ela é derivada aqui, junto das tabelas auxiliares que a
// página usa para montar os filtros.
$pdo->exec('CREATE TABLE ' . OXI_TB_PRECATORIO . ' AS SELECT *, NULL AS ' . OXI_COL_DATA_QUITACAO
    . ' FROM ' . OXI_TB_DETALHE);
$pdo->exec('CREATE TABLE ' . OXI_TB_USUARIO . ' AS
            SELECT DISTINCT usuario_id, FirstName, LastName FROM ' . OXI_TB_DETALHE . '
            WHERE usuario_id IS NOT NULL');
$pdo->exec('CREATE TABLE ' . OXI_TB_NATUREZA . ' AS
            SELECT DISTINCT NaturezaId AS natuPrec_id, Natureza FROM ' . OXI_TB_DETALHE . '
            WHERE NaturezaId IS NOT NULL');

echo "Carga: {$qtdHistorico} contatos, {$qtdStatus} status, {$qtdDetalhe} precatórios, {$qtdBatch} rodadas de batch.\n\n";

$semTentativa = oxigenacao_status_sem_tentativa($pdo);
sort($semTentativa);

echo "Família \"Sem Tentativa\": " . implode(', ', $semTentativa) . "\n\n";

$filtrosBase = [
    'data_inicio' => '2000-01-01',
    'data_fim'    => '2100-12-31',
];

$hoje = date('Y-m-d');

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

$naturezaAlvo = (int)$pdo->query('SELECT NaturezaId FROM ' . OXI_TB_PRECATORIO . '
                                  WHERE NaturezaId IS NOT NULL LIMIT 1')->fetch()['NaturezaId'];
$porNatureza = oxigenacao_eventos($pdo, filtros(['natureza_id' => [$naturezaAlvo]]));
$idsNatureza = array_column($porNatureza, 'precatorio_id');
$stmt = $pdo->query('SELECT precatorio_id FROM ' . OXI_TB_PRECATORIO . '
                     WHERE NaturezaId <> ' . $naturezaAlvo);
$deOutraNatureza = array_intersect($idsNatureza, array_column($stmt->fetchAll(), 'precatorio_id'));
verificar('filtro de Natureza devolve só a natureza pedida',
    count($porNatureza) > 0 && count($deOutraNatureza) === 0);
verificar('filtro de Natureza reduz o conjunto', count($porNatureza) < count($eventos));

$fotoNatureza = oxigenacao_foto_por_data($pdo, filtros(['data_ref' => $hoje, 'natureza_id' => [$naturezaAlvo]]));
$fotoTodas = oxigenacao_foto_por_data($pdo, filtros(['data_ref' => $hoje]));
verificar('filtro de Natureza também vale na foto',
    $fotoNatureza['totais']['qtd'] > 0 && $fotoNatureza['totais']['qtd'] < $fotoTodas['totais']['qtd']);

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

// Cruzamento status x ente que alimenta o detalhamento ao clicar na pizza.
$statusComEnte = array_keys($agregados['por_status_ente']);
$statusNaPizza = array_column($agregados['por_status_destino'], 'rotulo');
sort($statusComEnte);
sort($statusNaPizza);
verificar('todo status da pizza tem quebra por ente', $statusComEnte === $statusNaPizza);

foreach (['por_status_ente' => 'ente', 'por_status_consultor' => 'consultor'] as $cruzamento => $rotuloCruz) {
    $somaCruzada = 0;
    $divergenteCruzado = [];
    foreach ($agregados['por_status_destino'] as $linha) {
        $doStatus = $agregados[$cruzamento][$linha['rotulo']];
        $soma = array_sum(array_column($doStatus, 'qtd'));
        $somaCruzada += $soma;
        if ($soma !== $linha['qtd']) {
            $divergenteCruzado[] = $linha['rotulo'] . ": {$soma} != {$linha['qtd']}";
        }
    }
    verificar("a quebra por {$rotuloCruz} soma o total do status",
        count($divergenteCruzado) === 0, implode(' | ', $divergenteCruzado));
    verificar("o cruzamento por {$rotuloCruz} cobre todos os eventos", $somaCruzada === count($eventos));
}

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

$linhaSemTentativa = array_values($semTentativaNaFoto)[0];
verificar('a linha de Sem Tentativa vem marcada para o gráfico ignorar',
    $linhaSemTentativa['SemTentativa'] === true);
verificar('os demais status não vêm marcados',
    !array_filter($foto['linhas'], function ($l) {
        return $l['SemTentativa'] && $l['Status'] !== OXI_ROTULO_SEM_TENTATIVA;
    }));
verificar('total de Sem Tentativa bate com a linha correspondente',
    $foto['totais']['sem_tentativa_qtd'] === $linhaSemTentativa['Qtd']);
verificar('Sem Tentativa + demais status fecham o total',
    $foto['totais']['sem_tentativa_qtd'] + $foto['totais']['outros_qtd'] === $foto['totais']['qtd']
    && abs($foto['totais']['sem_tentativa_valor'] + $foto['totais']['outros_valor']
           - $foto['totais']['valor']) < 0.01);
verificar('demais status somam as linhas fora de Sem Tentativa',
    $foto['totais']['outros_qtd'] === array_sum(array_column(array_filter($foto['linhas'],
        function ($l) { return !$l['SemTentativa']; }), 'Qtd')));

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
$fotoHoje = oxigenacao_foto_por_data($pdo, filtros(['data_ref' => $hoje]));
$stmt = $pdo->query('SELECT COUNT(*) AS Qtd FROM ' . OXI_TB_DETALHE);
verificar('foto de hoje cobre todos os precatórios da view',
    $fotoHoje['totais']['qtd'] === (int)$stmt->fetch()['Qtd']);

// ---------------------------------------------------------------------------
// Reconstrução da quitação pelo histórico de lotes
// ---------------------------------------------------------------------------

echo "\nQuitação pelo histórico de lotes:\n";

$cobertura = oxigenacao_cobertura_quitacao($pdo, filtros(['data_ref' => $hoje]));
echo "  histórico de lotes começa em {$cobertura['inicio_historico']}; "
    . "{$cobertura['quitados_data_exata']} com data exata, {$cobertura['quitados_data_lote']} por lote, "
    . "{$cobertura['quitados_sem_data']} sem data; {$cobertura['pendentes_hoje']} pendentes hoje.\n";

verificar('início do histórico de lotes é identificado',
    $cobertura['inicio_historico'] !== null);
verificar('a coluna de data de quitação é detectada quando existe',
    $cobertura['tem_coluna_data'] === true);

$quitadosNaAmostra = count(array_filter(
    $pdo->query('SELECT prec_pg FROM ' . OXI_TB_PRECATORIO)->fetchAll(),
    function ($l) { return $l['prec_pg'] !== null; }
));
verificar('as três fontes somam os quitados da amostra',
    $cobertura['quitados_data_exata'] + $cobertura['quitados_data_lote'] + $cobertura['quitados_sem_data']
        === $quitadosNaAmostra);
verificar('pendentes de hoje é o complemento dos quitados',
    $cobertura['pendentes_hoje'] + $quitadosNaAmostra === (int)$pdo->query(
        'SELECT COUNT(*) AS Qtd FROM ' . OXI_TB_PRECATORIO)->fetch()['Qtd']);

// As amostras de precatório e de batch não se cruzam (a view exportada tem
// lotes antigos), então a rodada de quitação é montada aqui para exercitar o
// JOIN e o recorte por data.
$alvo = $pdo->query('SELECT EnteId, batch, COUNT(*) AS Qtd FROM ' . OXI_TB_DETALHE . "
                     WHERE prec_pg IS NOT NULL AND batch IS NOT NULL AND CAST(batch AS SIGNED) <> 0
                       AND EnteId IS NOT NULL
                     GROUP BY EnteId, batch ORDER BY Qtd DESC")->fetch();

$dataQuitacao = '2024-07-15';
$pdo->prepare('INSERT INTO ' . OXI_TB_BATCH . ' (idBatchControl, data_batch, ente_id, n_batch_quit, qtd_quitados)
               VALUES (?, ?, ?, ?, ?)')
    ->execute([999001, $dataQuitacao, $alvo['EnteId'], $alvo['batch'], $alvo['Qtd']]);

$antes  = oxigenacao_foto_por_data($pdo, filtros(['data_ref' => '2024-07-01', 'somente_pendentes' => 1]));
$depois = oxigenacao_foto_por_data($pdo, filtros(['data_ref' => '2024-08-01', 'somente_pendentes' => 1]));
$universoAntes  = oxigenacao_foto_por_data($pdo, filtros(['data_ref' => '2024-07-01']));
$universoDepois = oxigenacao_foto_por_data($pdo, filtros(['data_ref' => '2024-08-01']));

verificar('o universo da foto não muda entre as duas datas (controle)',
    $universoAntes['totais']['qtd'] === $universoDepois['totais']['qtd']);
verificar('precatório quitado ainda conta como pendente antes da data do lote',
    $antes['totais']['qtd'] - $depois['totais']['qtd'] === (int)$alvo['Qtd'],
    "{$antes['totais']['qtd']} - {$depois['totais']['qtd']} != {$alvo['Qtd']}");

$coberturaDepois = oxigenacao_cobertura_quitacao($pdo, filtros(['data_ref' => '2024-08-01']));
verificar('a rodada registrada passa a contar como quitação por lote',
    $coberturaDepois['quitados_data_lote'] === (int)$alvo['Qtd'],
    "{$coberturaDepois['quitados_data_lote']} != {$alvo['Qtd']}");

// A quitação de um ente não pode vazar para precatórios de outro ente com o
// mesmo número de lote.
$pdo->prepare('INSERT INTO ' . OXI_TB_BATCH . ' (idBatchControl, data_batch, ente_id, n_batch_quit)
               VALUES (?, ?, ?, ?)')
    ->execute([999002, '2024-07-15', 999999, $alvo['batch']]);
$depoisComRuido = oxigenacao_foto_por_data($pdo, filtros(['data_ref' => '2024-08-01', 'somente_pendentes' => 1]));
verificar('quitação de outro ente com o mesmo número de lote não vaza',
    $depoisComRuido['totais']['qtd'] === $depois['totais']['qtd']);

// Lote 0 é o valor padrão de quem nunca passou por batch: não pode quitar nada.
$pdo->prepare('INSERT INTO ' . OXI_TB_BATCH . ' (idBatchControl, data_batch, ente_id, n_batch_quit)
               VALUES (?, ?, ?, ?)')
    ->execute([999003, '2024-07-15', $alvo['EnteId'], '0']);
$depoisComZero = oxigenacao_foto_por_data($pdo, filtros(['data_ref' => '2024-08-01', 'somente_pendentes' => 1]));
verificar('lote 0 não é tratado como rodada de quitação',
    $depoisComZero['totais']['qtd'] === $depois['totais']['qtd'],
    "{$depoisComZero['totais']['qtd']} != {$depois['totais']['qtd']}");

// A coluna de data de quitação tem precedência sobre a data do lote.
echo "\nColuna de data de quitação:\n";

$pdo->exec('UPDATE ' . OXI_TB_PRECATORIO . ' SET ' . OXI_COL_DATA_QUITACAO . " = '2024-12-20'
            WHERE prec_pg IS NOT NULL AND EnteId = " . (int)$alvo['EnteId'] . '
              AND CAST(batch AS SIGNED) = ' . (int)$alvo['batch']);

$comColuna = oxigenacao_foto_por_data($pdo, filtros(['data_ref' => '2024-08-01', 'somente_pendentes' => 1]));
verificar('data da coluna vence a data do lote (lote dizia quitado, coluna diz que não)',
    $comColuna['totais']['qtd'] === $depois['totais']['qtd'] + (int)$alvo['Qtd'],
    "{$comColuna['totais']['qtd']} != " . ($depois['totais']['qtd'] + (int)$alvo['Qtd']));

$depoisDaColuna = oxigenacao_foto_por_data($pdo, filtros(['data_ref' => '2025-01-05', 'somente_pendentes' => 1]));
verificar('passada a data da coluna, o precatório sai dos pendentes',
    $depoisDaColuna['totais']['qtd'] === $comColuna['totais']['qtd'] - (int)$alvo['Qtd'],
    "{$depoisDaColuna['totais']['qtd']} vs {$comColuna['totais']['qtd']}");

$coberturaColuna = oxigenacao_cobertura_quitacao($pdo, filtros(['data_ref' => '2024-08-01']));
verificar('cobertura separa data exata de data por lote',
    $coberturaColuna['quitados_data_exata'] === (int)$alvo['Qtd']
    && $coberturaColuna['quitados_data_lote'] === 0,
    "exata={$coberturaColuna['quitados_data_exata']} lote={$coberturaColuna['quitados_data_lote']}");

// Data gravada com hora junto tem que dar o mesmo resultado da data pura.
$pdo->exec('UPDATE ' . OXI_TB_PRECATORIO . ' SET ' . OXI_COL_DATA_QUITACAO . " = '2024-12-20T03:00:00.000Z'
            WHERE " . OXI_COL_DATA_QUITACAO . " = '2024-12-20'");
$comHora = oxigenacao_foto_por_data($pdo, filtros(['data_ref' => '2024-12-20', 'somente_pendentes' => 1]));
$pdo->exec('UPDATE ' . OXI_TB_PRECATORIO . ' SET ' . OXI_COL_DATA_QUITACAO . " = '2024-12-20'
            WHERE " . OXI_COL_DATA_QUITACAO . " = '2024-12-20T03:00:00.000Z'");
$semHora = oxigenacao_foto_por_data($pdo, filtros(['data_ref' => '2024-12-20', 'somente_pendentes' => 1]));
verificar('data com hora e data pura dão o mesmo resultado',
    $comHora['totais']['qtd'] === $semHora['totais']['qtd'],
    "{$comHora['totais']['qtd']} != {$semHora['totais']['qtd']}");
verificar('quitado no próprio dia já não conta como pendente naquele dia',
    $semHora['totais']['qtd'] === $depoisDaColuna['totais']['qtd']);

// Sem a coluna, o painel precisa continuar de pé usando só o histórico de lotes.
$suportaDropColumn = true;
try {
    $pdo->exec('ALTER TABLE ' . OXI_TB_PRECATORIO . ' DROP COLUMN ' . OXI_COL_DATA_QUITACAO);
} catch (PDOException $e) {
    $suportaDropColumn = false;
    echo "  (SQLite sem DROP COLUMN: teste de ausência da coluna pulado)\n";
}
if ($suportaDropColumn) {
    verificar('a ausência da coluna é detectada', oxigenacao_coluna_quitacao_existe($pdo) === false);
    $semColuna = oxigenacao_foto_por_data($pdo, filtros(['data_ref' => '2024-08-01', 'somente_pendentes' => 1]));
    verificar('sem a coluna, a foto volta a usar só o histórico de lotes',
        $semColuna['totais']['qtd'] === $depois['totais']['qtd'],
        "{$semColuna['totais']['qtd']} != {$depois['totais']['qtd']}");
    $coberturaSemColuna = oxigenacao_cobertura_quitacao($pdo, filtros(['data_ref' => '2024-08-01']));
    verificar('sem a coluna, nada é contado como data exata',
        $coberturaSemColuna['tem_coluna_data'] === false
        && $coberturaSemColuna['quitados_data_exata'] === 0);
    $pdo->exec('ALTER TABLE ' . OXI_TB_PRECATORIO . ' ADD COLUMN ' . OXI_COL_DATA_QUITACAO . ' TEXT');
}

$pdo->exec('UPDATE ' . OXI_TB_PRECATORIO . ' SET ' . OXI_COL_DATA_QUITACAO . ' = NULL');
$pdo->exec('DELETE FROM ' . OXI_TB_BATCH . ' WHERE idBatchControl IN (999001, 999002, 999003)');
$restaurado = oxigenacao_foto_por_data($pdo, filtros(['data_ref' => '2024-08-01', 'somente_pendentes' => 1]));
verificar('sem rodada registrada, o quitado segue fora dos pendentes',
    $restaurado['totais']['qtd'] === $depois['totais']['qtd'],
    "restaurado={$restaurado['totais']['qtd']} depois={$depois['totais']['qtd']}");

// ---------------------------------------------------------------------------
// Opções dos filtros (usadas na montagem da página)
// ---------------------------------------------------------------------------

echo "\nOpções dos filtros:\n";

// Lixo gravado na coluna varchar não pode chegar ao filtro.
$pdo->exec("INSERT INTO " . OXI_TB_PRECATORIO . " (precatorio_id, Orcamento) VALUES (999901, 'NULL'), (999902, '24')");
$opcoesOrcamento = oxigenacao_opcoes_orcamento($pdo);
$pdo->exec('DELETE FROM ' . OXI_TB_PRECATORIO . ' WHERE precatorio_id IN (999901, 999902)');

$anos = array_column($opcoesOrcamento, 'Orcamento');
verificar('lista de orçamentos vem preenchida e sem repetição',
    count($anos) > 0 && count($anos) === count(array_unique($anos)));
verificar('orçamentos inválidos ficam fora da lista',
    !in_array('NULL', $anos, true) && !in_array('24', $anos, true), implode(', ', $anos));
verificar('orçamentos vêm do mais recente para o mais antigo', $anos === array_reverse(array_values(array_unique($anos))) || $anos[0] >= $anos[count($anos) - 1]);

$opcoesConsultor = oxigenacao_opcoes_consultor($pdo);
verificar('lista de consultores vem preenchida', count($opcoesConsultor) > 0);
verificar('consultores têm id e nome',
    !array_filter($opcoesConsultor, function ($c) {
        return $c['Negociador'] === null || $c['FirstName'] === null;
    }));

$opcoesNatureza = oxigenacao_opcoes_natureza($pdo);
verificar('lista de naturezas vem preenchida com id e nome',
    count($opcoesNatureza) > 0 && !array_filter($opcoesNatureza, function ($n) {
        return $n['natuPrec_id'] === null || $n['Natureza'] === null;
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
