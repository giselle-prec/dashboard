<?php

// Roteador do servidor embutido do PHP, para testar localmente com as mesmas
// URLs da produção. O php -S não lê o .htaccess da raiz; este arquivo repete
// as regras dele. Uso, dentro de public/:
//
//   php -S localhost:2026 router.php
//
//   /                 → index.php
//   /prospeccao       → prospeccao.php
//   /api/inicio       → api/inicio.php
//   /prospeccao.php   → redireciona para /prospeccao
//
// Em produção quem cuida das URLs é o .htaccess; lá este arquivo não faz nada.

if (PHP_SAPI !== 'cli-server') {
    http_response_code(404);
    exit;
}

// Devolve o .php a executar, ou false para o servidor embutido servir o
// arquivo pedido como está. Redirecionamentos e 404 simples param aqui.
function roteador_resolver() {
    $bruto   = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $caminho = rawurldecode($bruto);
    $query   = $_SERVER['QUERY_STRING'] ?? '';
    $get     = in_array($_SERVER['REQUEST_METHOD'], ['GET', 'HEAD'], true);

    // Endereço antigo → novo (301), como no .htaccess. Só GET/HEAD: um POST
    // redirecionado chega como GET e perde os dados do formulário.
    if ($get && (preg_match('#^(.*/)index(?:\.php)?$#', $bruto, $m)
              || preg_match('#^(.+)\.php$#', $bruto, $m))) {
        header('Location: ' . $m[1] . ($query !== '' ? '?' . $query : ''), true, 301);
        exit;
    }

    // Arquivo que existe (css, js, imagens, ou .php num POST antigo).
    if ($caminho !== '/' && is_file(__DIR__ . $caminho)) {
        return false;
    }

    // Página: / → index.php, /prospeccao → prospeccao.php. Só letras,
    // números, "_", "-" e "/": nada de "..".
    $pagina = $caminho === '/' ? '/index' : $caminho;
    if (preg_match('#^/[\w/-]+$#', $pagina) && is_file(__DIR__ . $pagina . '.php')) {
        return __DIR__ . $pagina . '.php';
    }

    // Página que não existe: a 404 do painel só para nomes de um nível, como
    // no .htaccess (em /a/b os links relativos da página quebrariam).
    if (preg_match('#^/[\w-]+$#', $pagina)) {
        return __DIR__ . '/404.php';
    }

    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Não encontrado: {$caminho}\n";
    exit;
}

$roteador_arquivo = roteador_resolver();
if ($roteador_arquivo === false) {
    return false;
}
require $roteador_arquivo;
