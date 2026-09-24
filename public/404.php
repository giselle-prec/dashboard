<?php
    $rota = '404';
    require __DIR__ . '/../src/guarda.php';

    http_response_code(404);
    $title = "Página não encontrada";
    require __DIR__ . '/templates/head.php';
?>

<body>
<?php require __DIR__ . '/templates/nav_top.php' ?>
<div class="container text-center" style="margin-top: 4rem;">
    <h1>404</h1>
    <p>A página que você procurou não existe.</p>
    <a href="index.php" class="btn btn-primary">Voltar ao início</a>
</div>
<?php require __DIR__ . '/templates/scripts.php' ?>
</body>
</html>
