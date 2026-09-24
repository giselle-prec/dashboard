<?php
    $rota = 'login';
    require __DIR__ . '/../src/guarda.php';

    $erros = [
        '1' => 'Preencha o e-mail e a senha.',
        '2' => 'E-mail ou senha inválidos.',
    ];
    $erro = $erros[$_GET['error'] ?? ''] ?? null;

    $title = "Login";
    require __DIR__ . '/templates/head.php';
?>

<body style="padding-top:4.2rem; padding-bottom:4.2rem; background:rgba(0, 0, 0, 0.76);">
<div class="container">
    <div class="row">
        <div class="col-md-5 mx-auto">
            <div class="card">
                <div class="card-body p-4">
                    <h1 class="text-center mb-3">Login</h1>

                    <?php if ($erro): ?>
                        <div class="alert alert-danger" role="alert"><?php echo htmlspecialchars($erro); ?></div>
                    <?php endif; ?>

                    <form action="valida_login.php" method="post" name="login">
                        <div class="form-group">
                            <label for="email">E-mail</label>
                            <input
                                type="email"
                                name="email"
                                id="email"
                                class="form-control"
                                placeholder="E-mail"
                                autocomplete="username"
                                required
                                autofocus>
                        </div>
                        <div class="form-group">
                            <label for="password">Senha</label>
                            <input
                                type="password"
                                name="password"
                                id="password"
                                class="form-control"
                                placeholder="Senha"
                                autocomplete="current-password"
                                required>
                        </div>
                        <p class="text-center mt-3">
                            Entre com suas credenciais de acesso do nosso sistema (Precabot - http://precapp.net/)
                        </p>
                        <button type="submit" class="btn btn-primary w-100">Entrar</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
