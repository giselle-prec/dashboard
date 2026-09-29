<?php
    // Menu lateral + barra superior. O item ativo vem de $rota, que toda
    // página define antes de incluir src/guarda.php.
    $menu_rota_atual = $rota ?? '';
    $menu_secoes = [
        'Menu' => [
            ['rota' => 'index',      'href' => 'index.php',      'icone' => 'fa-home',       'texto' => 'Início'],
        ],
        'Painéis' => [
            ['rota' => 'prospeccao', 'href' => 'prospeccao.php', 'icone' => 'fa-bar-chart',  'texto' => 'Painel de Prospecção'],
            ['rota' => 'oxigenacao', 'href' => 'oxigenacao.php', 'icone' => 'fa-line-chart', 'texto' => 'Painel de Oxigenação'],
        ],
        'Tabelas' => [
            ['rota' => 'precabot',   'href' => 'precabot.php',   'icone' => 'fa-table',      'texto' => 'Tabela do Sistema (Precabot)'],
            ['rota' => 'tjrj',       'href' => 'tjrj.php',       'icone' => 'fa-university', 'texto' => 'Tabela do Site do TJRJ'],
        ],
    ];
    $menu_usuario = isset($_SESSION['user']) ? htmlspecialchars($_SESSION['user']) : '';
?>
<script>
    // Aplica o "menu minimizado" antes do primeiro desenho, sem piscar.
    try { if (localStorage.getItem('precapp.sidebarMinimizado') === '1') document.body.classList.add('sidebar-minimized'); } catch (e) {}
</script>
<aside class="sidebar-wrapper" id="sidebar">
    <a href="index.php" class="sidebar-brand">
        <img src="img/logo-precapp.svg" alt="" class="sidebar-brand-logo">
        <span>Precapp</span>
    </a>

    <nav class="sidebar-menu">
        <?php foreach ($menu_secoes as $secao => $itens): ?>
        <div class="sidebar-menu-section">
            <div class="sidebar-menu-title"><?php echo $secao; ?></div>
            <ul class="sidebar-menu-list">
                <?php foreach ($itens as $item): ?>
                <?php $ativo = $item['rota'] === $menu_rota_atual; ?>
                <li class="sidebar-menu-item">
                    <a href="<?php echo $item['href']; ?>"
                       class="sidebar-menu-link<?php echo $ativo ? ' active' : ''; ?>"
                       title="<?php echo $item['texto']; ?>"<?php echo $ativo ? ' aria-current="page"' : ''; ?>>
                        <i class="fa <?php echo $item['icone']; ?>" aria-hidden="true"></i>
                        <span><?php echo $item['texto']; ?></span>
                    </a>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endforeach; ?>
    </nav>

    <?php if ($menu_usuario !== ''): ?>
    <div class="sidebar-profile">
        <div class="sidebar-profile-avatar"><i class="fa fa-user" aria-hidden="true"></i></div>
        <div class="sidebar-profile-info">
            <div class="sidebar-profile-name">Usuário</div>
            <div class="sidebar-profile-email" title="<?php echo $menu_usuario; ?>"><?php echo $menu_usuario; ?></div>
        </div>
        <a href="logout.php" class="sidebar-profile-sair" title="Sair" aria-label="Sair">
            <i class="fa fa-sign-out" aria-hidden="true"></i>
        </a>
    </div>
    <?php endif; ?>
</aside>
<div class="sidebar-overlay" id="sidebar-overlay"></div>

<header class="navbar-custom">
    <div class="navbar-left">
        <button type="button" class="btn-desktop-toggle" id="desktop-sidebar-toggle" aria-label="Minimizar menu">
            <i class="fa fa-angle-double-left" aria-hidden="true"></i>
        </button>
        <button type="button" class="sidebar-toggle-btn" id="sidebar-toggle" aria-label="Abrir menu">
            <i class="fa fa-bars" aria-hidden="true"></i>
        </button>
        <span class="navbar-page-title"><?php echo htmlspecialchars($title ?? ''); ?></span>
    </div>

    <?php if ($menu_usuario !== ''): ?>
    <div class="navbar-actions">
        <div class="dropdown">
            <button class="navbar-profile-btn dropdown-toggle" type="button" id="profile-dropdown"
                    data-bs-toggle="dropdown" aria-expanded="false">
                <span class="navbar-profile-avatar"><i class="fa fa-user" aria-hidden="true"></i></span>
                <span class="navbar-profile-name"><?php echo $menu_usuario; ?></span>
                <i class="fa fa-chevron-down navbar-profile-caret" aria-hidden="true"></i>
            </button>
            <ul class="dropdown-menu dropdown-menu-end dropdown-menu-profile" aria-labelledby="profile-dropdown">
                <li class="dropdown-header">Bem-vindo(a)!</li>
                <li><a class="dropdown-item text-danger" href="logout.php"><i class="fa fa-sign-out" aria-hidden="true"></i> Sair</a></li>
            </ul>
        </div>
    </div>
    <?php endif; ?>
</header>
<script src="js/layout.js" defer></script>
