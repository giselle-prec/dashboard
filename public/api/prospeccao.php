<?php

header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/../../src/connection.php';
require __DIR__ . '/../../src/prospeccao_repository.php';

try {
    // POST em vez de GET: com "selecionar todos" o Ente pode ter milhares de
    // valores marcados, o que estoura o limite de tamanho de URL de um GET.
    $input = $_POST;
    $filtros = prospeccao_parse_filtros($input);

    $resumo = prospeccao_resumo_geral($pdo, $filtros);
    $detalhe = prospeccao_detalhe($pdo, $filtros);
    $ultimoBatch = prospeccao_ultimo_batch($pdo, $filtros['ente_ids']);

    echo json_encode([
        'ok'                  => true,
        'resumo'              => $resumo,
        'detalhe'             => $detalhe,
        'agrupado_por_consultora' => $filtros['por_consultora'],
        'ultimo_batch'        => $ultimoBatch,
    ]);
} catch (InvalidArgumentException $e) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'erro' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('prospeccao.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'erro' => 'Erro ao consultar os dados.']);
}
