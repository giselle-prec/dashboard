<?php

header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/../../src/connection.php';
require __DIR__ . '/../../src/prospeccao_repository.php';

try {
    $input = $_GET;
    $filtros = prospeccao_parse_filtros($input);

    $resumo = prospeccao_resumo_geral($pdo, $filtros);
    $detalhe = prospeccao_detalhe($pdo, $filtros);

    echo json_encode([
        'ok'                  => true,
        'resumo'              => $resumo,
        'detalhe'             => $detalhe,
        'agrupado_por_consultora' => $filtros['por_consultora'],
    ]);
} catch (InvalidArgumentException $e) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'erro' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('prospeccao.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'erro' => 'Erro ao consultar os dados.']);
}
