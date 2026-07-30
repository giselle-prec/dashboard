<?php

// Consultas de suporte ao painel de prospecção (public/prospeccao.php).
// Regras de negócio replicadas da query original fornecida:
// - StatusId 66, 72, 74 e 75 são sempre excluídos do pipeline de prospecção.
// - RequisitorioId 1 ou 3 (ou nulo) é considerado "sem requisitório".
// - Orçamento aceita o ano informado OU o ano seguinte quando NaturezaId = 2.
// - "Pendente de prospecção" = StatusPrec = 'Sem Tentativa'.

const PROSPECCAO_STATUS_EXCLUIDOS = ['66', '72', '74', '75'];
const PROSPECCAO_STATUS_PENDENTE = 'Sem Tentativa';

function prospeccao_sanitize_ente_id($raw) {
    if (!is_numeric($raw) || (int)$raw <= 0) {
        throw new InvalidArgumentException('Ente inválido.');
    }
    return (int)$raw;
}

function prospeccao_sanitize_orcamento($raw) {
    if (!ctype_digit((string)$raw)) {
        throw new InvalidArgumentException('Orçamento inválido.');
    }
    $ano = (int)$raw;
    if ($ano < 2000 || $ano > 2100) {
        throw new InvalidArgumentException('Orçamento fora do intervalo permitido.');
    }
    return $ano;
}

function prospeccao_sanitize_data($raw) {
    $raw = trim((string)$raw);
    $data = DateTime::createFromFormat('Y-m-d', $raw);
    if (!$data || $data->format('Y-m-d') !== $raw) {
        throw new InvalidArgumentException('Data de previsão de pagamento inválida.');
    }
    return $raw;
}

function prospeccao_sanitize_valor_min($raw) {
    if (!is_numeric($raw) || (float)$raw < 0) {
        throw new InvalidArgumentException('Valor mínimo inválido.');
    }
    return number_format((float)$raw, 2, '.', '');
}

// Normaliza e valida todos os filtros vindos do formulário/API.
function prospeccao_parse_filtros(array $input) {
    return [
        'ente_id'        => prospeccao_sanitize_ente_id($input['ente_id'] ?? null),
        'orcamento'      => prospeccao_sanitize_orcamento($input['orcamento'] ?? null),
        'data_max'       => prospeccao_sanitize_data($input['data_max'] ?? null),
        'valor_min'      => prospeccao_sanitize_valor_min($input['valor_min'] ?? null),
        'por_consultora' => !empty($input['por_consultora']),
    ];
}

function prospeccao_status_excluidos_placeholders() {
    return implode(',', array_fill(0, count(PROSPECCAO_STATUS_EXCLUIDOS), '?'));
}

// Painel geral: total do universo (todos os precatórios do ente/orçamento) +
// quebra do pipeline de prospecção (prospectados / pendentes com e sem requisitório).
function prospeccao_resumo_geral(PDO $pdo, array $filtros) {
    $orcamentoProximo = $filtros['orcamento'] + 1;

    $sqlTotal = "
        SELECT
            COUNT(Precatorio) AS QtdTotal,
            COALESCE(SUM(CAST(ValorPrec AS DECIMAL(15,2))), 0) AS ValorTotal
        FROM precappapp.precatoriodetalhe
        WHERE ente_id = ?
          AND (Orcamento = ? OR (Orcamento = ? AND NaturezaId = '2'))
    ";
    $stmt = $pdo->prepare($sqlTotal);
    $stmt->execute([$filtros['ente_id'], $filtros['orcamento'], $orcamentoProximo]);
    $total = $stmt->fetch();

    $statusPlaceholders = prospeccao_status_excluidos_placeholders();
    $sqlPipeline = "
        SELECT
            SUM(CASE WHEN StatusPrec <> ? THEN 1 ELSE 0 END) AS QtdProspectados,
            SUM(CASE WHEN StatusPrec <> ? THEN CAST(ValorPrec AS DECIMAL(15,2)) ELSE 0 END) AS ValorProspectados,
            SUM(CASE WHEN StatusPrec = ? AND RequisitorioId NOT IN (1, 3) THEN 1 ELSE 0 END) AS QtdPendenteComReq,
            SUM(CASE WHEN StatusPrec = ? AND RequisitorioId NOT IN (1, 3) THEN CAST(ValorPrec AS DECIMAL(15,2)) ELSE 0 END) AS ValorPendenteComReq,
            SUM(CASE WHEN StatusPrec = ? AND (RequisitorioId IS NULL OR RequisitorioId IN (1, 3)) THEN 1 ELSE 0 END) AS QtdPendenteSemReq,
            SUM(CASE WHEN StatusPrec = ? AND (RequisitorioId IS NULL OR RequisitorioId IN (1, 3)) THEN CAST(ValorPrec AS DECIMAL(15,2)) ELSE 0 END) AS ValorPendenteSemReq
        FROM precappapp.precatoriodetalhe
        WHERE ente_id = ?
          AND StatusId NOT IN ({$statusPlaceholders})
          AND prec_pg IS NULL
          AND (Orcamento = ? OR (Orcamento = ? AND NaturezaId = '2'))
    ";
    $params = array_merge(
        [PROSPECCAO_STATUS_PENDENTE, PROSPECCAO_STATUS_PENDENTE, PROSPECCAO_STATUS_PENDENTE, PROSPECCAO_STATUS_PENDENTE, PROSPECCAO_STATUS_PENDENTE, PROSPECCAO_STATUS_PENDENTE],
        [$filtros['ente_id']],
        PROSPECCAO_STATUS_EXCLUIDOS,
        [$filtros['orcamento'], $orcamentoProximo]
    );
    $stmt = $pdo->prepare($sqlPipeline);
    $stmt->execute($params);
    $pipeline = $stmt->fetch();

    return [
        'qtd_total'             => (int)($total['QtdTotal'] ?? 0),
        'valor_total'           => (float)($total['ValorTotal'] ?? 0),
        'qtd_prospectados'      => (int)($pipeline['QtdProspectados'] ?? 0),
        'valor_prospectados'    => (float)($pipeline['ValorProspectados'] ?? 0),
        'qtd_pendente_com_req'  => (int)($pipeline['QtdPendenteComReq'] ?? 0),
        'valor_pendente_com_req'=> (float)($pipeline['ValorPendenteComReq'] ?? 0),
        'qtd_pendente_sem_req'  => (int)($pipeline['QtdPendenteSemReq'] ?? 0),
        'valor_pendente_sem_req'=> (float)($pipeline['ValorPendenteSemReq'] ?? 0),
    ];
}

// Detalhe por StatusPrec (e opcionalmente por consultora/FirstName), equivalente à
// query original fornecida, com filtros de ente/orçamento/data/valor mínimo.
function prospeccao_detalhe(PDO $pdo, array $filtros) {
    $orcamentoProximo = $filtros['orcamento'] + 1;
    $porConsultora = $filtros['por_consultora'];

    $selectConsultora = $porConsultora ? "FirstName,\n            " : '';
    $groupByConsultora = $porConsultora ? ', FirstName' : '';
    $orderByConsultora = $porConsultora ? ', FirstName' : '';
    $statusPlaceholders = prospeccao_status_excluidos_placeholders();

    $sql = "
        SELECT
            Ente,
            StatusPrec,
            StatusId,
            {$selectConsultora}COUNT(Precatorio) AS QuantidadeTotal,
            SUM(CAST(ValorPrec AS DECIMAL(15,2))) AS ValorTotal,
            SUM(CASE WHEN RequisitorioId NOT IN (1, 3) THEN 1 ELSE 0 END) AS ComRequisitorio,
            SUM(CASE WHEN RequisitorioId NOT IN (1, 3) THEN CAST(ValorPrec AS DECIMAL(15,2)) ELSE 0 END) AS ValorComRequisitorio,
            SUM(CASE WHEN RequisitorioId IS NULL OR RequisitorioId IN (1, 3) THEN 1 ELSE 0 END) AS SemRequisitorio,
            SUM(CASE WHEN RequisitorioId IS NULL OR RequisitorioId IN (1, 3) THEN CAST(ValorPrec AS DECIMAL(15,2)) ELSE 0 END) AS ValorSemRequisitorio,
            SUM(CASE WHEN CAST(ValorPrec AS DECIMAL(15,2)) >= ? AND DataRecebimento < ? THEN 1 ELSE 0 END) AS QtdMelhores,
            SUM(CASE WHEN CAST(ValorPrec AS DECIMAL(15,2)) >= ? AND DataRecebimento < ? THEN CAST(ValorPrec AS DECIMAL(15,2)) ELSE 0 END) AS ValorMelhores,
            SUM(CASE WHEN CAST(ValorPrec AS DECIMAL(15,2)) >= ? AND DataRecebimento < ? AND RequisitorioId NOT IN (1, 3) THEN 1 ELSE 0 END) AS QtdMelhoresComReq,
            SUM(CASE WHEN CAST(ValorPrec AS DECIMAL(15,2)) >= ? AND DataRecebimento < ? AND RequisitorioId NOT IN (1, 3) THEN CAST(ValorPrec AS DECIMAL(15,2)) ELSE 0 END) AS ValorMelhoresComReq,
            SUM(CASE WHEN CAST(ValorPrec AS DECIMAL(15,2)) >= ? AND DataRecebimento < ? AND (RequisitorioId IS NULL OR RequisitorioId IN (1, 3)) THEN 1 ELSE 0 END) AS QtdMelhoresSemReq,
            SUM(CASE WHEN CAST(ValorPrec AS DECIMAL(15,2)) >= ? AND DataRecebimento < ? AND (RequisitorioId IS NULL OR RequisitorioId IN (1, 3)) THEN CAST(ValorPrec AS DECIMAL(15,2)) ELSE 0 END) AS ValorMelhoresSemReq
        FROM precappapp.precatoriodetalhe
        WHERE ente_id = ?
          AND StatusId NOT IN ({$statusPlaceholders})
          AND prec_pg IS NULL
          AND (Orcamento = ? OR (Orcamento = ? AND NaturezaId = '2'))
        GROUP BY StatusPrec{$groupByConsultora}
        ORDER BY StatusPrec DESC{$orderByConsultora}
    ";

    $melhoresPar = [$filtros['valor_min'], $filtros['data_max']];
    $params = array_merge(
        $melhoresPar, $melhoresPar, $melhoresPar, $melhoresPar, $melhoresPar, $melhoresPar,
        [$filtros['ente_id']],
        PROSPECCAO_STATUS_EXCLUIDOS,
        [$filtros['orcamento'], $orcamentoProximo]
    );

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}
