<?php
    $rota = '404';
    require __DIR__ . '/../src/guarda.php';

    http_response_code(404);
    $title = "Página não encontrada";
    require __DIR__ . '/templates/head.php';
?>

<body class="com-sidebar">
<?php require __DIR__ . '/templates/nav_top.php' ?>
<div class="container">
    <div class="error-card-custom">
        <h1 class="error-title-huge">4<img src="img/logo-precapp.svg" alt="0">4</h1>
        <p>A página que você procurou não existe.</p>
        <a href="index.php" class="btn btn-primary">Voltar ao início</a>
    </div>
</div>
<?php require __DIR__ . '/templates/scripts.php' ?>
<?php require __DIR__ . '/templates/footer.php'; ?>
</body>
</html>
