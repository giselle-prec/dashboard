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
// - prec_pg (quitado x pendente de pagamento) é estado atual, sem data de virada.
//
// Desempenho: as consultas varrem HistoricoContato agrupando por precatório e
// data. Se o painel ficar lento, os índices que resolvem são
// (PrecatorioId, DataContato) e (ResultContatoId) em HistoricoContato.

require_once __DIR__ . '/prospeccao_repository.php';

// Nomes de tabela isolados em constantes sobrescrevíveis (o harness de teste
// aponta as constantes para tabelas locais antes de incluir este arquivo).
defined('OXI_TB_HISTORICO') || define('OXI_TB_HISTORICO', 'precappapp.HistoricoContato');
defined('OXI_TB_STATUS')    || define('OXI_TB_STATUS',    'precappapp.StatusPrecatorio');
defined('OXI_TB_DETALHE')   || define('OXI_TB_DETALHE',   'precappapp.precatoriodetalhe');

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

    if ($filtros['ente_id']) {
        $sql .= " AND {$alias}.ente_id IN (" . oxigenacao_placeholders($filtros['ente_id']) . ')';
        $params = array_merge($params, $filtros['ente_id']);
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
        $sql .= " AND {$alias}.Datarecebimento < ?";
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

// Soma um evento em um balde de agregação.
function oxigenacao_acumular(array &$destino, $chave, $rotulo, $valor) {
    if (!isset($destino[$chave])) {
        $destino[$chave] = ['rotulo' => $rotulo, 'qtd' => 0, 'valor' => 0.0];
    }
    $destino[$chave]['qtd']++;
    $destino[$chave]['valor'] += $valor;
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
    $valorTotal = 0.0;
    $entes = [];

    foreach ($eventos as $evento) {
        $valor = (float)$evento['ValorPrec'];
        $valorTotal += $valor;
        $entes[$evento['ente_id']] = true;

        oxigenacao_acumular($porDia, $evento['DataOxigenacao'], $evento['DataOxigenacao'], $valor);
        oxigenacao_acumular($porEnte, $evento['ente_id'], $evento['Ente'], $valor);
        oxigenacao_acumular($porConsultor, $evento['ConsultorId'], $evento['Consultor'], $valor);
        oxigenacao_acumular($porStatus, $evento['StatusDestino'], $evento['StatusDestino'], $valor);
    }

    ksort($porDia);

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
    ];
}

// Base ainda não oxigenada (status atual dentro da família "Sem Tentativa"),
// para dar denominador ao número do período.
function oxigenacao_base_sem_tentativa(PDO $pdo, array $filtros) {
    $semTentativa = oxigenacao_status_sem_tentativa($pdo);
    $ph = oxigenacao_placeholders($semTentativa);
    $detalhe = OXI_TB_DETALHE;

    $sql = "
        SELECT
            COUNT(*) AS Qtd,
            COALESCE(SUM(CAST(p.ValorPrec AS DECIMAL(15,2))), 0) AS Valor
        FROM {$detalhe} p
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

// Ponto único de decisão do que é "pendente de pagamento". Hoje usa o estado
// atual (prec_pg IS NULL), porque não existe data de quitação no histórico de
// contato. Quando a tabela de alterações em lote estiver disponível, é aqui que
// a reconstrução por data entra.
function oxigenacao_filtro_pendente_pagamento(array $filtros, array &$params, $alias = 'p') {
    if (empty($filtros['somente_pendentes'])) {
        return '';
    }
    return " AND {$alias}.prec_pg IS NULL";
}

// Quantidade e valor de precatórios em cada status na data escolhida.
// O status na data é o resultado do último contato até aquele dia; sem contato,
// o precatório é considerado "Sem Tentativa".
function oxigenacao_foto_por_data(PDO $pdo, array $filtros) {
    $historico = OXI_TB_HISTORICO;
    $detalhe   = OXI_TB_DETALHE;
    $status    = OXI_TB_STATUS;
    $limite    = oxigenacao_dia_seguinte($filtros['data_ref']);

    // Aqui não há filtro de família: um contato pode devolver o precatório
    // para "Sem Tentativa", e essa volta precisa aparecer na foto.
    $sql = "
        SELECT
            ult.ResultContatoId AS StatusId,
            s.Status            AS StatusNome,
            s.ParentId          AS ParentId,
            COUNT(*)            AS Qtd,
            COALESCE(SUM(CAST(p.ValorPrec AS DECIMAL(15,2))), 0) AS Valor
        FROM {$detalhe} p
        LEFT JOIN (
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
        ) ult ON ult.PrecatorioId = p.precatorio_id
        LEFT JOIN {$status} s ON s.statusPrecatorio_id = ult.ResultContatoId
        WHERE (p.DataCadastra IS NULL OR p.DataCadastra < ?)
    ";

    $params = [$limite, $limite];
    $sql .= oxigenacao_where_precatorio($filtros, $params);
    $sql .= oxigenacao_filtro_pendente_pagamento($filtros, $params);
    $sql .= ' GROUP BY ult.ResultContatoId, s.Status, s.ParentId';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $linhas = $stmt->fetchAll();

    // Rótulos iguais são o mesmo status e viram uma linha só: o precatório sem
    // contato nenhum e o resultado de contato "Sem Tentativa" (id 65) descrevem
    // a mesma situação.
    $porRotulo = [];
    $totalQtd = 0;
    $totalValor = 0.0;
    foreach ($linhas as $linha) {
        $qtd    = (int)$linha['Qtd'];
        $valor  = (float)$linha['Valor'];
        $rotulo = oxigenacao_rotulo_status($linha['StatusId'], $linha['StatusNome']);
        $totalQtd += $qtd;
        $totalValor += $valor;

        if (!isset($porRotulo[$rotulo])) {
            $porRotulo[$rotulo] = [
                'StatusId' => null,
                'Status'   => $rotulo,
                'ParentId' => null,
                'Qtd'      => 0,
                'Valor'    => 0.0,
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
        'totais' => ['qtd' => $totalQtd, 'valor' => $totalValor],
    ];
}

// ---------------------------------------------------------------------------
// Opções dos filtros
// ---------------------------------------------------------------------------

// Mapa statusPrecatorio_id => nome, usado no cliente para agrupar os status
// filhos sob o nome do status pai.
function oxigenacao_mapa_status(PDO $pdo) {
    $sql = 'SELECT statusPrecatorio_id, Status FROM ' . OXI_TB_STATUS;
    $mapa = [];
    foreach ($pdo->query($sql)->fetchAll() as $linha) {
        $mapa[(string)$linha['statusPrecatorio_id']] = $linha['Status'];
    }
    return $mapa;
}

function oxigenacao_opcoes_orcamento(PDO $pdo) {
    $sql = 'SELECT DISTINCT Orcamento FROM ' . OXI_TB_DETALHE . '
            WHERE Orcamento IS NOT NULL ORDER BY Orcamento DESC';
    return $pdo->query($sql)->fetchAll();
}

// Consultores derivados da própria view (mesma origem do filtro: o consultor
// atual do precatório). Se o DISTINCT ficar pesado em produção, trocar por uma
// consulta à tabela de usuários — é o único ponto que precisa mudar.
function oxigenacao_opcoes_consultor(PDO $pdo) {
    $sql = 'SELECT DISTINCT Negociador, FirstName, LastName FROM ' . OXI_TB_DETALHE . '
            WHERE Negociador IS NOT NULL AND FirstName IS NOT NULL
            ORDER BY FirstName';
    return $pdo->query($sql)->fetchAll();
}
