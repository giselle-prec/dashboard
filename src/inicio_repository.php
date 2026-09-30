<?php

// Consultas do resumo semanal da página inicial (public/index.php).
//
// A página não tem filtro nenhum: tudo aqui roda com recortes fixos, e reusa
// as consultas dos painéis de oxigenação e de prospecção para que os números
// batam com os deles.
//
// - Semana = os últimos 5 dias úteis, contando hoje quando hoje é dia útil,
//   comparados com os 5 dias úteis imediatamente anteriores. Dia útil é de
//   segunda a sexta; feriados não são descontados (não há calendário de
//   feriados no sistema). As duas janelas são intervalos de datas contíguos,
//   então um contato feito num sábado ou domingo conta na janela em que cai.
// - Prospecção e batch olham para os entes que têm precatório pendente de
//   pagamento (inicio_entes_pendentes).

require_once __DIR__ . '/oxigenacao_repository.php';

const INICIO_DIAS_UTEIS_SEMANA = 5;
const INICIO_TOP_ENTES = 15;
const INICIO_QTD_BATCH_ANTIGOS = 3;

// ---------------------------------------------------------------------------
// Semana
// ---------------------------------------------------------------------------

function inicio_data($raw) {
    $data = DateTimeImmutable::createFromFormat('!Y-m-d', (string)$raw);
    if (!$data || $data->format('Y-m-d') !== (string)$raw) {
        throw new InvalidArgumentException('Data inválida.');
    }
    return $data;
}

function inicio_eh_dia_util(DateTimeImmutable $dia) {
    return (int)$dia->format('N') <= 5;
}

// Os $quantidade dias úteis que terminam em $fim (inclusive), em ordem crescente.
function inicio_dias_uteis_ate(DateTimeImmutable $fim, $quantidade) {
    $dias = [];
    $dia = $fim;
    while (count($dias) < $quantidade) {
        if (inicio_eh_dia_util($dia)) {
            array_unshift($dias, $dia->format('Y-m-d'));
        }
        $dia = $dia->modify('-1 day');
    }
    return $dias;
}

// Janela atual (termina hoje) e anterior (termina na véspera do início da
// atual). Cada uma traz o intervalo de datas consultado e os dias úteis que
// ele contém, que é o que a tela mostra ao comparar as duas.
function inicio_janelas_semana($hoje) {
    $hoje = inicio_data($hoje);

    $diasAtual = inicio_dias_uteis_ate($hoje, INICIO_DIAS_UTEIS_SEMANA);
    $fimAnterior = inicio_data($diasAtual[0])->modify('-1 day');
    $diasAnterior = inicio_dias_uteis_ate($fimAnterior, INICIO_DIAS_UTEIS_SEMANA);

    return [
        'atual' => [
            'inicio' => $diasAtual[0],
            'fim'    => $hoje->format('Y-m-d'),
            'dias'   => $diasAtual,
        ],
        'anterior' => [
            'inicio' => $diasAnterior[0],
            'fim'    => $fimAnterior->format('Y-m-d'),
            'dias'   => $diasAnterior,
        ],
    ];
}

// ---------------------------------------------------------------------------
// Oxigenação da semana
// ---------------------------------------------------------------------------

// Uma consulta só, cobrindo as duas janelas; os eventos são separados aqui
// pela data de oxigenação. Os gráficos são da janela atual; da anterior só
// interessa o total, para a comparação.
function inicio_resumo_oxigenacao(PDO $pdo, array $janelas) {
    $filtros = oxigenacao_parse_filtros([
        'data_inicio' => $janelas['anterior']['inicio'],
        'data_fim'    => $janelas['atual']['fim'],
    ], 'periodo');

    $eventosAtual = [];
    $anterior = ['qtd' => 0, 'valor' => 0.0];
    foreach (oxigenacao_eventos($pdo, $filtros) as $evento) {
        if ($evento['DataOxigenacao'] >= $janelas['atual']['inicio']) {
            $eventosAtual[] = $evento;
        } else {
            $anterior['qtd']++;
            $anterior['valor'] += (float)$evento['ValorPrec'];
        }
    }

    $agregados = oxigenacao_agregar($eventosAtual);

    return [
        'semana_atual'       => [
            'qtd'   => $agregados['kpis']['qtd'],
            'valor' => $agregados['kpis']['valor'],
        ],
        'semana_anterior'    => $anterior,
        // Os gráficos da página são de quantidade: o top é por quantidade.
        'por_ente'           => array_slice(
            oxigenacao_ordenar_agregado($agregados['por_ente'], 'qtd'), 0, INICIO_TOP_ENTES
        ),
        'por_consultor'      => oxigenacao_ordenar_agregado($agregados['por_consultor'], 'qtd'),
        'base_sem_tentativa' => oxigenacao_base_sem_tentativa($pdo, $filtros),
    ];
}

// ---------------------------------------------------------------------------
// Prospecção e batch
// ---------------------------------------------------------------------------

// Entes com ao menos um precatório pendente de pagamento. Id zero ou negativo
// fica de fora: não é ente de verdade e seria recusado pelo filtro da
// prospecção (prospeccao_sanitize_ente_ids).
function inicio_entes_pendentes(PDO $pdo) {
    $sql = 'SELECT ' . OXI_HINT_TIMEOUT . ' DISTINCT p.EnteId
            FROM ' . OXI_TB_PRECATORIO . ' p
            WHERE p.prec_pg IS NULL
              AND p.EnteId IS NOT NULL
            ORDER BY p.EnteId';

    $ids = [];
    foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN) as $id) {
        if ((int)$id > 0) {
            $ids[] = (int)$id;
        }
    }
    return array_values(array_unique($ids));
}

// O bloco principal do Painel de Prospecção (resumo geral e valor por status)
// com todos os entes pendentes selecionados e os demais filtros em branco.
//
// O detalhe da prospecção vem com uma linha por status e por ente — com todos
// os entes, milhares de linhas. O gráfico só usa o total de cada status, então
// o ente é somado aqui e o navegador recebe uma linha por status, na mesma
// ordem em que o painel as desenha.
function inicio_resumo_prospeccao(PDO $pdo, array $enteIds) {
    if (empty($enteIds)) {
        return [
            'resumo'     => [
                'qtd_total' => 0, 'valor_total' => 0.0,
                'qtd_prospectados' => 0, 'valor_prospectados' => 0.0,
                'qtd_pendente_com_req' => 0, 'valor_pendente_com_req' => 0.0,
                'qtd_pendente_sem_req' => 0, 'valor_pendente_sem_req' => 0.0,
            ],
            'por_status' => [],
        ];
    }

    $filtros = prospeccao_parse_filtros(['ente_id' => $enteIds]);

    $porStatus = [];
    foreach (prospeccao_detalhe($pdo, $filtros) as $linha) {
        $status = ($linha['StatusPrec'] === null || $linha['StatusPrec'] === '')
            ? '(não informado)'
            : $linha['StatusPrec'];
        if (!isset($porStatus[$status])) {
            $porStatus[$status] = ['StatusPrec' => $status, 'QuantidadeTotal' => 0, 'ValorTotal' => 0.0];
        }
        $porStatus[$status]['QuantidadeTotal'] += (int)$linha['QuantidadeTotal'];
        $porStatus[$status]['ValorTotal'] += (float)$linha['ValorTotal'];
    }

    return [
        'resumo'     => prospeccao_resumo_geral($pdo, $filtros),
        'por_status' => array_values($porStatus),
    ];
}

// data_batch é texto livre. Os formatos reconhecidos viram Y-m-d; qualquer
// outro devolve null e a tela mostra o texto como está gravado.
function inicio_data_do_batch($texto) {
    $texto = trim((string)$texto);
    $formatos = ['!Y-m-d H:i:s', '!Y-m-d H:i', '!Y-m-d', '!d/m/Y H:i:s', '!d/m/Y H:i', '!d/m/Y'];
    foreach ($formatos as $formato) {
        $data = DateTimeImmutable::createFromFormat($formato, $texto);
        $erros = DateTimeImmutable::getLastErrors();
        if ($data && ($erros === false || ($erros['warning_count'] === 0 && $erros['error_count'] === 0))) {
            return $data->format('Y-m-d');
        }
    }
    return null;
}

// Os entes há mais tempo sem batch, entre os que têm precatório pendente, com
// a data e a idade do último batch de cada um. Quem nunca passou por batch não
// tem data para mostrar: entra só na contagem à parte.
function inicio_batch_mais_antigos(PDO $pdo, array $enteIds, $hoje) {
    $porEnte = prospeccao_ultimo_batch_por_ente($pdo, $enteIds);
    $hoje = inicio_data($hoje);

    $maisAntigos = [];
    foreach (array_slice($porEnte, 0, INICIO_QTD_BATCH_ANTIGOS) as $linha) {
        $data = inicio_data_do_batch($linha['data_batch']);
        $maisAntigos[] = [
            'ente_id'    => (int)$linha['ente_id'],
            'nome_ente'  => $linha['nome_ente'],
            'data_batch' => $linha['data_batch'],
            'data'       => $data,
            'dias'       => $data === null ? null : (int)inicio_data($data)->diff($hoje)->format('%r%a'),
        ];
    }

    return [
        'mais_antigos'    => $maisAntigos,
        'entes_sem_batch' => max(0, count($enteIds) - count($porEnte)),
    ];
}

// "24/09 a 30/09/2026" — o ano só aparece no começo quando as duas datas
// caem em anos diferentes.
function inicio_rotulo_janela(array $janela) {
    $inicio = inicio_data($janela['inicio']);
    $fim = inicio_data($janela['fim']);
    $formatoInicio = $inicio->format('Y') === $fim->format('Y') ? 'd/m' : 'd/m/Y';
    return $inicio->format($formatoInicio) . ' a ' . $fim->format('d/m/Y');
}
