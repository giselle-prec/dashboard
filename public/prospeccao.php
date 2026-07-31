<?php
    require __DIR__ . '/../src/connection.php';
    require __DIR__ . '/../src/crud.php';
    require __DIR__ . '/../src/prospeccao_repository.php';

    $read_ente = DBread($pdo, 'Ente', 'ORDER BY Ente');
    $naturezas = prospeccao_listar_naturezas($pdo);

    $title = "Painel de Prospecção";
    require __DIR__ . '/templates/head.php';
?>

<body>
<?php require __DIR__ . '/templates/scripts.php' ?>
<?php require __DIR__ . '/templates/nav_top.php' ?>

<div class="container-fluid" style="max-width: 1400px;">
    <h2>Painel de Prospecção</h2>

    <form id="form-prospeccao" class="row g-3 align-items-end mb-4">
        <div class="col-md-3">
            <label for="ente_id" class="form-label">Ente</label>
            <select class="form-select" id="ente_id" name="ente_id" required>
                <option value="" selected disabled>Selecione um Ente</option>
                <?php foreach ($read_ente as $ente): ?>
                <option value="<?php echo htmlspecialchars($ente['ente_id']); ?>"><?php echo htmlspecialchars($ente['Ente']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label for="orcamento" class="form-label">Orçamento</label>
            <input type="number" class="form-control" id="orcamento" name="orcamento" placeholder="Todos">
        </div>
        <div class="col-md-2">
            <label for="natureza_id" class="form-label">Natureza</label>
            <select class="form-select" id="natureza_id" name="natureza_id">
                <option value="" selected>Todas</option>
                <?php foreach ($naturezas as $natureza): ?>
                <option value="<?php echo htmlspecialchars($natureza); ?>"><?php echo htmlspecialchars($natureza); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label for="data_max" class="form-label">Previsão de Pagamento até</label>
            <input type="date" class="form-control" id="data_max" name="data_max" value="2030-01-01" required>
        </div>
        <div class="col-md-2">
            <label for="valor_min" class="form-label">Valor Mínimo (R$)</label>
            <input type="number" step="0.01" min="0" class="form-control" id="valor_min" name="valor_min" value="100000" required>
        </div>
        <div class="col-md-2 form-check ms-2">
            <input type="checkbox" class="form-check-input" id="por_consultora" name="por_consultora">
            <label class="form-check-label" for="por_consultora">Agrupar por consultora</label>
        </div>
        <div class="col-12">
            <button type="submit" class="btn btn-primary">Buscar</button>
        </div>
    </form>

    <div id="alerta-erro" class="alert alert-danger d-none" role="alert"></div>

    <div id="opcoes-consultora" class="mb-3 d-none">
        <div class="btn-group" role="group" aria-label="Modo de visualização por consultora">
            <input type="radio" class="btn-check" name="modo_consultora" id="modo-consultora-geral" value="geral" checked autocomplete="off">
            <label class="btn btn-outline-primary" for="modo-consultora-geral">Visão Geral</label>
            <input type="radio" class="btn-check" name="modo_consultora" id="modo-consultora-detalhe" value="detalhe" autocomplete="off">
            <label class="btn btn-outline-primary" for="modo-consultora-detalhe">Detalhe por Consultora</label>
        </div>
        <span class="d-inline-block ms-3 d-none" id="select-consultora-wrapper">
            <label for="select-consultora" class="form-label mb-0 me-1">Consultora:</label>
            <select class="form-select form-select-sm d-inline-block w-auto" id="select-consultora"></select>
        </span>
    </div>

    <div class="row g-3 mb-4" id="cards-resumo">
        <div class="col-md-3">
            <div class="card text-bg-secondary h-100">
                <div class="card-body">
                    <h6 class="card-title">Total de Precatórios</h6>
                    <p class="card-text fs-5 mb-0" id="card-total-qtd">-</p>
                    <p class="card-text" id="card-total-valor">-</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-bg-success h-100">
                <div class="card-body">
                    <h6 class="card-title">Prospectados</h6>
                    <p class="card-text fs-5 mb-0" id="card-prospectados-qtd">-</p>
                    <p class="card-text" id="card-prospectados-valor">-</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-bg-warning h-100">
                <div class="card-body">
                    <h6 class="card-title">Pendentes c/ Requisitório</h6>
                    <p class="card-text fs-5 mb-0" id="card-pendente-com-qtd">-</p>
                    <p class="card-text" id="card-pendente-com-valor">-</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-bg-danger h-100">
                <div class="card-body">
                    <h6 class="card-title">Pendentes s/ Requisitório</h6>
                    <p class="card-text fs-5 mb-0" id="card-pendente-sem-qtd">-</p>
                    <p class="card-text" id="card-pendente-sem-valor">-</p>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-6" id="col-chart-resumo">
            <div id="chart-resumo" style="height: 350px;"></div>
        </div>
        <div class="col-md-6" id="col-chart-status">
            <div id="chart-status" style="height: 350px;"></div>
        </div>
    </div>

    <!-- Agrupado por consultora + Visão Geral: desempenho de todas as consultoras -->
    <div id="secao-consultora-geral" class="d-none">
        <div class="row g-3 mb-4">
            <div class="col-12">
                <table id="tabela-consultora-resumo" class="table table-striped table-bordered w-100">
                    <thead></thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
        <div class="row g-3 mb-4">
            <div class="col-12">
                <div id="chart-consultora-empilhado" style="height: 450px;"></div>
            </div>
        </div>
    </div>

    <!-- Agrupado por consultora + Detalhe: os 4 cards e os 2 gráficos de quando
         não está agrupado, só que filtrados para a consultora selecionada -->
    <div id="secao-consultora-detalhe" class="d-none">
        <div class="row g-3 mb-4" id="cards-consultora">
            <div class="col-md-3">
                <div class="card text-bg-secondary h-100">
                    <div class="card-body">
                        <h6 class="card-title">Total de Precatórios</h6>
                        <p class="card-text fs-5 mb-0" id="card-consultora-total-qtd">-</p>
                        <p class="card-text" id="card-consultora-total-valor">-</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-bg-success h-100">
                    <div class="card-body">
                        <h6 class="card-title">Prospectados</h6>
                        <p class="card-text fs-5 mb-0" id="card-consultora-prospectados-qtd">-</p>
                        <p class="card-text" id="card-consultora-prospectados-valor">-</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-bg-warning h-100">
                    <div class="card-body">
                        <h6 class="card-title">Pendentes c/ Requisitório</h6>
                        <p class="card-text fs-5 mb-0" id="card-consultora-pendente-com-qtd">-</p>
                        <p class="card-text" id="card-consultora-pendente-com-valor">-</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-bg-danger h-100">
                    <div class="card-body">
                        <h6 class="card-title">Pendentes s/ Requisitório</h6>
                        <p class="card-text fs-5 mb-0" id="card-consultora-pendente-sem-qtd">-</p>
                        <p class="card-text" id="card-consultora-pendente-sem-valor">-</p>
                    </div>
                </div>
            </div>
        </div>
        <div class="row g-3 mb-4">
            <div class="col-md-6">
                <div id="chart-consultora-resumo" style="height: 350px;"></div>
            </div>
            <div class="col-md-6">
                <div id="chart-consultora-status" style="height: 350px;"></div>
            </div>
        </div>
    </div>

    <h4>Melhores Negociações <small class="text-muted">(com filtro de previsão de pagamento e valor mínimo)</small></h4>
    <div class="row g-3 mb-4" id="cards-melhores">
        <div class="col-md-3">
            <div class="card text-bg-secondary h-100">
                <div class="card-body">
                    <h6 class="card-title">Total de Precatórios</h6>
                    <p class="card-text fs-5 mb-0" id="card-melhores-total-qtd">-</p>
                    <p class="card-text" id="card-melhores-total-valor">-</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-bg-success h-100">
                <div class="card-body">
                    <h6 class="card-title">Prospectados</h6>
                    <p class="card-text fs-5 mb-0" id="card-melhores-prospectados-qtd">-</p>
                    <p class="card-text" id="card-melhores-prospectados-valor">-</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-bg-warning h-100">
                <div class="card-body">
                    <h6 class="card-title">Pendentes c/ Requisitório</h6>
                    <p class="card-text fs-5 mb-0" id="card-melhores-pendente-com-qtd">-</p>
                    <p class="card-text" id="card-melhores-pendente-com-valor">-</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-bg-danger h-100">
                <div class="card-body">
                    <h6 class="card-title">Pendentes s/ Requisitório</h6>
                    <p class="card-text fs-5 mb-0" id="card-melhores-pendente-sem-qtd">-</p>
                    <p class="card-text" id="card-melhores-pendente-sem-valor">-</p>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-6" id="col-chart-melhores-resumo">
            <div id="chart-melhores-resumo" style="height: 350px;"></div>
        </div>
        <div class="col-md-6" id="col-chart-melhores-status">
            <div id="chart-melhores-status" style="height: 350px;"></div>
        </div>
    </div>

    <!-- Agrupado por consultora + Visão Geral: mesma tabela-resumo acima já
         traz os números de melhores negociações; aqui só o empilhado por status -->
    <div id="secao-consultora-geral-melhores" class="d-none row g-3 mb-4">
        <div class="col-12">
            <div id="chart-consultora-empilhado-melhores" style="height: 450px;"></div>
        </div>
    </div>

    <!-- Agrupado por consultora + Detalhe: cards e gráficos de melhores negociações da consultora selecionada -->
    <div id="secao-consultora-detalhe-melhores" class="d-none">
        <div class="row g-3 mb-4" id="cards-consultora-melhores">
            <div class="col-md-3">
                <div class="card text-bg-secondary h-100">
                    <div class="card-body">
                        <h6 class="card-title">Total de Precatórios</h6>
                        <p class="card-text fs-5 mb-0" id="card-consultora-melhores-total-qtd">-</p>
                        <p class="card-text" id="card-consultora-melhores-total-valor">-</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-bg-success h-100">
                    <div class="card-body">
                        <h6 class="card-title">Prospectados</h6>
                        <p class="card-text fs-5 mb-0" id="card-consultora-melhores-prospectados-qtd">-</p>
                        <p class="card-text" id="card-consultora-melhores-prospectados-valor">-</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-bg-warning h-100">
                    <div class="card-body">
                        <h6 class="card-title">Pendentes c/ Requisitório</h6>
                        <p class="card-text fs-5 mb-0" id="card-consultora-melhores-pendente-com-qtd">-</p>
                        <p class="card-text" id="card-consultora-melhores-pendente-com-valor">-</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-bg-danger h-100">
                    <div class="card-body">
                        <h6 class="card-title">Pendentes s/ Requisitório</h6>
                        <p class="card-text fs-5 mb-0" id="card-consultora-melhores-pendente-sem-qtd">-</p>
                        <p class="card-text" id="card-consultora-melhores-pendente-sem-valor">-</p>
                    </div>
                </div>
            </div>
        </div>
        <div class="row g-3 mb-4">
            <div class="col-md-6">
                <div id="chart-consultora-melhores-resumo" style="height: 350px;"></div>
            </div>
            <div class="col-md-6">
                <div id="chart-consultora-melhores-status" style="height: 350px;"></div>
            </div>
        </div>
    </div>

    <table id="tabela-detalhe" class="table table-striped table-bordered w-100">
        <thead></thead>
        <tbody></tbody>
    </table>
</div>

<!-- jQuery -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<!-- DataTables (bootstrap 5 build) -->
<script src="https://cdn.datatables.net/v/bs5/dt-2.1.8/b-3.1.2/datatables.min.js"></script>
<!-- AnyChart -->
<script src="https://cdn.anychart.com/releases/latest/js/anychart-base.min.js"></script>

<script src="js/prospeccao.js"></script>

<?php require __DIR__ . '/templates/footer.php'; ?>
</body>
</html>
