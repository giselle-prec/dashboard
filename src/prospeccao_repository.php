<?php

// Consultas de suporte ao painel de prospecção (public/prospeccao.php).
// Regras de negócio replicadas da query original fornecida:
// - StatusId 66, 72, 74 e 75 são sempre excluídos do pipeline de prospecção.
// - RequisitorioId 1 ou 3 (ou nulo) é considerado "sem requisitório".
// - Ente, Orçamento e Natureza aceitam múltiplos valores (IN). Orçamento e
//   Natureza são opcionais: nenhum valor selecionado = sem filtro (todos).
//   Ente é obrigatório: ao menos um precisa ser selecionado.
// - "Pendente de prospecção" = StatusId = 65 (Sem Tentativa).
// - prec_pg IS NULL AND Active = 1 (pendente de pagamento e ativo no sistema)
//   é aplicado em TODAS as consultas do painel, inclusive no "Total de
//   Precatórios" — que portanto não é o universo bruto da tabela, e sim o
//   total de precatórios ativos e pendentes de pagamento do ente/orçamento.

const PROSPECCAO_STATUS_EXCLUIDOS = ['66', '72', '74', '75'];
const PROSPECCAO_STATUS_ID_PENDENTE = '65';

function prospeccao_placeholders($quantidade) {
    return implode(',', array_fill(0, $quantidade, '?'));
}

// Um <select multiple> com milhares de opções marcadas (ex.: "selecionar
// todos" os ~5.600 municípios) excede o max_input_vars do PHP se cada valor
// vier como um campo POST separado (ente_id[]=1&ente_id[]=2&...) — o PHP
// descarta os excedentes em silêncio. Por isso o front-end manda cada lista
// como uma única string JSON; aqui aceitamos JSON, array (uso direto/testes)
// ou valor único, sempre devolvendo uma lista plana.
function prospeccao_normalizar_lista($raw) {
    if (is_array($raw)) {
        return $raw;
    }
    if ($raw === null || $raw === '') {
        return [];
    }
    $decodificado = json_decode($raw, true);
    if (is_array($decodificado)) {
        return $decodificado;
    }
    return [$raw];
}

// Aceita um valor único, array ou string JSON. Devolve uma lista de inteiros positivos.
function prospeccao_sanitize_ente_ids($raw) {
    $valores = prospeccao_normalizar_lista($raw);
    $ids = [];
    foreach ($valores as $valor) {
        if ($valor === null || $valor === '') {
            continue;
        }
        if (!is_numeric($valor) || (int)$valor <= 0) {
            throw new InvalidArgumentException('Ente inválido.');
        }
        $ids[] = (int)$valor;
    }
    if (empty($ids)) {
        throw new InvalidArgumentException('Selecione ao menos um Ente.');
    }
    return array_values(array_unique($ids));
}

// Orçamento é opcional: nenhum valor selecionado = sem filtro (todos os orçamentos).
function prospeccao_sanitize_orcamentos($raw) {
    $valores = prospeccao_normalizar_lista($raw);
    $anos = [];
    foreach ($valores as $valor) {
        if ($valor === null || $valor === '') {
            continue;
        }
        if (!ctype_digit((string)$valor)) {
            throw new InvalidArgumentException('Orçamento inválido.');
        }
        $ano = (int)$valor;
        if ($ano < 2000 || $ano > 2100) {
            throw new InvalidArgumentException('Orçamento fora do intervalo permitido.');
        }
        $anos[] = $ano;
    }
    return array_values(array_unique($anos));
}

// Natureza também é opcional: nenhum valor selecionado = sem filtro (todas as naturezas).
function prospeccao_sanitize_naturezas($raw) {
    $valores = prospeccao_normalizar_lista($raw);
    $ids = [];
    foreach ($valores as $valor) {
        if ($valor === null || $valor === '') {
            continue;
        }
        if (!ctype_digit((string)$valor)) {
            throw new InvalidArgumentException('Natureza inválida.');
        }
        $ids[] = (int)$valor;
    }
    return array_values(array_unique($ids));
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
        'ente_ids'       => prospeccao_sanitize_ente_ids($input['ente_id'] ?? null),
        'orcamentos'     => prospeccao_sanitize_orcamentos($input['orcamento'] ?? null),
        'natureza_ids'   => prospeccao_sanitize_naturezas($input['natureza_id'] ?? null),
        'data_max'       => prospeccao_sanitize_data($input['data_max'] ?? null),
        'valor_min'      => prospeccao_sanitize_valor_min($input['valor_min'] ?? null),
        'por_consultora' => !empty($input['por_consultora']),
    ];
}

// Monta a cláusula WHERE comum (ente, pipeline de prospecção, orçamento e natureza)
// e devolve, por referência, os parâmetros na mesma ordem dos "?" gerados.
// Colunas sempre qualificadas com "precatoriodetalhe." porque
// prospeccao_detalhe() pode juntar a tabela Usuario (quando agrupado por
// consultora), que já se mostrou ter colunas de mesmo nome (FirstName,
// Active) — sem o prefixo, a query fica ambígua assim que o JOIN entra.
function prospeccao_build_where(array $filtros, $incluirPipeline, &$params) {
    $params = [];
    $clausulas = [
        'precatoriodetalhe.ente_id IN (' . prospeccao_placeholders(count($filtros['ente_ids'])) . ')',
        'precatoriodetalhe.prec_pg IS NULL',
        'precatoriodetalhe.Active = 1',
    ];
    foreach ($filtros['ente_ids'] as $enteId) {
        $params[] = $enteId;
    }

    if ($incluirPipeline) {
        $clausulas[] = 'precatoriodetalhe.StatusId NOT IN (' . prospeccao_placeholders(count(PROSPECCAO_STATUS_EXCLUIDOS)) . ')';
        foreach (PROSPECCAO_STATUS_EXCLUIDOS as $statusId) {
            $params[] = $statusId;
        }
    }

    if (!empty($filtros['orcamentos'])) {
        $clausulas[] = 'precatoriodetalhe.Orcamento IN (' . prospeccao_placeholders(count($filtros['orcamentos'])) . ')';
        foreach ($filtros['orcamentos'] as $ano) {
            $params[] = $ano;
        }
    }

    if (!empty($filtros['natureza_ids'])) {
        $clausulas[] = 'precatoriodetalhe.NaturezaId IN (' . prospeccao_placeholders(count($filtros['natureza_ids'])) . ')';
        foreach ($filtros['natureza_ids'] as $naturezaId) {
            $params[] = $naturezaId;
        }
    }

    return implode("\n          AND ", $clausulas);
}

// Naturezas cadastradas (id + nome), para popular o filtro do formulário.
function prospeccao_listar_naturezas(PDO $pdo) {
    $stmt = $pdo->query("
        SELECT natuPrec_id AS id, Natureza AS nome
        FROM precappapp.NaturezaPrec
        ORDER BY Natureza
    ");
    return $stmt->fetchAll();
}

// Orçamentos distintos existentes na base, para popular o filtro do formulário.
function prospeccao_listar_orcamentos(PDO $pdo) {
    $stmt = $pdo->query("
        SELECT DISTINCT Orcamento
        FROM precappapp.precatoriodetalhe
        WHERE Orcamento IS NOT NULL
        ORDER BY Orcamento DESC
    ");
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

// Painel geral: total de precatórios ativos e pendentes de pagamento do
// ente/orçamento/natureza + quebra do pipeline de prospecção (prospectados /
// pendentes com e sem requisitório, excluindo os StatusId já encerrados).
function prospeccao_resumo_geral(PDO $pdo, array $filtros) {
    $whereTotal = prospeccao_build_where($filtros, false, $paramsTotal);
    $sqlTotal = "
        SELECT
            COUNT(precatoriodetalhe.Precatorio) AS QtdTotal,
            COALESCE(SUM(CAST(precatoriodetalhe.ValorPrec AS DECIMAL(15,2))), 0) AS ValorTotal
        FROM precappapp.precatoriodetalhe
        WHERE {$whereTotal}
    ";
    $stmt = $pdo->prepare($sqlTotal);
    $stmt->execute($paramsTotal);
    $total = $stmt->fetch();

    $wherePipeline = prospeccao_build_where($filtros, true, $paramsPipeline);
    $sqlPipeline = "
        SELECT
            SUM(CASE WHEN precatoriodetalhe.StatusId <> ? THEN 1 ELSE 0 END) AS QtdProspectados,
            SUM(CASE WHEN precatoriodetalhe.StatusId <> ? THEN CAST(precatoriodetalhe.ValorPrec AS DECIMAL(15,2)) ELSE 0 END) AS ValorProspectados,
            SUM(CASE WHEN precatoriodetalhe.StatusId = ? AND precatoriodetalhe.RequisitorioId NOT IN (1, 3) THEN 1 ELSE 0 END) AS QtdPendenteComReq,
            SUM(CASE WHEN precatoriodetalhe.StatusId = ? AND precatoriodetalhe.RequisitorioId NOT IN (1, 3) THEN CAST(precatoriodetalhe.ValorPrec AS DECIMAL(15,2)) ELSE 0 END) AS ValorPendenteComReq,
            SUM(CASE WHEN precatoriodetalhe.StatusId = ? AND (precatoriodetalhe.RequisitorioId IS NULL OR precatoriodetalhe.RequisitorioId IN (1, 3)) THEN 1 ELSE 0 END) AS QtdPendenteSemReq,
            SUM(CASE WHEN precatoriodetalhe.StatusId = ? AND (precatoriodetalhe.RequisitorioId IS NULL OR precatoriodetalhe.RequisitorioId IN (1, 3)) THEN CAST(precatoriodetalhe.ValorPrec AS DECIMAL(15,2)) ELSE 0 END) AS ValorPendenteSemReq
        FROM precappapp.precatoriodetalhe
        WHERE {$wherePipeline}
    ";
    $params = array_merge(
        array_fill(0, 6, PROSPECCAO_STATUS_ID_PENDENTE),
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

    // precatoriodetalhe.FirstName é qualificado explicitamente porque Usuario
    // também tem uma coluna FirstName; sem o prefixo, o "SELECT FirstName"
    // fica ambíguo assim que o JOIN com Usuario entra na consulta.
    $selectConsultora = $porConsultora ? "precatoriodetalhe.FirstName,\n            " : '';
    $groupByConsultora = $porConsultora ? ', precatoriodetalhe.FirstName' : '';
    $orderByConsultora = $porConsultora ? ', precatoriodetalhe.FirstName' : '';
    $where = prospeccao_build_where($filtros, true, $whereParams);

    // Consultoras relevantes são as com PerfilId = 2 na tabela Usuario
    // (precatoriodetalhe.Negociador = Usuario.usuario_id). Só entra quando
    // agrupado por consultora — não afeta o resumo geral (que não é por pessoa).
    $joinUsuario = '';
    if ($porConsultora) {
        $joinUsuario = 'INNER JOIN precappapp.Usuario ON precatoriodetalhe.Negociador = Usuario.usuario_id';
        $where .= "\n          AND Usuario.PerfilId = 2";
    }

    // Ente e StatusId entram no GROUP BY (junto de StatusPrec) para funcionar
    // tanto em servidores com sql_mode=ONLY_FULL_GROUP_BY quanto sem; não
    // altera o resultado, já que StatusId é 1:1 com StatusPrec e Ente é
    // funcionalmente dependente de ente_id (mesmo havendo vários entes no IN).
    $sql = "
        SELECT
            precatoriodetalhe.Ente,
            precatoriodetalhe.StatusPrec,
            precatoriodetalhe.StatusId,
            {$selectConsultora}COUNT(precatoriodetalhe.Precatorio) AS QuantidadeTotal,
            SUM(CAST(precatoriodetalhe.ValorPrec AS DECIMAL(15,2))) AS ValorTotal,
            SUM(CASE WHEN precatoriodetalhe.RequisitorioId NOT IN (1, 3) THEN 1 ELSE 0 END) AS ComRequisitorio,
            SUM(CASE WHEN precatoriodetalhe.RequisitorioId NOT IN (1, 3) THEN CAST(precatoriodetalhe.ValorPrec AS DECIMAL(15,2)) ELSE 0 END) AS ValorComRequisitorio,
            SUM(CASE WHEN precatoriodetalhe.RequisitorioId IS NULL OR precatoriodetalhe.RequisitorioId IN (1, 3) THEN 1 ELSE 0 END) AS SemRequisitorio,
            SUM(CASE WHEN precatoriodetalhe.RequisitorioId IS NULL OR precatoriodetalhe.RequisitorioId IN (1, 3) THEN CAST(precatoriodetalhe.ValorPrec AS DECIMAL(15,2)) ELSE 0 END) AS ValorSemRequisitorio,
            SUM(CASE WHEN CAST(precatoriodetalhe.ValorPrec AS DECIMAL(15,2)) >= ? AND precatoriodetalhe.DataRecebimento < ? THEN 1 ELSE 0 END) AS QtdMelhores,
            SUM(CASE WHEN CAST(precatoriodetalhe.ValorPrec AS DECIMAL(15,2)) >= ? AND precatoriodetalhe.DataRecebimento < ? THEN CAST(precatoriodetalhe.ValorPrec AS DECIMAL(15,2)) ELSE 0 END) AS ValorMelhores,
            SUM(CASE WHEN CAST(precatoriodetalhe.ValorPrec AS DECIMAL(15,2)) >= ? AND precatoriodetalhe.DataRecebimento < ? AND precatoriodetalhe.RequisitorioId NOT IN (1, 3) THEN 1 ELSE 0 END) AS QtdMelhoresComReq,
            SUM(CASE WHEN CAST(precatoriodetalhe.ValorPrec AS DECIMAL(15,2)) >= ? AND precatoriodetalhe.DataRecebimento < ? AND precatoriodetalhe.RequisitorioId NOT IN (1, 3) THEN CAST(precatoriodetalhe.ValorPrec AS DECIMAL(15,2)) ELSE 0 END) AS ValorMelhoresComReq,
            SUM(CASE WHEN CAST(precatoriodetalhe.ValorPrec AS DECIMAL(15,2)) >= ? AND precatoriodetalhe.DataRecebimento < ? AND (precatoriodetalhe.RequisitorioId IS NULL OR precatoriodetalhe.RequisitorioId IN (1, 3)) THEN 1 ELSE 0 END) AS QtdMelhoresSemReq,
            SUM(CASE WHEN CAST(precatoriodetalhe.ValorPrec AS DECIMAL(15,2)) >= ? AND precatoriodetalhe.DataRecebimento < ? AND (precatoriodetalhe.RequisitorioId IS NULL OR precatoriodetalhe.RequisitorioId IN (1, 3)) THEN CAST(precatoriodetalhe.ValorPrec AS DECIMAL(15,2)) ELSE 0 END) AS ValorMelhoresSemReq
        FROM precappapp.precatoriodetalhe
        {$joinUsuario}
        WHERE {$where}
        GROUP BY precatoriodetalhe.StatusPrec, precatoriodetalhe.StatusId, precatoriodetalhe.Ente{$groupByConsultora}
        ORDER BY precatoriodetalhe.StatusPrec DESC{$orderByConsultora}
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
