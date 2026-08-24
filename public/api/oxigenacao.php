<?php

header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/../../src/connection.php';
require __DIR__ . '/../../src/oxigenacao_repository.php';

try {
    $acao = $_GET['acao'] ?? 'oxigenacao';

    if ($acao === 'foto') {
        $filtros = oxigenacao_parse_filtros($_GET, 'foto');
        $foto = oxigenacao_foto_por_data($pdo, $filtros);
        $cruzamentos = oxigenacao_foto_cruzamentos($pdo, $filtros);

        // A cobertura da reconstrução de quitação custa uma varredura inteira e
        // só é exibida quando o recorte de pendentes está ligado.
        echo json_encode([
            'ok'               => true,
            'data_ref'         => $filtros['data_ref'],
            'linhas'           => $foto['linhas'],
            'totais'           => $foto['totais'],
            'usa_status_atual' => $foto['usa_status_atual'],
            'status_mapa'      => oxigenacao_mapa_status($pdo),
            'por_status_ente'      => $cruzamentos['por_status_ente'],
            'por_status_consultor' => $cruzamentos['por_status_consultor'],
            'cobertura'        => $filtros['somente_pendentes']
                ? oxigenacao_cobertura_quitacao($pdo, $filtros)
                : null,
        ]);
    } elseif ($acao === 'oxigenacao') {
        $filtros = oxigenacao_parse_filtros($_GET, 'periodo');

        $eventos = oxigenacao_eventos($pdo, $filtros);
        $agregados = oxigenacao_agregar($eventos);

        $truncado = count($eventos) > OXI_LIMITE_DETALHE;

        echo json_encode([
            'ok'                  => true,
            'periodo'             => ['inicio' => $filtros['data_inicio'], 'fim' => $filtros['data_fim']],
            'kpis'                => $agregados['kpis'],
            'base_sem_tentativa'  => oxigenacao_base_sem_tentativa($pdo, $filtros),
            'por_dia'             => $agregados['por_dia'],
            'por_ente'            => $agregados['por_ente'],
            'por_consultor'       => $agregados['por_consultor'],
            'por_status_destino'  => $agregados['por_status_destino'],
            'por_status_ente'      => $agregados['por_status_ente'],
            'por_status_consultor' => $agregados['por_status_consultor'],
            'eventos'             => $truncado ? array_slice($eventos, 0, OXI_LIMITE_DETALHE) : $eventos,
            'eventos_truncados'   => $truncado,
            'limite_detalhe'      => OXI_LIMITE_DETALHE,
        ]);
    } else {
        throw new InvalidArgumentException('Ação desconhecida.');
    }
} catch (InvalidArgumentException $e) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'erro' => $e->getMessage()]);
} catch (Throwable $e) {
    // Sem o log, uma falha aqui chega ao navegador como resposta vazia e não
    // sobra rastro nenhum para diagnosticar.
    error_log('api/oxigenacao.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'erro' => 'Erro ao consultar os dados.']);
}
