<?php

// Consultas de suporte ao painel de oxigenação (public/oxigenacao.php).
//
// Oxigenação = o precatório sai do status "Sem Tentativa" para qualquer outro status.
// O único registro de status ao longo do tempo é HistoricoContato.ResultContatoId,
// que aponta para StatusPrecatorio.statusPrecatorio_id. Portanto:
//
// - A data de oxigenação de um precatório é a data do PRIMEIRO registro de
//   HistoricoContato cujo ResultContatoId está fora da família "Sem Tentativa".
//   Cada precatório é contado uma única vez, mesmo que volte para "Sem Tentativa".
// - A família "Sem Tentativa" é lida do banco (statusPrecatorio_id = 1 ou ParentId = 1),
//   e não fixada no código, para absorver novos status filhos.
// - Precatório sem nenhum registro de contato é considerado "Sem Tentativa".
//
// Limitações conhecidas (exibidas na tela):
// - Mudanças de status feitas fora do fluxo de contato (ex.: "Pago pelo ente",
//   "Pausado", alterações em lote) não estão no histórico, então a reconstrução
//   da foto por data é aproximada para esses status.
// - Se o precatório está quitado hoje é o prec_pg quem diz. A data da quitação
//   sai de três fontes, nesta ordem:
//     1. Precatorio.DataQuitacaoBatch (coluna nova, preenchida nas alterações em
//        lote) — a fonte exata, quando existir;
//     2. o histórico de lotes: cada rodada de batch reserva três números
//        (n_batch_new, n_batch_upd, n_batch_quit) e grava o usado em
//        Precatorio.batch, então Precatorio.batch = BatchControl.n_batch_quit
//        (do mesmo ente) identifica a rodada que quitou, e data_batch é a data;
//     3. nada — quitação anterior a qualquer registro. Nesse caso o precatório
//        entra como já quitado em qualquer data, e a tela avisa quantos estão
//        nessa situação.
//
// Desempenho: a foto por data e a base "Sem Tentativa" consultam a tabela
// Precatorio direto, não a view precatoriodetalhe. A view junta nove tabelas e
// varrê-la inteira por precatório é o que fazia a consulta travar. A diferença
// é que a view descarta precatórios sem cadastro relacionado (credor, advogado,
// réu, tabela de cálculo), então a foto conta um pouco mais de precatórios que
// o Painel de Prospecção. A aba de período continua usando a view, mas só para
// os precatórios oxigenados no intervalo, buscados pela chave primária.
// Se ainda ficar lento, os índices que resolvem são (PrecatorioId, DataContato)
// e (ResultContatoId) em HistoricoContato.

require_once __DIR__ . '/prospeccao_repository.php';

// Nomes de tabela isolados em constantes sobrescrevíveis (o harness de teste
// aponta as constantes para tabelas locais antes de incluir este arquivo).
defined('OXI_TB_HISTORICO')  || define('OXI_TB_HISTORICO',  'precappapp.HistoricoContato');
defined('OXI_TB_STATUS')     || define('OXI_TB_STATUS',     'precappapp.StatusPrecatorio');
defined('OXI_TB_DETALHE')    || define('OXI_TB_DETALHE',    'precappapp.precatoriodetalhe');
defined('OXI_TB_BATCH')      || define('OXI_TB_BATCH',      'precappapp.BatchControl');
defined('OXI_TB_PRECATORIO') || define('OXI_TB_PRECATORIO', 'precappapp.Precatorio');
defined('OXI_TB_NATUREZA')   || define('OXI_TB_NATUREZA',   'precappapp.NaturezaPrec');
defined('OXI_TB_USUARIO')    || define('OXI_TB_USUARIO',    'precappapp.Usuario');
defined('OXI_TB_ENTE')       || define('OXI_TB_ENTE',       'precappapp.Ente');

// Perfil de consultor na tabela de usuários.
const OXI_PERFIL_CONSULTOR = 2;

// Coluna com a data em que o tribunal quitou o precatório. Ainda não existe em
// todas as bases: quando estiver ausente, a reconstrução cai para o histórico
// de lotes sozinho. Trocar aqui se a coluna receber outro nome.
defined('OXI_COL_DATA_QUITACAO') || define('OXI_COL_DATA_QUITACAO', 'DataQuitacaoBatch');

// Teto de execução das consultas pesadas (hint do MySQL 8; outros bancos leem
// como comentário). Sem isso, uma consulta lenta prende o servidor de
// desenvolvimento do PHP, que atende uma requisição por vez.
const OXI_HINT_TIMEOUT = '/*+ MAX_EXECUTION_TIME(30000) */';

// Máximo de linhas de detalhe devolvidas ao navegador (os agregados continuam
// considerando o conjunto completo).
const OXI_LIMITE_DETALHE = 5000;

const OXI_ROTULO_SEM_TENTATIVA = 'Sem Tentativa';

// ---------------------------------------------------------------------------
// Filtros
// ---------------------------------------------------------------------------

// Lista de inteiros vinda de um multi-select. Vazio = "todos" (sem filtro).
function oxigenacao_sanitize_lista_int($raw, $rotulo) {
    if ($raw === null || $raw === '' || $raw === []) {
        return [];
    }
    if (!is_array($raw)) {
        $raw = explode(',', (string)$raw);
    }
    $ids = [];
    foreach ($raw as $item) {
        $item = trim((string)$item);
        if ($item === '') {
            continue;
        }
        if (!ctype_digit($item)) {
            throw new InvalidArgumentException("{$rotulo} inválido.");
        }
        $ids[] = (int)$item;
    }
    return array_values(array_unique($ids));
}

// Valor monetário opcional. Vazio = sem limite.
function oxigenacao_sanitize_valor_opcional($raw, $rotulo) {
    if ($raw === null || trim((string)$raw) === '') {
        return null;
    }
    if (!is_numeric($raw) || (float)$raw < 0) {
        throw new InvalidArgumentException("{$rotulo} inválido.");
    }
    return number_format((float)$raw, 2, '.', '');
}

// Data opcional no formato Y-m-d. Vazio = sem limite.
function oxigenacao_sanitize_data_opcional($raw, $rotulo) {
    if ($raw === null || trim((string)$raw) === '') {
        return null;
    }
    try {
        return prospeccao_sanitize_data($raw);
    } catch (InvalidArgumentException $e) {
        throw new InvalidArgumentException("{$rotulo} inválida.");
    }
}

// Devolve a data seguinte, para usar em comparações "< data + 1 dia" (limite inclusivo).
function oxigenacao_dia_seguinte($data) {
    $dt = new DateTime($data);
    $dt->modify('+1 day');
    return $dt->format('Y-m-d');
}

// Normaliza e valida os filtros. $modo = 'periodo' (aba 1) ou 'foto' (aba 2).
function oxigenacao_parse_filtros(array $input, $modo = 'periodo') {
    $filtros = [
        'ente_id'      => oxigenacao_sanitize_lista_int($input['ente_id'] ?? null, 'Ente'),
        'orcamento'    => oxigenacao_sanitize_lista_int($input['orcamento'] ?? null, 'Orçamento'),
        'consultor_id' => oxigenacao_sanitize_lista_int($input['consultor_id'] ?? null, 'Consultor'),
        'natureza_id'  => oxigenacao_sanitize_lista_int($input['natureza_id'] ?? null, 'Natureza'),
        'valor_min'    => oxigenacao_sanitize_valor_opcional($input['valor_min'] ?? null, 'Valor mínimo'),
        'valor_max'    => oxigenacao_sanitize_valor_opcional($input['valor_max'] ?? null, 'Valor máximo'),
        'previsao_max' => oxigenacao_sanitize_data_opcional($input['previsao_max'] ?? null, 'Máxima previsão de pagamento'),
        'excluir_status_contrato' => !empty($input['excluir_status_contrato']),
        'somente_pendentes'       => !empty($input['somente_pendentes']),
        'modo'         => $modo,
    ];

    foreach ($filtros['orcamento'] as $ano) {
        prospeccao_sanitize_orcamento($ano);
    }

    if ($filtros['valor_min'] !== null && $filtros['valor_max'] !== null
        && (float)$filtros['valor_min'] > (float)$filtros['valor_max']) {
        throw new InvalidArgumentException('Valor mínimo não pode ser maior que o valor máximo.');
    }

    if ($modo === 'foto') {
        if (trim((string)($input['data_ref'] ?? '')) === '') {
            throw new InvalidArgumentException('Informe a data da foto.');
        }
        $filtros['data_ref'] = prospeccao_sanitize_data($input['data_ref']);
        return $filtros;
    }

    if (trim((string)($input['data_inicio'] ?? '')) === '' || trim((string)($input['data_fim'] ?? '')) === '') {
        throw new InvalidArgumentException('Informe a data inicial e a data final.');
    }
    $filtros['data_inicio'] = prospeccao_sanitize_data($input['data_inicio']);
    $filtros['data_fim']    = prospeccao_sanitize_data($input['data_fim']);
    if ($filtros['data_fim'] < $filtros['data_inicio']) {
        throw new InvalidArgumentException('A data final não pode ser anterior à data inicial.');
    }

    return $filtros;
}

// ---------------------------------------------------------------------------
// Apoio às queries
// ---------------------------------------------------------------------------

function oxigenacao_placeholders(array $valores) {
    return implode(',', array_fill(0, count($valores), '?'));
}

// Ids da família "Sem Tentativa": o próprio status 1 e todos os seus filhos.
function oxigenacao_status_sem_tentativa(PDO $pdo) {
    $sql = 'SELECT statusPrecatorio_id FROM ' . OXI_TB_STATUS . '
            WHERE statusPrecatorio_id = 1 OR ParentId = 1';
    $stmt = $pdo->query($sql);
    $ids = [];
    foreach ($stmt->fetchAll() as $linha) {
        $ids[] = (int)$linha['statusPrecatorio_id'];
    }
    if (!$ids) {
        $ids = [1];
    }
    return $ids;
}

// Condições comuns às duas abas, aplicadas sobre a view de detalhe (alias $alias).
// Acrescenta os valores em $params na mesma ordem em que aparecem no SQL.
function oxigenacao_where_precatorio(array $filtros, array &$params, $alias = 'p') {
    $sql = '';

    // EnteId (e não ente_id) porque a mesma cláusula roda contra a view e
    // contra a tabela Precatorio.
    if ($filtros['ente_id']) {
        $sql .= " AND {$alias}.EnteId IN (" . oxigenacao_placeholders($filtros['ente_id']) . ')';
        $params = array_merge($params, $filtros['ente_id']);
    }
    if ($filtros['natureza_id']) {
        $sql .= " AND {$alias}.NaturezaId IN (" . oxigenacao_placeholders($filtros['natureza_id']) . ')';
        $params = array_merge($params, $filtros['natureza_id']);
    }
    if ($filtros['orcamento']) {
        $sql .= " AND {$alias}.Orcamento IN (" . oxigenacao_placeholders($filtros['orcamento']) . ')';
        $params = array_merge($params, $filtros['orcamento']);
    }
    if ($filtros['consultor_id']) {
        $sql .= " AND {$alias}.Negociador IN (" . oxigenacao_placeholders($filtros['consultor_id']) . ')';
        $params = array_merge($params, $filtros['consultor_id']);
    }
    if ($filtros['valor_min'] !== null) {
        $sql .= " AND CAST({$alias}.ValorPrec AS DECIMAL(15,2)) >= ?";
        $params[] = $filtros['valor_min'];
    }
    if ($filtros['valor_max'] !== null) {
        $sql .= " AND CAST({$alias}.ValorPrec AS DECIMAL(15,2)) <= ?";
        $params[] = $filtros['valor_max'];
    }
    if ($filtros['previsao_max'] !== null) {
        $sql .= " AND {$alias}.DataRecebimento < ?";
        $params[] = oxigenacao_dia_seguinte($filtros['previsao_max']);
    }
    if (!empty($filtros['excluir_status_contrato'])) {
        $sql .= " AND {$alias}.StatusId NOT IN (" . prospeccao_status_excluidos_placeholders() . ')';
        $params = array_merge($params, PROSPECCAO_STATUS_EXCLUIDOS);
    }

    return $sql;
}

// Status removidos da tabela ainda aparecem em registros antigos de contato.
function oxigenacao_rotulo_status($status_id, $nome) {
    if ($nome !== null && $nome !== '') {
        return $nome;
    }
    if ($status_id === null || $status_id === '') {
        return OXI_ROTULO_SEM_TENTATIVA;
    }
    return 'Status #' . $status_id;
}

// ---------------------------------------------------------------------------
// Aba 1 — oxigenação por período
// ---------------------------------------------------------------------------

// Uma linha por precatório oxigenado dentro do período, já com os dados do
// precatório e do contato que o oxigenou.
function oxigenacao_eventos(PDO $pdo, array $filtros) {
    $semTentativa = oxigenacao_status_sem_tentativa($pdo);
    $ph = oxigenacao_placeholders($semTentativa);

    $historico = OXI_TB_HISTORICO;
    $detalhe   = OXI_TB_DETALHE;
    $status    = OXI_TB_STATUS;

    // A subquery interna acha a data do primeiro contato "oxigenante"; a externa
    // desempata datas iguais pelo menor historicoContato_id.
    $sql = "
        SELECT
            DATE(ho.DataContato) AS DataOxigenacao,
            ho.Negociador        AS NegociadorContato,
            ho.ResultContatoId   AS StatusDestinoId,
            s.Status             AS StatusDestinoNome,
            p.precatorio_id,
            p.Precatorio,
            p.Processo,
            p.ente_id,
            p.Ente,
            p.Orcamento,
            p.Negociador         AS ConsultorId,
            p.FirstName          AS Consultor,
            p.ValorPrec,
            p.Datarecebimento,
            p.StatusPrec,
            p.prec_pg
        FROM (
            SELECT h2.PrecatorioId AS PrecatorioId, MIN(h2.historicoContato_id) AS OxiId
            FROM {$historico} h2
            JOIN (
                SELECT h.PrecatorioId AS PrecatorioId, MIN(h.DataContato) AS DataOxi
                FROM {$historico} h
                WHERE h.ResultContatoId IS NOT NULL
                  AND h.ResultContatoId NOT IN ({$ph})
                GROUP BY h.PrecatorioId
            ) pri ON pri.PrecatorioId = h2.PrecatorioId AND h2.DataContato = pri.DataOxi
            WHERE h2.ResultContatoId IS NOT NULL
              AND h2.ResultContatoId NOT IN ({$ph})
            GROUP BY h2.PrecatorioId
        ) oxi
        JOIN {$historico} ho ON ho.historicoContato_id = oxi.OxiId
        JOIN {$detalhe} p    ON p.precatorio_id = oxi.PrecatorioId
        LEFT JOIN {$status} s ON s.statusPrecatorio_id = ho.ResultContatoId
        WHERE ho.DataContato >= ?
          AND ho.DataContato < ?
    ";

    $params = array_merge($semTentativa, $semTentativa, [
        $filtros['data_inicio'],
        oxigenacao_dia_seguinte($filtros['data_fim']),
    ]);
    $sql .= oxigenacao_where_precatorio($filtros, $params);
    $sql .= ' ORDER BY DataOxigenacao, p.precatorio_id';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $linhas = $stmt->fetchAll();

    foreach ($linhas as &$linha) {
        $linha['StatusDestino'] = oxigenacao_rotulo_status($linha['StatusDestinoId'], $linha['StatusDestinoNome']);
        $linha['Consultor']     = ($linha['Consultor'] === null || $linha['Consultor'] === '')
            ? '(sem consultor)'
            : $linha['Consultor'];
        $linha['ValorPrec']     = (float)$linha['ValorPrec'];
        unset($linha['StatusDestinoNome']);
    }
    unset($linha);

    return $linhas;
}

// Soma uma quantidade já apurada em um balde de agregação.
function oxigenacao_somar_agregado(array &$destino, $chave, $rotulo, $qtd, $valor) {
    $chave = ($chave === null || $chave === '') ? '0' : (string)$chave;
    if (!isset($destino[$chave])) {
        $destino[$chave] = ['rotulo' => $rotulo, 'qtd' => 0, 'valor' => 0.0];
    }
    $destino[$chave]['qtd'] += $qtd;
    $destino[$chave]['valor'] += $valor;
}

// Soma um evento (uma unidade) em um balde de agregação.
function oxigenacao_acumular(array &$destino, $chave, $rotulo, $valor) {
    oxigenacao_somar_agregado($destino, $chave, $rotulo, 1, $valor);
}

// Ordena por valor decrescente e devolve lista simples (pronta para JSON).
function oxigenacao_ordenar_agregado(array $agregado, $por = 'valor') {
    uasort($agregado, function ($a, $b) use ($por) {
        return $b[$por] <=> $a[$por];
    });
    return array_values($agregado);
}

// Agregados dos gráficos, calculados em PHP a partir dos eventos.
function oxigenacao_agregar(array $eventos) {
    $porDia = $porEnte = $porConsultor = $porStatus = [];
    $porStatusEnte = $porStatusConsultor = [];
    $valorTotal = 0.0;
    $entes = [];

    foreach ($eventos as $evento) {
        $valor = (float)$evento['ValorPrec'];
        $valorTotal += $valor;
        $entes[$evento['ente_id']] = true;
        $status = $evento['StatusDestino'];

        oxigenacao_acumular($porDia, $evento['DataOxigenacao'], $evento['DataOxigenacao'], $valor);
        oxigenacao_acumular($porEnte, $evento['ente_id'], $evento['Ente'], $valor);
        oxigenacao_acumular($porConsultor, $evento['ConsultorId'], $evento['Consultor'], $valor);
        oxigenacao_acumular($porStatus, $status, $status, $valor);

        // Cruzamentos status de destino x ente e x consultor, para o
        // detalhamento ao clicar numa fatia do gráfico de status.
        if (!isset($porStatusEnte[$status])) {
            $porStatusEnte[$status] = [];
            $porStatusConsultor[$status] = [];
        }
        oxigenacao_acumular($porStatusEnte[$status], $evento['ente_id'], $evento['Ente'], $valor);
        oxigenacao_acumular($porStatusConsultor[$status], $evento['ConsultorId'], $evento['Consultor'], $valor);
    }

    ksort($porDia);

    foreach ($porStatusEnte as $status => $entesDoStatus) {
        $porStatusEnte[$status] = oxigenacao_ordenar_agregado($entesDoStatus, 'qtd');
        $porStatusConsultor[$status] = oxigenacao_ordenar_agregado($porStatusConsultor[$status], 'qtd');
    }

    $qtd = count($eventos);

    return [
        'kpis' => [
            'qtd'              => $qtd,
            'valor'            => $valorTotal,
            'entes_distintos'  => count($entes),
            'ticket_medio'     => $qtd > 0 ? $valorTotal / $qtd : 0.0,
        ],
        'por_dia'            => array_values($porDia),
        'por_ente'           => oxigenacao_ordenar_agregado($porEnte),
        'por_consultor'      => oxigenacao_ordenar_agregado($porConsultor),
        'por_status_destino' => oxigenacao_ordenar_agregado($porStatus),
        'por_status_ente'      => $porStatusEnte,
        'por_status_consultor' => $porStatusConsultor,
    ];
}

// Base ainda não oxigenada (status atual dentro da família "Sem Tentativa"),
// para dar denominador ao número do período.
function oxigenacao_base_sem_tentativa(PDO $pdo, array $filtros) {
    $semTentativa = oxigenacao_status_sem_tentativa($pdo);
    $ph = oxigenacao_placeholders($semTentativa);
    $precatorio = OXI_TB_PRECATORIO;

    $sql = '
        SELECT ' . OXI_HINT_TIMEOUT . "
            COUNT(*) AS Qtd,
            COALESCE(SUM(CAST(p.ValorPrec AS DECIMAL(15,2))), 0) AS Valor
        FROM {$precatorio} p
        WHERE p.StatusId IN ({$ph})
    ";
    $params = $semTentativa;
    $sql .= oxigenacao_where_precatorio($filtros, $params);

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $linha = $stmt->fetch();

    return [
        'qtd'   => (int)($linha['Qtd'] ?? 0),
        'valor' => (float)($linha['Valor'] ?? 0),
    ];
}

// ---------------------------------------------------------------------------
// Aba 2 — foto por data
// ---------------------------------------------------------------------------

// Data de quitação por precatório, derivada do histórico de lotes: a rodada de
// batch cujo n_batch_quit foi gravado em Precatorio.batch (no mesmo ente) é a
// que quitou o precatório. Números reaproveitados entre rodadas próximas são
// resolvidos pela data mais antiga — na prática as rodadas em conflito estão a
// no máximo um dia de distância.
//
// O resultado se chama DataRodadaQuitacao, e não DataQuitacao, para não se
// confundir com a coluna DataQuitacaoBatch da tabela Precatorio: esta é a data
// informada pelo tribunal, aquela é a data em que a rodada de batch rodou.
function oxigenacao_sql_quitacao() {
    $batch = OXI_TB_BATCH;
    return "
        SELECT
            CAST(b.n_batch_quit AS SIGNED) AS BatchQuit,
            CAST(b.ente_id AS SIGNED)      AS EnteId,
            MIN(b.data_batch)              AS DataRodadaQuitacao
        FROM {$batch} b
        WHERE b.n_batch_quit IS NOT NULL
          AND CAST(b.n_batch_quit AS SIGNED) <> 0
          AND b.ente_id IS NOT NULL
          AND b.data_batch IS NOT NULL
          AND b.data_batch <> ''
        GROUP BY CAST(b.n_batch_quit AS SIGNED), CAST(b.ente_id AS SIGNED)
    ";
}

// JOIN da data de quitação. O CAST fica dentro da subconsulta, dos dois lados
// da igualdade só há coluna: com CAST no lado do precatório o MySQL não
// consegue criar a chave automática da tabela derivada e passa a varrer as
// centenas de rodadas de batch para cada um dos precatórios.
function oxigenacao_join_quitacao($alias = 'p', $aliasQuit = 'q') {
    return ' LEFT JOIN (' . oxigenacao_sql_quitacao() . ") {$aliasQuit}
             ON {$aliasQuit}.BatchQuit = {$alias}.batch
            AND {$aliasQuit}.EnteId = {$alias}.EnteId ";
}

// A data de quitação só interessa quando o recorte de pendentes está ligado;
// fora disso o JOIN é peso morto.
function oxigenacao_join_quitacao_se_preciso(array $filtros, $alias = 'p', $aliasQuit = 'q') {
    return empty($filtros['somente_pendentes']) ? '' : oxigenacao_join_quitacao($alias, $aliasQuit);
}

// A coluna de data de quitação está sendo criada aos poucos: o painel funciona
// com ou sem ela, então a presença é checada em vez de assumida.
function oxigenacao_coluna_quitacao_existe(PDO $pdo) {
    $col = OXI_COL_DATA_QUITACAO;
    $tabela = OXI_TB_PRECATORIO;
    try {
        $pdo->query("SELECT {$col} FROM {$tabela} WHERE 1 = 0")->fetchAll();
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

// Ponto único de decisão do que é "pendente de pagamento" na data escolhida:
// nunca quitado, ou quitado depois dela. As fontes de data são consultadas na
// ordem descrita no topo do arquivo. Precatório quitado sem data em nenhuma
// fonte fica de fora — a contagem desses casos vai para
// oxigenacao_cobertura_quitacao(), para a tela mostrar o tamanho da incerteza.
//
// A comparação usa o dia seguinte para dar o mesmo resultado quer a data esteja
// gravada como data pura, quer com hora junto.
function oxigenacao_condicao_pendente(PDO $pdo, array $filtros, array &$params, $alias = 'p', $aliasQuit = 'q') {
    $limite = oxigenacao_dia_seguinte($filtros['data_ref']);
    $col = OXI_COL_DATA_QUITACAO;

    if (!oxigenacao_coluna_quitacao_existe($pdo)) {
        $params[] = $limite;
        return "({$alias}.prec_pg IS NULL OR {$aliasQuit}.DataRodadaQuitacao >= ?)";
    }

    $params[] = $limite;
    $params[] = $limite;
    return "({$alias}.prec_pg IS NULL
             OR ({$alias}.{$col} IS NOT NULL AND {$alias}.{$col} <> '' AND {$alias}.{$col} >= ?)
             OR (({$alias}.{$col} IS NULL OR {$alias}.{$col} = '') AND {$aliasQuit}.DataRodadaQuitacao >= ?))";
}

function oxigenacao_filtro_pendente_pagamento(PDO $pdo, array $filtros, array &$params, $alias = 'p', $aliasQuit = 'q') {
    if (empty($filtros['somente_pendentes'])) {
        return '';
    }
    return ' AND ' . oxigenacao_condicao_pendente($pdo, $filtros, $params, $alias, $aliasQuit);
}

// Precatório que já existia na data escolhida.
function oxigenacao_filtro_existia_na_data(array $filtros, array &$params, $alias = 'p') {
    $params[] = oxigenacao_dia_seguinte($filtros['data_ref']);
    return "({$alias}.DataCadastra IS NULL OR {$alias}.DataCadastra < ?)";
}

// Quanto da reconstrução de quitação é confiável para os filtros escolhidos:
// quantos quitados têm data exata, quantos têm só a data do lote, quantos não
// têm nenhuma — e quantos estão pendentes hoje, que é a referência com que a
// tela compara o número reconstruído.
function oxigenacao_cobertura_quitacao(PDO $pdo, array $filtros) {
    $precatorio = OXI_TB_PRECATORIO;
    $batch      = OXI_TB_BATCH;
    $col        = OXI_COL_DATA_QUITACAO;
    $temColuna  = oxigenacao_coluna_quitacao_existe($pdo);

    $inicio = $pdo->query("
        SELECT MIN(b.data_batch) AS Inicio
        FROM {$batch} b
        WHERE b.n_batch_quit IS NOT NULL
          AND CAST(b.n_batch_quit AS SIGNED) <> 0
          AND b.data_batch IS NOT NULL
          AND b.data_batch <> ''
    ")->fetch();

    // Sem a coluna, nenhum precatório entra no balde "data exata".
    $temDataExata = $temColuna ? "(p.{$col} IS NOT NULL AND p.{$col} <> '')" : '(1 = 0)';

    $sql = '
        SELECT ' . OXI_HINT_TIMEOUT . "
            SUM(CASE WHEN p.prec_pg IS NULL THEN 1 ELSE 0 END) AS PendentesHoje,
            SUM(CASE WHEN p.prec_pg IS NOT NULL AND {$temDataExata} THEN 1 ELSE 0 END) AS ComDataExata,
            SUM(CASE WHEN p.prec_pg IS NOT NULL AND NOT {$temDataExata}
                          AND q.DataRodadaQuitacao IS NOT NULL THEN 1 ELSE 0 END) AS ComDataLote,
            SUM(CASE WHEN p.prec_pg IS NOT NULL AND NOT {$temDataExata}
                          AND q.DataRodadaQuitacao IS NULL THEN 1 ELSE 0 END) AS SemData
        FROM {$precatorio} p
    " . oxigenacao_join_quitacao() . '
        WHERE 1 = 1
    ';
    $params = [];
    $sql .= oxigenacao_where_precatorio($filtros, $params);

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $linha = $stmt->fetch();

    return [
        'inicio_historico'  => $inicio['Inicio'] ?? null,
        'tem_coluna_data'   => $temColuna,
        'coluna_data'       => $col,
        'pendentes_hoje'    => (int)($linha['PendentesHoje'] ?? 0),
        'quitados_data_exata' => (int)($linha['ComDataExata'] ?? 0),
        'quitados_data_lote'  => (int)($linha['ComDataLote'] ?? 0),
        'quitados_sem_data'   => (int)($linha['SemData'] ?? 0),
    ];
}

// Último contato de cada precatório até a data, com o status que ele deixou.
// Só entram precatórios que tiveram contato — que é uma fração pequena da base,
// e é justamente o que torna a reconstrução barata.
function oxigenacao_sql_ultimo_contato() {
    $historico = OXI_TB_HISTORICO;
    return "
        SELECT h2.PrecatorioId AS PrecatorioId, h2.ResultContatoId AS ResultContatoId
        FROM {$historico} h2
        JOIN (
            SELECT h3.PrecatorioId AS PrecatorioId, MAX(h3.historicoContato_id) AS UltId
            FROM {$historico} h3
            JOIN (
                SELECT h.PrecatorioId AS PrecatorioId, MAX(h.DataContato) AS UltData
                FROM {$historico} h
                WHERE h.ResultContatoId IS NOT NULL
                  AND h.DataContato < ?
                GROUP BY h.PrecatorioId
            ) ultd ON ultd.PrecatorioId = h3.PrecatorioId AND h3.DataContato = ultd.UltData
            WHERE h3.ResultContatoId IS NOT NULL
            GROUP BY h3.PrecatorioId
        ) ulti ON ulti.UltId = h2.historicoContato_id
    ";
}

// Foto de hoje: o status atual da tabela é a resposta exata e dispensa o
// histórico de contato inteiro. Além de ser muito mais rápido, pega também as
// mudanças de status feitas fora do fluxo de contato.
function oxigenacao_foto_status_atual(PDO $pdo, array $filtros) {
    $precatorio = OXI_TB_PRECATORIO;
    $status     = OXI_TB_STATUS;

    $params = [];
    $sql = '
        SELECT ' . OXI_HINT_TIMEOUT . "
            p.StatusId AS StatusId,
            s.Status   AS StatusNome,
            s.ParentId AS ParentId,
            COUNT(*)   AS Qtd,
            COALESCE(SUM(CAST(p.ValorPrec AS DECIMAL(15,2))), 0) AS Valor
        FROM {$precatorio} p
        LEFT JOIN {$status} s ON s.statusPrecatorio_id = p.StatusId
    " . oxigenacao_join_quitacao_se_preciso($filtros) . '
        WHERE ' . oxigenacao_filtro_existia_na_data($filtros, $params);

    $sql .= oxigenacao_where_precatorio($filtros, $params);
    $sql .= oxigenacao_filtro_pendente_pagamento($pdo, $filtros, $params);
    $sql .= ' GROUP BY p.StatusId, s.Status, s.ParentId';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

// Foto de uma data passada: o status vem do último contato até aquele dia.
// A consulta é dirigida pelo conjunto de precatórios COM contato (pequeno) e
// alcança a tabela Precatorio pela chave primária; o resto da base, que nunca
// teve contato, é "Sem Tentativa" e sai por diferença de uma única contagem.
function oxigenacao_foto_reconstruida(PDO $pdo, array $filtros) {
    $precatorio = OXI_TB_PRECATORIO;
    $status     = OXI_TB_STATUS;
    $limite     = oxigenacao_dia_seguinte($filtros['data_ref']);

    // Aqui não há filtro de família: um contato pode devolver o precatório
    // para "Sem Tentativa", e essa volta precisa aparecer na foto.
    $params = [$limite];
    $sql = '
        SELECT ' . OXI_HINT_TIMEOUT . "
            ult.ResultContatoId AS StatusId,
            s.Status            AS StatusNome,
            s.ParentId          AS ParentId,
            COUNT(*)            AS Qtd,
            COALESCE(SUM(CAST(p.ValorPrec AS DECIMAL(15,2))), 0) AS Valor
        FROM (" . oxigenacao_sql_ultimo_contato() . ") ult
        JOIN {$precatorio} p ON p.precatorio_id = ult.PrecatorioId
        LEFT JOIN {$status} s ON s.statusPrecatorio_id = ult.ResultContatoId
    " . oxigenacao_join_quitacao_se_preciso($filtros) . '
        WHERE ' . oxigenacao_filtro_existia_na_data($filtros, $params);

    $sql .= oxigenacao_where_precatorio($filtros, $params);
    $sql .= oxigenacao_filtro_pendente_pagamento($pdo, $filtros, $params);
    $sql .= ' GROUP BY ult.ResultContatoId, s.Status, s.ParentId';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $linhas = $stmt->fetchAll();

    // Universo da data, para tirar por diferença quem nunca teve contato.
    $paramsUniverso = [];
    $sqlUniverso = '
        SELECT ' . OXI_HINT_TIMEOUT . "
            COUNT(*) AS Qtd,
            COALESCE(SUM(CAST(p.ValorPrec AS DECIMAL(15,2))), 0) AS Valor
        FROM {$precatorio} p
    " . oxigenacao_join_quitacao_se_preciso($filtros) . '
        WHERE ' . oxigenacao_filtro_existia_na_data($filtros, $paramsUniverso);
    $sqlUniverso .= oxigenacao_where_precatorio($filtros, $paramsUniverso);
    $sqlUniverso .= oxigenacao_filtro_pendente_pagamento($pdo, $filtros, $paramsUniverso);

    $stmt = $pdo->prepare($sqlUniverso);
    $stmt->execute($paramsUniverso);
    $universo = $stmt->fetch();

    $comContatoQtd = array_sum(array_map('intval', array_column($linhas, 'Qtd')));
    $comContatoValor = array_sum(array_map('floatval', array_column($linhas, 'Valor')));

    $semContatoQtd = (int)($universo['Qtd'] ?? 0) - $comContatoQtd;
    $semContatoValor = (float)($universo['Valor'] ?? 0) - $comContatoValor;

    if ($semContatoQtd > 0) {
        $linhas[] = [
            'StatusId'   => null,
            'StatusNome' => null,
            'ParentId'   => null,
            'Qtd'        => $semContatoQtd,
            'Valor'      => max(0.0, $semContatoValor),
        ];
    }

    return $linhas;
}

// Quebra por ente e por consultor de cada status da foto, para o detalhamento
// ao clicar numa fatia. Uma consulta só: agrupa por status/ente/consultor e o
// PHP colapsa nos dois recortes. Os status da família "Sem Tentativa" ficam de
// fora porque não vão ao gráfico — e é justamente o que torna esta consulta
// barata, já que são a maior parte da base.
function oxigenacao_foto_cruzamentos(PDO $pdo, array $filtros) {
    $precatorio = OXI_TB_PRECATORIO;
    $ente       = OXI_TB_ENTE;
    $usuario    = OXI_TB_USUARIO;

    $semTentativa = oxigenacao_status_sem_tentativa($pdo);
    $ph = oxigenacao_placeholders($semTentativa);
    $usaStatusAtual = $filtros['data_ref'] >= date('Y-m-d');

    $colunaStatus = $usaStatusAtual ? 'p.StatusId' : 'ult.ResultContatoId';
    $params = [];
    $origem = '';

    if (!$usaStatusAtual) {
        $params[] = oxigenacao_dia_seguinte($filtros['data_ref']);
        $origem = 'FROM (' . oxigenacao_sql_ultimo_contato() . ") ult
                   JOIN {$precatorio} p ON p.precatorio_id = ult.PrecatorioId ";
    } else {
        $origem = "FROM {$precatorio} p ";
    }

    $interno = "
        SELECT
            {$colunaStatus} AS StatusId,
            p.EnteId        AS EnteId,
            p.Negociador    AS Negociador,
            COUNT(*)        AS Qtd,
            COALESCE(SUM(CAST(p.ValorPrec AS DECIMAL(15,2))), 0) AS Valor
        {$origem}
    " . oxigenacao_join_quitacao_se_preciso($filtros) . '
        WHERE ' . oxigenacao_filtro_existia_na_data($filtros, $params);

    $interno .= oxigenacao_where_precatorio($filtros, $params);
    $interno .= oxigenacao_filtro_pendente_pagamento($pdo, $filtros, $params);
    // NULL cai fora junto: sem status, o precatório é rotulado "Sem Tentativa"
    // na pizza, que é exatamente o que este cruzamento não cobre.
    $interno .= " AND {$colunaStatus} NOT IN ({$ph})";
    $params = array_merge($params, $semTentativa);
    $interno .= " GROUP BY {$colunaStatus}, p.EnteId, p.Negociador";

    // Os nomes entram depois da agregação, sobre um punhado de linhas.
    $sql = '
        SELECT ' . OXI_HINT_TIMEOUT . "
            c.StatusId, c.EnteId, c.Negociador, c.Qtd, c.Valor,
            e.Ente      AS Ente,
            u.FirstName AS Consultor
        FROM ({$interno}) c
        LEFT JOIN {$ente} e    ON e.ente_id = c.EnteId
        LEFT JOIN {$usuario} u ON u.usuario_id = c.Negociador
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $porEnte = [];
    $porConsultor = [];
    foreach ($stmt->fetchAll() as $linha) {
        $statusId = (string)$linha['StatusId'];
        $valor = (float)$linha['Valor'];
        $qtd = (int)$linha['Qtd'];

        if (!isset($porEnte[$statusId])) {
            $porEnte[$statusId] = [];
            $porConsultor[$statusId] = [];
        }
        oxigenacao_somar_agregado($porEnte[$statusId], $linha['EnteId'],
            $linha['Ente'] !== null ? $linha['Ente'] : '(sem ente)', $qtd, $valor);
        oxigenacao_somar_agregado($porConsultor[$statusId], $linha['Negociador'],
            $linha['Consultor'] !== null ? $linha['Consultor'] : '(sem consultor)', $qtd, $valor);
    }

    foreach ($porEnte as $statusId => $entes) {
        $porEnte[$statusId] = oxigenacao_ordenar_agregado($entes, 'qtd');
        $porConsultor[$statusId] = oxigenacao_ordenar_agregado($porConsultor[$statusId], 'qtd');
    }

    return ['por_status_ente' => $porEnte, 'por_status_consultor' => $porConsultor];
}

// Quantidade e valor de precatórios em cada status na data escolhida.
function oxigenacao_foto_por_data(PDO $pdo, array $filtros) {
    $usaStatusAtual = $filtros['data_ref'] >= date('Y-m-d');

    $linhas = $usaStatusAtual
        ? oxigenacao_foto_status_atual($pdo, $filtros)
        : oxigenacao_foto_reconstruida($pdo, $filtros);

    // Rótulos iguais são o mesmo status e viram uma linha só: o precatório sem
    // contato nenhum e o resultado de contato "Sem Tentativa" (id 65) descrevem
    // a mesma situação.
    $porRotulo = [];
    $totalQtd = 0;
    $totalValor = 0.0;
    $semTentativaQtd = 0;
    $semTentativaValor = 0.0;
    foreach ($linhas as $linha) {
        $qtd    = (int)$linha['Qtd'];
        $valor  = (float)$linha['Valor'];
        $rotulo = oxigenacao_rotulo_status($linha['StatusId'], $linha['StatusNome']);
        $totalQtd += $qtd;
        $totalValor += $valor;

        // "Sem Tentativa" é a única faixa aproximada da foto (sai por diferença,
        // de quem nunca teve contato) e costuma ser a maioria da base, então é
        // contabilizada à parte para não engolir o resto do gráfico.
        if ($rotulo === OXI_ROTULO_SEM_TENTATIVA) {
            $semTentativaQtd += $qtd;
            $semTentativaValor += $valor;
        }

        if (!isset($porRotulo[$rotulo])) {
            $porRotulo[$rotulo] = [
                'StatusId'     => null,
                'Status'       => $rotulo,
                'ParentId'     => null,
                'SemTentativa' => $rotulo === OXI_ROTULO_SEM_TENTATIVA,
                'Qtd'          => 0,
                'Valor'        => 0.0,
            ];
        }
        $porRotulo[$rotulo]['Qtd'] += $qtd;
        $porRotulo[$rotulo]['Valor'] += $valor;

        // Guarda a identidade do status conhecido, quando houver.
        if ($linha['StatusId'] !== null && $porRotulo[$rotulo]['StatusId'] === null) {
            $porRotulo[$rotulo]['StatusId'] = (int)$linha['StatusId'];
            $porRotulo[$rotulo]['ParentId'] = $linha['ParentId'] === null ? null : (int)$linha['ParentId'];
        }
    }

    $resultado = array_values($porRotulo);
    usort($resultado, function ($a, $b) {
        return $b['Qtd'] <=> $a['Qtd'];
    });

    return [
        'linhas' => $resultado,
        'totais' => [
            'qtd'                 => $totalQtd,
            'valor'               => $totalValor,
            'sem_tentativa_qtd'   => $semTentativaQtd,
            'sem_tentativa_valor' => $semTentativaValor,
            'outros_qtd'          => $totalQtd - $semTentativaQtd,
            'outros_valor'        => $totalValor - $semTentativaValor,
        ],
        'usa_status_atual' => $usaStatusAtual,
    ];
}

// ---------------------------------------------------------------------------
// Opções dos filtros
// ---------------------------------------------------------------------------

// Mapa statusPrecatorio_id => nome e pai, usado no cliente para rotular os
// cruzamentos e para agrupar os status filhos sob o nome do status pai.
function oxigenacao_mapa_status(PDO $pdo) {
    $sql = 'SELECT statusPrecatorio_id, Status, ParentId FROM ' . OXI_TB_STATUS;
    $mapa = [];
    foreach ($pdo->query($sql)->fetchAll() as $linha) {
        $mapa[(string)$linha['statusPrecatorio_id']] = [
            'nome' => $linha['Status'],
            'pai'  => $linha['ParentId'] === null ? null : (string)$linha['ParentId'],
        ];
    }
    return $mapa;
}

// Orcamento é varchar e tem lixo gravado ("NULL", "24"). Oferecer esses valores
// no filtro só produziria erro de validação, então a lista guarda apenas anos
// dentro da faixa que o filtro aceita.
function oxigenacao_opcoes_orcamento(PDO $pdo) {
    $sql = 'SELECT DISTINCT Orcamento FROM ' . OXI_TB_PRECATORIO . '
            WHERE Orcamento IS NOT NULL ORDER BY Orcamento DESC';

    $anos = [];
    foreach ($pdo->query($sql)->fetchAll() as $linha) {
        try {
            prospeccao_sanitize_orcamento($linha['Orcamento']);
        } catch (InvalidArgumentException $e) {
            continue;
        }
        $anos[] = $linha;
    }
    return $anos;
}

function oxigenacao_opcoes_natureza(PDO $pdo) {
    $sql = 'SELECT natuPrec_id, Natureza FROM ' . OXI_TB_NATUREZA . ' ORDER BY Natureza';
    return $pdo->query($sql)->fetchAll();
}

// Consultores vêm da tabela de usuários, não de um DISTINCT sobre a view: a
// lista é montada a cada abertura da página e a view é cara de varrer.
// Só perfil de consultor entra; os inativos vêm junto marcados, para a tela
// poder mostrá-los ou não sem ir ao banco de novo.
function oxigenacao_opcoes_consultor(PDO $pdo) {
    $sql = 'SELECT usuario_id AS Negociador, FirstName, LastName, Active
            FROM ' . OXI_TB_USUARIO . '
            WHERE PerfilId = ' . OXI_PERFIL_CONSULTOR . '
              AND FirstName IS NOT NULL
            ORDER BY Active DESC, FirstName';
    return $pdo->query($sql)->fetchAll();
}
