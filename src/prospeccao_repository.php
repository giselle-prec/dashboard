<?php

// Consultas de suporte ao painel de prospecção (public/prospeccao.php).
// Regras de negócio replicadas da query original fornecida:
// - StatusId 66, 72, 74 e 75 são sempre excluídos do pipeline de prospecção.
// - RequisitorioId 1 ou 3 (ou nulo) é considerado "sem requisitório".
// - Quando um orçamento é informado, aceita o ano informado OU o ano seguinte
//   quando NaturezaId = 2 (carry-over orçamentário). Sem orçamento informado,
//   nenhum filtro de ano é aplicado (todos os orçamentos).
// - Natureza (NaturezaId) é um filtro independente e opcional.
// - "Pendente de prospecção" = StatusPrec = 'Sem Tentativa'.

const PROSPECCAO_STATUS_EXCLUIDOS = ['66', '72', '74', '75'];
const PROSPECCAO_STATUS_PENDENTE = 'Sem Tentativa';

function prospeccao_sanitize_ente_id($raw) {
    if (!is_numeric($raw) || (int)$raw <= 0) {
        throw new InvalidArgumentException('Ente inválido.');
    }
    return (int)$raw;
}

// Orçamento é opcional: string vazia/ausente = sem filtro (todos os orçamentos).
function prospeccao_sanitize_orcamento($raw) {
    if ($raw === null || $raw === '') {
        return null;
    }
    if (!ctype_digit((string)$raw)) {
        throw new InvalidArgumentException('Orçamento inválido.');
    }
    $ano = (int)$raw;
    if ($ano < 2000 || $ano > 2100) {
        throw new InvalidArgumentException('Orçamento fora do intervalo permitido.');
    }
    return $ano;
}

// Natureza também é opcional: string vazia/ausente = sem filtro (todas as naturezas).
function prospeccao_sanitize_natureza($raw) {
    if ($raw === null || $raw === '') {
        return null;
    }
    if (!ctype_digit((string)$raw)) {
        throw new InvalidArgumentException('Natureza inválida.');
    }
    return (int)$raw;
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
        'natureza_id'    => prospeccao_sanitize_natureza($input['natureza_id'] ?? null),
        'data_max'       => prospeccao_sanitize_data($input['data_max'] ?? null),
        'valor_min'      => prospeccao_sanitize_valor_min($input['valor_min'] ?? null),
        'por_consultora' => !empty($input['por_consultora']),
    ];
}

function prospeccao_status_excluidos_placeholders() {
    return implode(',', array_fill(0, count(PROSPECCAO_STATUS_EXCLUIDOS), '?'));
}

// Monta a cláusula WHERE comum (ente, pipeline de prospecção, orçamento e natureza)
// e devolve, por referência, os parâmetros na mesma ordem dos "?" gerados.
function prospeccao_build_where(array $filtros, $incluirPipeline, &$params) {
    $params = [$filtros['ente_id']];
    $clausulas = ['ente_id = ?'];

    if ($incluirPipeline) {
        $clausulas[] = 'StatusId NOT IN (' . prospeccao_status_excluidos_placeholders() . ')';
        foreach (PROSPECCAO_STATUS_EXCLUIDOS as $statusId) {
            $params[] = $statusId;
        }
        $clausulas[] = 'prec_pg IS NULL';
    }

    if ($filtros['orcamento'] !== null) {
        $clausulas[] = "(Orcamento = ? OR (Orcamento = ? AND NaturezaId = '2'))";
        $params[] = $filtros['orcamento'];
        $params[] = $filtros['orcamento'] + 1;
    }

    if ($filtros['natureza_id'] !== null) {
        $clausulas[] = 'NaturezaId = ?';
        $params[] = $filtros['natureza_id'];
    }

    return implode("\n          AND ", $clausulas);
}

// Naturezas distintas existentes na base, para popular o filtro do formulário.
function prospeccao_listar_naturezas(PDO $pdo) {
    $stmt = $pdo->query("
        SELECT DISTINCT NaturezaId
        FROM precappapp.precatoriodetalhe
        WHERE NaturezaId IS NOT NULL
        ORDER BY NaturezaId
    ");
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

// Painel geral: total do universo (todos os precatórios do ente/orçamento/natureza) +
// quebra do pipeline de prospecção (prospectados / pendentes com e sem requisitório).
function prospeccao_resumo_geral(PDO $pdo, array $filtros) {
    $whereTotal = prospeccao_build_where($filtros, false, $paramsTotal);
    $sqlTotal = "
        SELECT
            COUNT(Precatorio) AS QtdTotal,
            COALESCE(SUM(CAST(ValorPrec AS DECIMAL(15,2))), 0) AS ValorTotal
        FROM precappapp.precatoriodetalhe
        WHERE {$whereTotal}
    ";
    $stmt = $pdo->prepare($sqlTotal);
    $stmt->execute($paramsTotal);
    $total = $stmt->fetch();

    $wherePipeline = prospeccao_build_where($filtros, true, $paramsPipeline);
    $sqlPipeline = "
        SELECT
            SUM(CASE WHEN StatusPrec <> ? THEN 1 ELSE 0 END) AS QtdProspectados,
            SUM(CASE WHEN StatusPrec <> ? THEN CAST(ValorPrec AS DECIMAL(15,2)) ELSE 0 END) AS ValorProspectados,
            SUM(CASE WHEN StatusPrec = ? AND RequisitorioId NOT IN (1, 3) THEN 1 ELSE 0 END) AS QtdPendenteComReq,
            SUM(CASE WHEN StatusPrec = ? AND RequisitorioId NOT IN (1, 3) THEN CAST(ValorPrec AS DECIMAL(15,2)) ELSE 0 END) AS ValorPendenteComReq,
            SUM(CASE WHEN StatusPrec = ? AND (RequisitorioId IS NULL OR RequisitorioId IN (1, 3)) THEN 1 ELSE 0 END) AS QtdPendenteSemReq,
            SUM(CASE WHEN StatusPrec = ? AND (RequisitorioId IS NULL OR RequisitorioId IN (1, 3)) THEN CAST(ValorPrec AS DECIMAL(15,2)) ELSE 0 END) AS ValorPendenteSemReq
        FROM precappapp.precatoriodetalhe
        WHERE {$wherePipeline}
    ";
    $params = array_merge(
        array_fill(0, 6, PROSPECCAO_STATUS_PENDENTE),
        $paramsPipeline
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
// query original fornecida, com filtros de ente/orçamento/natureza/data/valor mínimo.
function prospeccao_detalhe(PDO $pdo, array $filtros) {
    $porConsultora = $filtros['por_consultora'];

    $selectConsultora = $porConsultora ? "FirstName,\n            " : '';
    $groupByConsultora = $porConsultora ? ', FirstName' : '';
    $orderByConsultora = $porConsultora ? ', FirstName' : '';
    $where = prospeccao_build_where($filtros, true, $whereParams);

    // Ente e StatusId entram no GROUP BY (junto de StatusPrec) para funcionar
    // tanto em servidores com sql_mode=ONLY_FULL_GROUP_BY quanto sem; não
    // altera o resultado, já que ente_id é fixo no WHERE e StatusId é 1:1 com StatusPrec.
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
        WHERE {$where}
        GROUP BY StatusPrec, StatusId, Ente{$groupByConsultora}
        ORDER BY StatusPrec DESC{$orderByConsultora}
    ";

    $melhoresPar = [$filtros['valor_min'], $filtros['data_max']];
    $params = array_merge(
        $melhoresPar, $melhoresPar, $melhoresPar, $melhoresPar, $melhoresPar, $melhoresPar,
        $whereParams
    );

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}
