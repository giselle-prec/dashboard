<?php
    $rota = 'tjrj';
    require __DIR__ . '/../src/guarda.php';
    require __DIR__ . '/../src/tjrj_repository.php';

    $title = "Tabela do Site do TJRJ";
    require __DIR__ . '/templates/head.php';
?>

<body class="com-sidebar">
<?php require __DIR__ . '/templates/scripts.php' ?>
<?php require __DIR__ . '/templates/nav_top.php' ?>

<div class="container-fluid" style="max-width: 1400px;">
    <h1 class="page-title">Tabela do Site do TJRJ</h1>

    <form id="form-tjrj" class="row g-3 mb-4">
        <div class="col-12">
            <label class="form-label d-block fw-bold">Tipo de Consulta</label>
            <?php foreach (TJRJ_ORDENS as $valor => $titulo): ?>
            <?php $habilitada = in_array($valor, TJRJ_ORDENS_HABILITADAS, true); ?>
            <div class="form-check">
                <input class="form-check-input" type="radio" name="ordem" id="ordem-<?php echo $valor; ?>"
                       value="<?php echo $valor; ?>"
                       <?php echo $valor === TJRJ_ORDEM_CRONOLOGICA ? 'checked' : ''; ?>
                       <?php echo $habilitada ? '' : 'disabled'; ?>>
                <label class="form-check-label" for="ordem-<?php echo $valor; ?>"><?php echo htmlspecialchars($titulo); ?></label>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="col-md-5">
            <label for="ente_id" class="form-label">Ente</label>
            <select class="form-select" id="ente_id" name="ente_id" disabled>
                <option value="" selected disabled>Carregando entes do TJRJ...</option>
            </select>
        </div>
        <div class="col-12">
            <span class="text-muted d-none" id="carregando">Consultando o site do TJRJ, pode levar alguns segundos...</span>
        </div>
    </form>

    <div id="alerta-erro" class="alert alert-danger d-none" role="alert"></div>

    <table id="tabela-tjrj" class="table table-striped table-bordered nowrap w-100"></table>
</div>

<!-- jQuery -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<!-- DataTables (bootstrap 5) com botões de exportação -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/pdfmake.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/vfs_fonts.js"></script>
<script src="https://cdn.datatables.net/v/bs5/dt-2.1.8/b-3.1.2/b-html5-3.1.2/datatables.min.js"></script>

<script src="js/tjrj.js"></script>

<?php require __DIR__ . '/templates/footer.php'; ?>
</body>
</html>
