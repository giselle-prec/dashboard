<?php
    require __DIR__ . '/../src/connection.php';
    require __DIR__ . '/../src/crud.php';

    $read_ente = DBread($pdo, 'Ente', 'ORDER BY Ente');

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
            <input type="number" class="form-control" id="orcamento" name="orcamento" value="<?php echo date('Y'); ?>" required>
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
        <div class="col-md-6">
            <div id="chart-resumo" style="height: 350px;"></div>
        </div>
        <div class="col-md-6">
            <div id="chart-status" style="height: 350px;"></div>
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
