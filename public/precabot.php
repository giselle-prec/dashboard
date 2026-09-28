<?php
    $rota = 'precabot';
    require __DIR__ . '/../src/guarda.php';
    require __DIR__ . '/../src/connection.php';
    require __DIR__ . '/../src/crud.php';

    $read_ente = DBread($pdo, 'Ente', 'ORDER BY Ente') ?: [];

    $title = "Tabela do Sistema (Precabot)";
    require __DIR__ . '/templates/head.php';
?>

<body>
<?php require __DIR__ . '/templates/scripts.php' ?>
<?php require __DIR__ . '/templates/nav_top.php' ?>

<div class="container-fluid" style="max-width: 1400px;">
    <h2>Tabela do Sistema (Precabot)</h2>

    <form id="form-precabot" class="row g-3 align-items-end mb-4">
        <div class="col-12">
            <label class="form-label d-block fw-bold">Escopo</label>
            <div class="form-check form-check-inline">
                <input class="form-check-input" type="radio" name="escopo" id="escopo-ente" value="ente" checked>
                <label class="form-check-label" for="escopo-ente">Pelo Ente</label>
            </div>
            <div class="form-check form-check-inline">
                <input class="form-check-input" type="radio" name="escopo" id="escopo-todos" value="todos_exceto">
                <label class="form-check-label" for="escopo-todos">Todos Exceto ERJ e TRF2</label>
            </div>
            <div class="form-check form-check-inline">
                <input class="form-check-input" type="radio" name="escopo" id="escopo-erj" value="erj">
                <label class="form-check-label" for="escopo-erj">Estado do Rio de Janeiro</label>
            </div>
            <div class="form-check form-check-inline">
                <input class="form-check-input" type="radio" name="escopo" id="escopo-trf2" value="trf2">
                <label class="form-check-label" for="escopo-trf2">União TRF2</label>
            </div>
        </div>

        <div class="col-md-5" id="grupo-ente">
            <label for="ente_id" class="form-label">Ente</label>
            <select class="form-select" id="ente_id" name="ente_id">
                <option value="" selected disabled>Selecione um Ente</option>
                <?php foreach ($read_ente as $ente): ?>
                <option value="<?php echo htmlspecialchars($ente['ente_id']); ?>"><?php echo htmlspecialchars($ente['Ente']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="col-md-4 form-check ms-2">
            <input class="form-check-input" type="checkbox" id="apenas_pendentes" name="apenas_pendentes">
            <label class="form-check-label" for="apenas_pendentes">Somente Precatórios Pendentes</label>
        </div>

        <div class="col-12">
            <button type="submit" class="btn btn-primary" id="btn-buscar">Buscar</button>
            <span class="ms-2 text-muted d-none" id="carregando">Carregando...</span>
        </div>
    </form>

    <div id="alerta-erro" class="alert alert-danger d-none" role="alert"></div>

    <table id="tabela-precabot" class="table table-striped table-bordered nowrap w-100"></table>
</div>

<!-- jQuery -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<!-- DataTables (bootstrap 5) com botões de exportação -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/pdfmake.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/vfs_fonts.js"></script>
<script src="https://cdn.datatables.net/v/bs5/dt-2.1.8/b-3.1.2/b-html5-3.1.2/datatables.min.js"></script>

<script src="js/precabot.js"></script>

<?php require __DIR__ . '/templates/footer.php'; ?>
</body>
</html>
