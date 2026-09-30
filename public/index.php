<?php
    $rota = 'index';
    require __DIR__ . '/../src/guarda.php';
    require __DIR__ . '/../src/inicio_repository.php';

    // A página não consulta o banco ao abrir: os números chegam depois, por
    // api/inicio.php. A prospecção varre todos os entes e é lenta, então não
    // pode segurar o desenho da tela. Aqui só se calcula quais dias entram na
    // comparação, o que não depende do banco.
    $janelas = inicio_janelas_semana(date('Y-m-d'));
    $rotulo_atual    = inicio_rotulo_janela($janelas['atual']);
    $rotulo_anterior = inicio_rotulo_janela($janelas['anterior']);

    $title = "Início";
    require __DIR__ . '/templates/head.php';
?>

<body class="com-sidebar">
<?php require __DIR__ . '/templates/scripts.php' ?>
<?php require __DIR__ . '/templates/nav_top.php' ?>

<style>
    .inicio-secao {
        margin-top: 2.5rem;
    }

    .inicio-secao-cabecalho {
        display: flex;
        flex-wrap: wrap;
        align-items: flex-end;
        justify-content: space-between;
        gap: 0.75rem 1.5rem;
        margin-bottom: 1rem;
    }

    .inicio-secao-cabecalho h2 {
        font-size: 1.25rem;
        margin-bottom: 0.25rem;
    }

    .inicio-secao-cabecalho .page-subtitle {
        margin-bottom: 0;
    }

    .inicio-variacao {
        font-weight: 700 !important;
    }

    .card[class*="text-bg-"] .card-text.inicio-variacao-sobe  { color: var(--sys-green); }
    .card[class*="text-bg-"] .card-text.inicio-variacao-desce { color: var(--sys-red); }

    .inicio-aguardando {
        color: var(--text-muted-green);
        display: flex;
        align-items: center;
        justify-content: center;
        height: 100%;
        padding: 1rem;
        text-align: center;
    }
</style>

<div class="container-fluid" style="max-width: 1400px;">
    <h1 class="page-title">Resumo semanal</h1>
    <p class="page-subtitle">
        Semana atual: <strong><?php echo $rotulo_atual; ?></strong> (5 dias úteis, contando hoje) ·
        comparada com <strong><?php echo $rotulo_anterior; ?></strong> (os 5 dias úteis anteriores).
        Dias úteis são de segunda a sexta; feriados não são descontados.
    </p>

    <!-- Cards de resumo -->
    <div class="row g-3">
        <div class="col-md-4">
            <div class="card text-bg-success h-100">
                <div class="card-body">
                    <h6 class="card-title">Oxigenados na semana</h6>
                    <p class="card-text fs-5 mb-0" id="card-oxi-qtd">-</p>
                    <p class="card-text inicio-variacao" id="card-oxi-variacao">Carregando...</p>
                    <p class="card-text small mb-0">
                        <?php echo $rotulo_atual; ?> vs <?php echo $rotulo_anterior; ?>
                    </p>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card text-bg-secondary h-100">
                <div class="card-body">
                    <h6 class="card-title">Base atual em Sem Tentativa</h6>
                    <p class="card-text fs-5 mb-0" id="card-base-qtd">-</p>
                    <p class="card-text" id="card-base-valor">Carregando...</p>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card text-bg-info h-100">
                <div class="card-body">
                    <h6 class="card-title">Valor total pendente de pagamento</h6>
                    <p class="card-text fs-5 mb-0" id="card-pendente-valor">-</p>
                    <p class="card-text" id="card-pendente-qtd">Carregando...</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Oxigenação -->
    <section class="inicio-secao">
        <div class="inicio-secao-cabecalho">
            <div>
                <h2>Oxigenação nos últimos 5 dias úteis</h2>
                <p class="page-subtitle">
                    <?php echo $rotulo_atual; ?> · precatórios que saíram de <strong>Sem Tentativa</strong>,
                    pelo consultor atual do precatório.
                </p>
            </div>
            <a href="oxigenacao.php" class="btn btn-primary">
                <i class="fa fa-line-chart" aria-hidden="true"></i> Abrir Painel de Oxigenação
            </a>
        </div>

        <div id="alerta-oxigenacao" class="alert alert-danger d-none" role="alert"></div>

        <div class="row g-3">
            <div class="col-12">
                <div id="chart-oxi-consultor" style="height: 380px;">
                    <div class="inicio-aguardando">Carregando...</div>
                </div>
            </div>
            <div class="col-12">
                <div id="chart-oxi-ente" style="height: 440px;">
                    <div class="inicio-aguardando">Carregando...</div>
                </div>
            </div>
        </div>
    </section>

    <!-- Prospecção -->
    <section class="inicio-secao">
        <div class="inicio-secao-cabecalho">
            <div>
                <h2>Prospecção de todos os entes com precatórios pendentes</h2>
                <p class="page-subtitle" id="prosp-subtitulo">
                    Mesmos números do Painel de Prospecção com todos esses entes selecionados e nenhum outro filtro.
                </p>
            </div>
            <a href="prospeccao.php" class="btn btn-primary">
                <i class="fa fa-bar-chart" aria-hidden="true"></i> Abrir Painel de Prospecção
            </a>
        </div>

        <div id="alerta-prospeccao" class="alert alert-danger d-none" role="alert"></div>

        <div class="row g-3 mb-3">
            <div class="col-md-3">
                <div class="card text-bg-secondary h-100">
                    <div class="card-body">
                        <h6 class="card-title">Total de Precatórios</h6>
                        <p class="card-text fs-5 mb-0" id="card-prosp-total-qtd">-</p>
                        <p class="card-text" id="card-prosp-total-valor">-</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-bg-success h-100">
                    <div class="card-body">
                        <h6 class="card-title">Prospectados</h6>
                        <p class="card-text fs-5 mb-0" id="card-prosp-prospectados-qtd">-</p>
                        <p class="card-text" id="card-prosp-prospectados-valor">-</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-bg-warning h-100">
                    <div class="card-body">
                        <h6 class="card-title">Pendentes c/ Requisitório</h6>
                        <p class="card-text fs-5 mb-0" id="card-prosp-pendente-com-qtd">-</p>
                        <p class="card-text" id="card-prosp-pendente-com-valor">-</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-bg-danger h-100">
                    <div class="card-body">
                        <h6 class="card-title">Pendentes s/ Requisitório</h6>
                        <p class="card-text fs-5 mb-0" id="card-prosp-pendente-sem-qtd">-</p>
                        <p class="card-text" id="card-prosp-pendente-sem-valor">-</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-3">
            <div class="col-md-6">
                <div id="chart-prosp-resumo" style="height: 350px;">
                    <div class="inicio-aguardando">Carregando...</div>
                </div>
            </div>
            <div class="col-md-6">
                <div id="chart-prosp-status" style="height: 350px;">
                    <div class="inicio-aguardando">Carregando...</div>
                </div>
            </div>
        </div>
    </section>

    <!-- Batch -->
    <section class="inicio-secao">
        <div class="inicio-secao-cabecalho">
            <div>
                <h2>Entes há mais tempo sem batch</h2>
                <p class="page-subtitle">
                    Entre os entes com precatórios pendentes, os 3 cujo último batch é o mais antigo.
                </p>
            </div>
        </div>

        <div class="row g-3" id="batch-mais-antigos">
            <div class="col-12 text-muted">Carregando...</div>
        </div>
        <p class="form-text mt-2 mb-0" id="batch-nota"></p>
    </section>
</div>

<!-- jQuery -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<!-- AnyChart -->
<script src="https://cdn.anychart.com/releases/latest/js/anychart-base.min.js"></script>

<script src="js/inicio.js"></script>

<?php require __DIR__ . '/templates/footer.php'; ?>
</body>
</html>
