<?php
    require __DIR__ . '/../src/connection.php';
    require __DIR__ . '/../src/crud.php';
    require __DIR__ . '/../src/oxigenacao_repository.php';

    $read_ente   = DBread($pdo, 'Ente', 'ORDER BY Ente');
    $orcamentos  = oxigenacao_opcoes_orcamento($pdo);
    $consultores = oxigenacao_opcoes_consultor($pdo);
    $naturezas   = oxigenacao_opcoes_natureza($pdo);

    $hoje       = date('Y-m-d');
    $inicio_mes = date('Y-m-01');

    $title = "Painel de Oxigenação";
    require __DIR__ . '/templates/head.php';
?>

<body>
<?php require __DIR__ . '/templates/scripts.php' ?>
<?php require __DIR__ . '/templates/nav_top.php' ?>

<style>
    /* Espaço para a legenda dos gráficos de status, que fica à direita. */
    #chart-status-destino,
    #chart-foto {
        height: 380px;
    }
</style>

<div class="container-fluid" style="max-width: 1400px;">
    <h2>Painel de Oxigenação</h2>
    <p class="text-muted">
        Oxigenação é a saída do precatório do status <strong>Sem Tentativa</strong> para qualquer outro status.
        Cada precatório é contado uma única vez, na data em que saiu pela primeira vez.
    </p>

    <div class="card mb-4">
        <div class="card-body">
            <h6 class="card-title">Filtros (valem para as duas abas)</h6>
            <div class="row g-3">
                <div class="col-md-3">
                    <label for="ente_id" class="form-label">
                        Ente
                        <button type="button" class="btn btn-link btn-sm p-0 ms-1 btn-selecionar-todos" data-target="#ente_id">selecionar todos</button> ·
                        <button type="button" class="btn btn-link btn-sm p-0 btn-limpar-selecao" data-target="#ente_id">limpar</button>
                    </label>
                    <select class="form-select select2-multi" id="ente_id" name="ente_id[]" multiple
                            data-placeholder="Todos os Entes">
                        <?php foreach ($read_ente as $ente): ?>
                        <option value="<?php echo htmlspecialchars($ente['ente_id']); ?>"><?php echo htmlspecialchars($ente['Ente']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">Nenhum selecionado = todos</div>
                </div>
                <div class="col-md-3">
                    <label for="consultor_id" class="form-label">
                        Consultor
                        <button type="button" class="btn btn-link btn-sm p-0 ms-1 btn-selecionar-todos" data-target="#consultor_id">selecionar todos</button> ·
                        <button type="button" class="btn btn-link btn-sm p-0 btn-limpar-selecao" data-target="#consultor_id">limpar</button>
                    </label>
                    <select class="form-select select2-multi" id="consultor_id" name="consultor_id[]" multiple
                            data-placeholder="Todos os consultores">
                        <?php foreach ($consultores as $consultor):
                            $ativo = (string)$consultor['Active'] === '1';
                            $nome = trim($consultor['FirstName'] . ' ' . (string)$consultor['LastName']);
                        ?>
                        <option value="<?php echo htmlspecialchars($consultor['Negociador']); ?>"
                                data-ativo="<?php echo $ativo ? '1' : '0'; ?>"><?php
                            echo htmlspecialchars($ativo ? $nome : $nome . ' (inativo)');
                        ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" id="incluir_consultores_inativos">
                        <label class="form-check-label small" for="incluir_consultores_inativos">
                            Incluir consultores inativos
                        </label>
                    </div>
                    <div class="form-text">Consultor atual do precatório</div>
                </div>
                <div class="col-md-2">
                    <label for="orcamento" class="form-label">
                        Orçamento
                        <button type="button" class="btn btn-link btn-sm p-0 ms-1 btn-selecionar-todos" data-target="#orcamento">todos</button> ·
                        <button type="button" class="btn btn-link btn-sm p-0 btn-limpar-selecao" data-target="#orcamento">limpar</button>
                    </label>
                    <select class="form-select select2-multi" id="orcamento" name="orcamento[]" multiple
                            data-placeholder="Todos">
                        <?php foreach ($orcamentos as $orcamento): ?>
                        <option value="<?php echo htmlspecialchars($orcamento['Orcamento']); ?>"><?php echo htmlspecialchars($orcamento['Orcamento']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label for="natureza_id" class="form-label">
                        Natureza
                        <button type="button" class="btn btn-link btn-sm p-0 ms-1 btn-selecionar-todos" data-target="#natureza_id">todas</button> ·
                        <button type="button" class="btn btn-link btn-sm p-0 btn-limpar-selecao" data-target="#natureza_id">limpar</button>
                    </label>
                    <select class="form-select select2-multi" id="natureza_id" name="natureza_id[]" multiple
                            data-placeholder="Todas">
                        <?php foreach ($naturezas as $natureza): ?>
                        <option value="<?php echo htmlspecialchars($natureza['natuPrec_id']); ?>"><?php echo htmlspecialchars($natureza['Natureza']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label for="valor_min" class="form-label">Valor mínimo (R$)</label>
                    <input type="number" step="0.01" min="0" class="form-control" id="valor_min" placeholder="Todos">

                    <label for="valor_max" class="form-label">Valor máximo (R$)</label>
                    <input type="number" step="0.01" min="0" class="form-control" id="valor_max" placeholder="Todos">

                    <label for="previsao_max" class="form-label">Máxima previsão de pagamento</label>
                    <input type="date" class="form-control" id="previsao_max">
                    <div class="form-text">Em branco = todas</div>
                </div>
                <div class="col-12">
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" id="excluir_status_contrato">
                        <label class="form-check-label" for="excluir_status_contrato">
                            Excluir contratos assinados/parciais (status 66, 72, 74 e 75)
                        </label>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div id="alerta-erro" class="alert alert-danger d-none" role="alert"></div>

    <ul class="nav nav-tabs" id="abas-oxigenacao" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="aba-periodo-btn" data-bs-toggle="tab" data-bs-target="#aba-periodo"
                    type="button" role="tab">Oxigenação por período</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="aba-foto-btn" data-bs-toggle="tab" data-bs-target="#aba-foto"
                    type="button" role="tab">Foto por data</button>
        </li>
    </ul>

    <div class="tab-content border border-top-0 p-3 mb-4">
        <!-- ABA 1 -->
        <div class="tab-pane fade show active" id="aba-periodo" role="tabpanel">
            <form id="form-periodo" class="row g-3 align-items-end mb-4">
                <div class="col-md-3">
                    <label for="data_inicio" class="form-label">Data inicial</label>
                    <input type="date" class="form-control" id="data_inicio" value="<?php echo $inicio_mes; ?>" required>
                </div>
                <div class="col-md-3">
                    <label for="data_fim" class="form-label">Data final</label>
                    <input type="date" class="form-control" id="data_fim" value="<?php echo $hoje; ?>" required>
                </div>
                <div class="col-md-2">
                    <label for="granularidade" class="form-label">Agrupar por</label>
                    <select class="form-select" id="granularidade">
                        <option value="dia">Dia</option>
                        <option value="semana">Semana</option>
                        <option value="mes" selected>Mês</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label for="metrica" class="form-label">Métrica</label>
                    <select class="form-select" id="metrica">
                        <option value="qtd" selected>Quantidade</option>
                        <option value="valor">Valor de face</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100">Buscar</button>
                </div>
            </form>

            <div class="row g-3 mb-4">
                <div class="col-md-3">
                    <div class="card text-bg-success h-100">
                        <div class="card-body">
                            <h6 class="card-title">Precatórios oxigenados</h6>
                            <p class="card-text fs-5 mb-0" id="kpi-qtd">-</p>
                            <p class="card-text" id="kpi-valor">-</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card text-bg-secondary h-100">
                        <div class="card-body">
                            <h6 class="card-title">Ainda em Sem Tentativa (hoje)</h6>
                            <p class="card-text fs-5 mb-0" id="kpi-base-qtd">-</p>
                            <p class="card-text" id="kpi-base-valor">-</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card text-bg-info h-100">
                        <div class="card-body">
                            <h6 class="card-title">Entes alcançados</h6>
                            <p class="card-text fs-5 mb-0" id="kpi-entes">-</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card text-bg-light h-100">
                        <div class="card-body">
                            <h6 class="card-title">Valor médio por precatório</h6>
                            <p class="card-text fs-5 mb-0" id="kpi-ticket">-</p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-3 mb-4">
                <div class="col-12">
                    <div id="chart-tempo" style="height: 350px;"></div>
                </div>
                <div class="col-md-6">
                    <div id="chart-ente" style="height: 350px;"></div>
                </div>
                <div class="col-md-6">
                    <div id="chart-consultor" style="height: 350px;"></div>
                </div>
            </div>

            <div class="row g-3 mb-4">
                <div class="col-12">
                    <div id="chart-status-destino"></div>
                    <div class="form-text text-center">
                        Clique em uma fatia para ver a quebra por ente e por consultor daquele status.
                    </div>
                </div>
                <div class="col-md-6">
                    <div id="chart-status-ente" style="height: 350px;"></div>
                </div>
                <div class="col-md-6">
                    <div id="chart-status-consultor" style="height: 350px;"></div>
                </div>
            </div>

            <div id="aviso-truncado" class="alert alert-warning d-none" role="alert"></div>

            <table id="tabela-eventos" class="table table-striped table-bordered w-100">
                <thead></thead>
                <tbody></tbody>
            </table>
        </div>

        <!-- ABA 2 -->
        <div class="tab-pane fade" id="aba-foto" role="tabpanel">
            <form id="form-foto" class="row g-3 align-items-end mb-4">
                <div class="col-md-3">
                    <label for="data_ref" class="form-label">Data da foto</label>
                    <input type="date" class="form-control" id="data_ref" value="<?php echo $hoje; ?>" required>
                </div>
                <div class="col-md-2">
                    <label for="metrica_foto" class="form-label">Métrica</label>
                    <select class="form-select" id="metrica_foto">
                        <option value="qtd" selected>Quantidade</option>
                        <option value="valor">Valor de face</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" id="somente_pendentes" checked>
                        <label class="form-check-label" for="somente_pendentes">
                            Somente pendentes de pagamento na data
                        </label>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" id="agrupar_pai">
                        <label class="form-check-label" for="agrupar_pai">Agrupar por status pai</label>
                    </div>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100">Buscar</button>
                </div>
            </form>

            <div id="aviso-cobertura" class="alert alert-info d-none" role="alert"></div>

            <div class="row g-3 mb-4">
                <div class="col-md-3">
                    <div class="card text-bg-secondary h-100">
                        <div class="card-body">
                            <h6 class="card-title">Total na data</h6>
                            <p class="card-text fs-5 mb-0" id="foto-total-qtd">-</p>
                            <p class="card-text" id="foto-total-valor">-</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card text-bg-light h-100">
                        <div class="card-body">
                            <h6 class="card-title">Sem Tentativa <small class="text-muted">(aproximado)</small></h6>
                            <p class="card-text fs-5 mb-0" id="foto-sem-tentativa-qtd">-</p>
                            <p class="card-text" id="foto-sem-tentativa-valor">-</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card text-bg-info h-100">
                        <div class="card-body">
                            <h6 class="card-title">Demais status</h6>
                            <p class="card-text fs-5 mb-0" id="foto-outros-qtd">-</p>
                            <p class="card-text" id="foto-outros-valor">-</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card text-bg-success h-100">
                        <div class="card-body">
                            <h6 class="card-title">Oxigenados até a data</h6>
                            <p class="card-text fs-5 mb-0" id="foto-acumulado-qtd">-</p>
                            <p class="card-text" id="foto-acumulado-valor">-</p>
                            <p class="card-text small mb-0">Acumulado, não é foto</p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-3 mb-4">
                <div class="col-12">
                    <div id="chart-foto"></div>
                    <div class="form-text text-center">
                        Sem Tentativa fica fora do gráfico (está no card acima). Clique em uma fatia para ver a
                        quebra por ente e por consultor daquele status.
                    </div>
                </div>
                <div class="col-md-6">
                    <div id="chart-foto-ente" style="height: 350px;"></div>
                </div>
                <div class="col-md-6">
                    <div id="chart-foto-consultor" style="height: 350px;"></div>
                </div>
            </div>

            <table id="tabela-foto" class="table table-striped table-bordered w-100">
                <thead></thead>
                <tbody></tbody>
            </table>
        </div>
    </div>

    <div class="alert alert-secondary small">
        <strong>Como os números são calculados.</strong>
        A única fonte de status ao longo do tempo é o histórico de contato
        (<code>HistoricoContato.ResultContatoId</code>). Por isso:
        <ul class="mb-0">
            <li>A data de oxigenação é a do primeiro contato cujo resultado saiu da família <em>Sem Tentativa</em>.</li>
            <li>Na foto de uma data passada, o status é o resultado do último contato até aquele dia; sem contato, o
                precatório aparece como <em>Sem Tentativa</em>. Na data de hoje o painel usa o status atual da tabela
                de precatórios, que é exato e não depende do histórico.</li>
            <li>Mudanças feitas fora do fluxo de contato (ex.: <em>Pago pelo ente</em>, <em>Pausado</em>, alterações em
                lote) não estão no histórico e não são reconstruídas.</li>
            <li>&quot;Pendentes de pagamento na data&quot; parte do <code>prec_pg</code> e busca a data da quitação em
                quatro fontes, nesta ordem: a coluna <code>Precatorio.DataQuitacaoBatch</code>; a data da rodada de
                batch que quitou o precatório (<code>Precatorio.batch</code> = <code>BatchControl.n_batch_quit</code>
                do mesmo ente); o ano estimado pelo orçamento (regime comum + 2 anos, especial + 4, Estado do Rio
                + 5); e, se nada disso servir, o precatório entra como já quitado em qualquer data. O aviso acima do
                gráfico mostra quantos estão em cada situação.</li>
            <li>Os cards de status são uma <strong>foto</strong>: valem para aquele dia. O card
                &quot;oxigenados até a data&quot; é <strong>acumulado</strong>: conta quem saiu de Sem Tentativa alguma
                vez até ali, mesmo que depois tenha voltado ou sido quitado. Os dois números não batem entre si de
                propósito.</li>
            <li>A foto por data e a base &quot;Sem Tentativa&quot; consultam a tabela <code>Precatorio</code> direto, e
                não a view <code>precatoriodetalhe</code>. Por isso contam também os precatórios que a view descarta
                por falta de cadastro relacionado (credor, advogado, réu, tabela de cálculo).</li>
        </ul>
    </div>
</div>

<!-- jQuery -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<!-- DataTables (bootstrap 5 build) -->
<script src="https://cdn.datatables.net/v/bs5/dt-2.1.8/b-3.1.2/datatables.min.js"></script>
<!-- AnyChart -->
<script src="https://cdn.anychart.com/releases/latest/js/anychart-base.min.js"></script>
<!-- Select2 (multi-select com busca; independente do JS do Bootstrap) -->
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<script src="js/oxigenacao.js"></script>

<?php require __DIR__ . '/templates/footer.php'; ?>
</body>
</html>
