<?php

// Verificação das regras por perfil (src/auth.php, src/rotas.php) e do menu
// (public/templates/nav_top.php): quem vê a página de batch, o filtro de
// negociador do consultor e o endereço da foto de perfil.
//
// Não precisa de banco: a sessão é montada à mão em $_SESSION.
//
// Uso:
//   php tests/perfis_smoke.php

require __DIR__ . '/../src/auth.php';

$falhas = 0;
$total = 0;

function verificar($descricao, $condicao, $detalhe = '') {
    global $falhas, $total;
    $total++;
    if ($condicao) {
        echo "  ok   {$descricao}\n";
        return;
    }
    $falhas++;
    echo "  FALHA {$descricao}" . ($detalhe !== '' ? " — {$detalhe}" : '') . "\n";
}

function logar_como($perfil, $usuarioId = 10, $foto = null) {
    $_SESSION = [
        'user'       => 'fulano@precapp.com.br',
        'PerfilId'   => $perfil === null ? null : (string)$perfil,
        'usuario_id' => $usuarioId === null ? null : (string)$usuarioId,
        'foto'       => $foto,
    ];
}

// Renderiza o menu como uma página faria e devolve o HTML.
function renderizar_menu($rota = 'index') {
    $title = 'Teste';
    ob_start();
    require __DIR__ . '/../public/templates/nav_top.php';
    return ob_get_clean();
}

// ---------------------------------------------------------------------------
echo "Rotas por perfil\n";
// ---------------------------------------------------------------------------

$rotasLivres = ['index', 'prospeccao', 'oxigenacao', 'precabot', 'tjrj', 'login', '404'];

logar_como(AUTH_PERFIL_ADMIN);
verificar('perfil 1 abre a página de batch', auth_pode_acessar_rota('batch'));

foreach ([AUTH_PERFIL_CONSULTOR, 3] as $perfil) {
    logar_como($perfil);
    verificar("perfil {$perfil} não abre a página de batch", !auth_pode_acessar_rota('batch'));
}

foreach ([AUTH_PERFIL_ADMIN, AUTH_PERFIL_CONSULTOR, 3] as $perfil) {
    logar_como($perfil);
    $bloqueadas = array_filter($rotasLivres, function ($rota) {
        return !auth_pode_acessar_rota($rota);
    });
    verificar("perfil {$perfil} abre as demais páginas", !$bloqueadas, implode(', ', $bloqueadas));
}

verificar('rota desconhecida é negada', !auth_pode_acessar_rota('nao-existe'));

$_SESSION = [];
verificar('sem sessão, login continua acessível', auth_pode_acessar_rota('login'));
verificar('sem sessão, batch é negado', !auth_pode_acessar_rota('batch'));

// ---------------------------------------------------------------------------
echo "Sessão e filtro de negociador\n";
// ---------------------------------------------------------------------------

logar_como(AUTH_PERFIL_CONSULTOR, 10);
verificar('consultor logado', auth_usuario_logado() && auth_eh_consultor());
verificar('consultor é filtrado pelo próprio id', auth_negociador_restrito() === 10);

logar_como(AUTH_PERFIL_CONSULTOR, null);
verificar('consultor sem usuario_id não conta como logado', !auth_usuario_logado());

logar_como(AUTH_PERFIL_ADMIN, 1);
verificar('perfil 1 não é filtrado', auth_negociador_restrito() === null && auth_usuario_logado());

logar_como(3, 7);
verificar('perfil 3 não é filtrado', auth_negociador_restrito() === null && auth_usuario_logado());

$_SESSION = [];
verificar('sem sessão, não está logado', !auth_usuario_logado());

// ---------------------------------------------------------------------------
echo "Endereço da foto\n";
// ---------------------------------------------------------------------------

$casos = [
    ['sem foto',              null,                                          null],
    ['foto vazia',            '  ',                                          null],
    ['caminho relativo',      'uploads/31012024145502zyro-image.png',        'https://precapp.net/uploads/31012024145502zyro-image.png'],
    ['com barra inicial',     '/uploads/a.png',                              'https://precapp.net/uploads/a.png'],
    ['URL http vira https',   'http://precapp.net/uploads/a.png',            'https://precapp.net/uploads/a.png'],
    ['URL https fica igual',  'https://cdn.exemplo.com/a.png',               'https://cdn.exemplo.com/a.png'],
];
foreach ($casos as $c) {
    list($descricao, $foto, $esperado) = $c;
    $obtido = auth_url_foto($foto);
    verificar($descricao, $obtido === $esperado, var_export($obtido, true));
}

// ---------------------------------------------------------------------------
echo "Menu\n";
// ---------------------------------------------------------------------------

logar_como(AUTH_PERFIL_ADMIN, 1, 'uploads/foto.png');
$html = renderizar_menu();
verificar('perfil 1 vê "Informações Batch" no menu', strpos($html, 'href="batch"') !== false);
verificar('foto aparece nos dois avatares',
    substr_count($html, '<img src="https://precapp.net/uploads/foto.png"') === 2);
verificar('ícone continua por baixo da foto', substr_count($html, 'fa fa-user') === 2);

logar_como(AUTH_PERFIL_CONSULTOR, 10);
$html = renderizar_menu('prospeccao');
verificar('consultor não vê "Informações Batch" no menu', strpos($html, 'href="batch"') === false);
verificar('consultor vê os painéis e as tabelas',
    strpos($html, 'href="prospeccao"') !== false && strpos($html, 'href="oxigenacao"') !== false
    && strpos($html, 'href="precabot"') !== false && strpos($html, 'href="tjrj"') !== false);
verificar('seção "Menu" continua com o Início', strpos($html, '>Menu<') !== false && strpos($html, 'href="./"') !== false);
verificar('sem foto, só o ícone', strpos($html, '<img src="https://precapp.net') === false
    && substr_count($html, 'fa fa-user') === 2);

logar_como(AUTH_PERFIL_CONSULTOR, 10, 'uploads/"><script>alert(1)</script>');
$html = renderizar_menu();
verificar('endereço da foto sai escapado', strpos($html, '<script>alert(1)') === false);

echo "\n{$total} verificações, {$falhas} falha(s).\n";
exit($falhas > 0 ? 1 : 0);
