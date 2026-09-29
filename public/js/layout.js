// Menu lateral: abrir/fechar no celular e minimizar no desktop.
(function () {
    var CHAVE_MINIMIZADO = 'precapp.sidebarMinimizado';
    var body = document.body;
    var sidebar = document.getElementById('sidebar');
    var overlay = document.getElementById('sidebar-overlay');
    var botaoMobile = document.getElementById('sidebar-toggle');
    var botaoDesktop = document.getElementById('desktop-sidebar-toggle');

    if (!sidebar) {
        return;
    }

    function lerPreferencia() {
        try {
            return window.localStorage.getItem(CHAVE_MINIMIZADO) === '1';
        } catch (e) {
            return false;
        }
    }

    function gravarPreferencia(minimizado) {
        try {
            window.localStorage.setItem(CHAVE_MINIMIZADO, minimizado ? '1' : '0');
        } catch (e) {
            // Sem localStorage (janela anônima etc.): só não lembra a escolha.
        }
    }

    // A largura do conteúdo muda com o menu; os gráficos (AnyChart) e as
    // tabelas (DataTables) se reajustam no evento resize da janela.
    function avisarMudancaDeLargura() {
        window.setTimeout(function () {
            window.dispatchEvent(new Event('resize'));
        }, 320);
    }

    function abrirMobile(abrir) {
        sidebar.classList.toggle('show', abrir);
        if (overlay) {
            overlay.classList.toggle('show', abrir);
        }
    }

    // nav_top.php já aplica a preferência antes do primeiro desenho; aqui
    // fica só a garantia.
    if (lerPreferencia()) {
        body.classList.add('sidebar-minimized');
    }

    if (botaoDesktop) {
        botaoDesktop.addEventListener('click', function () {
            var minimizado = body.classList.toggle('sidebar-minimized');
            botaoDesktop.setAttribute('aria-label', minimizado ? 'Expandir menu' : 'Minimizar menu');
            gravarPreferencia(minimizado);
            avisarMudancaDeLargura();
        });
    }

    if (botaoMobile) {
        botaoMobile.addEventListener('click', function () {
            abrirMobile(!sidebar.classList.contains('show'));
        });
    }

    if (overlay) {
        overlay.addEventListener('click', function () {
            abrirMobile(false);
        });
    }

    document.addEventListener('keydown', function (evento) {
        if (evento.key === 'Escape') {
            abrirMobile(false);
        }
    });
})();
