<?php

// Proteção das páginas. Uso, no topo de cada página:
//   $rota = 'prospeccao';
//   require __DIR__ . '/../src/guarda.php';

require_once __DIR__ . '/auth.php';

auth_iniciar_sessao();

$rotas_permitidas = require __DIR__ . '/rotas.php';
$rota = $rota ?? null;

if (!auth_usuario_logado() && $rota !== 'login') {
    header('Location: login.php');
    exit;
}

if (auth_usuario_logado() && $rota === 'login') {
    header('Location: index.php');
    exit;
}

if (!in_array($rota, $rotas_permitidas, true)) {
    header('Location: 404.php');
    exit;
}
