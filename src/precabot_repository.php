<?php

// Consultas da Tabela do Sistema (public/precabot.php): lista de precatórios
// do Precabot a partir da view precatoriodetalhe.
//
// Escopos:
// - ente:         um ente escolhido no select;
// - erj:          Estado do Rio de Janeiro;
// - trf2:         União (TRF2);
// - todos_exceto: todos os entes, menos os dois acima (que são os maiores e
//                 têm tela própria na prática).
//
// Consultor (PerfilId = 2) só enxerga os precatórios em que é o negociador.

defined('PRECABOT_TB_DETALHE') || define('PRECABOT_TB_DETALHE', 'precappapp.precatoriodetalhe');

const PRECABOT_ENTE_ERJ          = 1;
const PRECABOT_ENTE_TRF2         = 2475;
const PRECABOT_PERFIL_CONSULTOR  = 2;
const PRECABOT_ESCOPOS           = ['ente', 'todos_exceto', 'erj', 'trf2'];

const PRECABOT_COLUNAS = [
    'Ente', 'Precatorio', 'Processo', 'Orcamento', 'StatusPrec', 'FirstName',
    'RequisitorioId', 'NomeReu', 'ReuId', 'prec_pg', 'ValorPrec', 'vlr_atual_tj',
    'Datarecebimento', 'LastContact', 'NextContact',
];

// Normaliza e valida os filtros vindos da tela.
function precabot_parse_filtros(array $input) {
    $escopo = (string)($input['escopo'] ?? 'ente');
    if ($escopo === '') {
        $escopo = 'ente';
    }
    if (!in_array($escopo, PRECABOT_ESCOPOS, true)) {
        throw new InvalidArgumentException('Escopo inválido.');
    }

    $enteId = null;
    if ($escopo === 'ente') {
        $raw = trim((string)($input['ente_id'] ?? ''));
        if ($raw === '') {
            throw new InvalidArgumentException('Selecione um Ente.');
        }
        if (!ctype_digit($raw) || (int)$raw <= 0) {
            throw new InvalidArgumentException('Ente inválido.');
        }
        $enteId = (int)$raw;
    }

    return [
        'escopo'           => $escopo,
        'ente_id'          => $enteId,
        'apenas_pendentes' => !empty($input['apenas_pendentes']),
    ];
}

// Precatórios do escopo escolhido. $perfilId e $usuarioId vêm da sessão, nunca
// da requisição.
function precabot_listar(PDO $pdo, array $filtros, $perfilId, $usuarioId) {
    $params = [];

    switch ($filtros['escopo']) {
        case 'erj':
            $where = 'ente_id = ?';
            $params[] = PRECABOT_ENTE_ERJ;
            break;
        case 'trf2':
            $where = 'ente_id = ?';
            $params[] = PRECABOT_ENTE_TRF2;
            break;
        case 'todos_exceto':
            $where = 'ente_id NOT IN (?, ?)';
            $params[] = PRECABOT_ENTE_ERJ;
            $params[] = PRECABOT_ENTE_TRF2;
            break;
        default:
            $where = 'ente_id = ?';
            $params[] = $filtros['ente_id'];
    }

    if ($filtros['apenas_pendentes']) {
        $where .= ' AND prec_pg IS NULL';
    }

    if ((int)$perfilId === PRECABOT_PERFIL_CONSULTOR && $usuarioId) {
        $where .= ' AND Negociador = ?';
        $params[] = (int)$usuarioId;
    }

    $sql = 'SELECT ' . implode(', ', PRECABOT_COLUNAS)
         . ' FROM ' . PRECABOT_TB_DETALHE
         . ' WHERE ' . $where;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
