<?php

header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/../../src/auth.php';
auth_exigir_login_api();

require __DIR__ . '/../../src/tjrj_repository.php';

// Uma consulta pode trazer dezenas de milhares de precatórios.
ini_set('memory_limit', '512M');

try {
    $acao = $_GET['acao'] ?? 'precatorios';

    if ($acao === 'entes') {
        echo json_encode(['ok' => true, 'entes' => tjrj_listar_entes_raiz()]);
    } elseif ($acao === 'precatorios') {
        $enteId = tjrj_sanitize_ente($_GET['ente_id'] ?? null);
        $ordem  = tjrj_sanitize_ordem($_GET['ordem'] ?? null);

        echo json_encode([
            'ok'          => true,
            'precatorios' => tjrj_buscar_precatorios($enteId, $ordem),
        ]);
    } else {
        throw new InvalidArgumentException('Ação desconhecida.');
    }
} catch (InvalidArgumentException $e) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'erro' => $e->getMessage()]);
} catch (RuntimeException $e) {
    error_log('api/tjrj.php: ' . $e->getMessage());
    http_response_code(502);
    echo json_encode(['ok' => false, 'erro' => 'O site do TJRJ não respondeu. Tente novamente em instantes.']);
} catch (Throwable $e) {
    error_log('api/tjrj.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'erro' => 'Erro ao consultar os dados.']);
}
