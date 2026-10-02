<?php

// Proteção das páginas. Uso, no topo de cada página:
//   $rota = 'prospeccao';
//   require __DIR__ . '/../src/guarda.php';

require_once __DIR__ . '/auth.php';

auth_iniciar_sessao();

$rota = $rota ?? null;

if (!auth_usuario_logado() && $rota !== 'login') {
    header('Location: login');
    exit;
}

if (auth_usuario_logado() && $rota === 'login') {
    header('Location: ./');
    exit;
}

$rotas_permitidas = require __DIR__ . '/rotas.php';
if (!array_key_exists((string)$rota, $rotas_permitidas)) {
    header('Location: 404');
    exit;
}

// Página que existe, mas não para o perfil de quem está logado.
if (!auth_pode_acessar_rota($rota)) {
    header('Location: ./');
    exit;
}
