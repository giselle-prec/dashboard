<?php

header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/../../src/auth.php';
auth_exigir_login_api();

require __DIR__ . '/../../src/connection.php';
require __DIR__ . '/../../src/precabot_repository.php';

try {
    $filtros = precabot_parse_filtros($_GET);

    $dados = precabot_listar(
        $pdo,
        $filtros,
        $_SESSION['PerfilId'] ?? null,
        $_SESSION['usuario_id'] ?? null
    );

    echo json_encode(['ok' => true, 'dados' => $dados]);
} catch (InvalidArgumentException $e) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'erro' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('api/precabot.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'erro' => 'Erro ao consultar os dados.']);
}
