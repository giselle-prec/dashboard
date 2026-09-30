<?php

header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/../../src/auth.php';
auth_exigir_login_api();

// A página inicial faz duas requisições em paralelo. O PHP trava o arquivo de
// sessão enquanto ela está aberta, e aí a segunda esperaria a primeira
// terminar. Daqui para baixo a sessão só é lida, então pode ser liberada.
session_write_close();

require __DIR__ . '/../../src/connection.php';
require __DIR__ . '/../../src/inicio_repository.php';

try {
    $acao = $_GET['acao'] ?? '';
    $hoje = date('Y-m-d');

    if ($acao === 'oxigenacao') {
        $janelas = inicio_janelas_semana($hoje);
        $resumo = inicio_resumo_oxigenacao($pdo, $janelas);

        echo json_encode([
            'ok'                 => true,
            'janelas'            => $janelas,
            'semana_atual'       => $resumo['semana_atual'],
            'semana_anterior'    => $resumo['semana_anterior'],
            'por_ente'           => $resumo['por_ente'],
            'por_consultor'      => $resumo['por_consultor'],
            'base_sem_tentativa' => $resumo['base_sem_tentativa'],
        ]);
    } elseif ($acao === 'prospeccao') {
        $enteIds = inicio_entes_pendentes($pdo);
        $prospeccao = inicio_resumo_prospeccao($pdo, $enteIds);
        $batch = inicio_batch_mais_antigos($pdo, $enteIds, $hoje);

        echo json_encode([
            'ok'                 => true,
            'qtd_entes'          => count($enteIds),
            'resumo'             => $prospeccao['resumo'],
            'por_status'         => $prospeccao['por_status'],
            'batch_mais_antigos' => $batch['mais_antigos'],
            'entes_sem_batch'    => $batch['entes_sem_batch'],
        ]);
    } else {
        throw new InvalidArgumentException('Ação desconhecida.');
    }
} catch (InvalidArgumentException $e) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'erro' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('api/inicio.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'erro' => 'Erro ao consultar os dados.']);
}
