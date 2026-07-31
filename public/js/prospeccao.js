(function ($) {
    'use strict';

    var moedaFormatter = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' });
    var inteiroFormatter = new Intl.NumberFormat('pt-BR');
    var STATUS_ID_PENDENTE = 65;

    var tabela = null;
    var tabelasConsultora = {};
    var charts = {};

    // Última resposta carregada — a troca de modo/consultora reaproveita
    // esses dados e recalcula tudo no navegador, sem nova requisição.
    var ultimoDetalhe = [];
    var ultimoResumo = null;
    var ultimoAgrupado = false;

    function formatarMoeda(valor) {
        return moedaFormatter.format(Number(valor) || 0);
    }

    function formatarInteiro(valor) {
        return inteiroFormatter.format(Number(valor) || 0);
    }

    function coletarFiltros() {
        return {
            ente_id: $('#ente_id').val(),
            orcamento: $('#orcamento').val(),
            natureza_id: $('#natureza_id').val(),
            data_max: $('#data_max').val(),
            valor_min: $('#valor_min').val(),
            por_consultora: $('#por_consultora').is(':checked') ? 1 : 0
        };
    }

    function mostrarErro(mensagem) {
        $('#alerta-erro').removeClass('d-none').text(mensagem);
    }

    function limparErro() {
        $('#alerta-erro').addClass('d-none').text('');
    }

    function disposeChart(containerId) {
        if (charts[containerId]) {
            charts[containerId].dispose();
            delete charts[containerId];
        }
    }

    // ---- Cálculo de resumos a partir do detalhe (feito no navegador) ----

    // Equivalente ao resumo geral do servidor (prospectados/pendentes com e
    // sem requisitório), mas a partir de um subconjunto do detalhe — usado
    // para o resumo por consultora e para o "Detalhe por Consultora".
    function calcularResumoDeStatus(detalhe) {
        var resumo = {
            qtd_total: 0, valor_total: 0,
            qtd_prospectados: 0, valor_prospectados: 0,
            qtd_pendente_com_req: 0, valor_pendente_com_req: 0,
            qtd_pendente_sem_req: 0, valor_pendente_sem_req: 0
        };

        detalhe.forEach(function (linha) {
            var qtd = Number(linha.QuantidadeTotal) || 0;
            var valor = Number(linha.ValorTotal) || 0;
            resumo.qtd_total += qtd;
            resumo.valor_total += valor;

            if (Number(linha.StatusId) === STATUS_ID_PENDENTE) {
                resumo.qtd_pendente_com_req += Number(linha.ComRequisitorio) || 0;
                resumo.valor_pendente_com_req += Number(linha.ValorComRequisitorio) || 0;
                resumo.qtd_pendente_sem_req += Number(linha.SemRequisitorio) || 0;
                resumo.valor_pendente_sem_req += Number(linha.ValorSemRequisitorio) || 0;
            } else {
                resumo.qtd_prospectados += qtd;
                resumo.valor_prospectados += valor;
            }
        });

        return resumo;
    }

    // Espelha calcularResumoDeStatus(), mas usando os campos "Melhores" (já
    // filtrados por previsão de pagamento e valor mínimo).
    function calcularResumoMelhores(detalhe) {
        var resumo = {
            qtd_total: 0, valor_total: 0,
            qtd_prospectados: 0, valor_prospectados: 0,
            qtd_pendente_com_req: 0, valor_pendente_com_req: 0,
            qtd_pendente_sem_req: 0, valor_pendente_sem_req: 0
        };

        detalhe.forEach(function (linha) {
            var qtdMelhores = Number(linha.QtdMelhores) || 0;
            var valorMelhores = Number(linha.ValorMelhores) || 0;
            resumo.qtd_total += qtdMelhores;
            resumo.valor_total += valorMelhores;

            if (Number(linha.StatusId) === STATUS_ID_PENDENTE) {
                resumo.qtd_pendente_com_req += Number(linha.QtdMelhoresComReq) || 0;
                resumo.valor_pendente_com_req += Number(linha.ValorMelhoresComReq) || 0;
                resumo.qtd_pendente_sem_req += Number(linha.QtdMelhoresSemReq) || 0;
                resumo.valor_pendente_sem_req += Number(linha.ValorMelhoresSemReq) || 0;
            } else {
                resumo.qtd_prospectados += qtdMelhores;
                resumo.valor_prospectados += valorMelhores;
            }
        });

        return resumo;
    }

    // ---- Cards (genérico: prefixoId define o conjunto de elementos alvo) ----

    function preencherCards(resumo, prefixoId) {
        $('#' + prefixoId + '-total-qtd').text(formatarInteiro(resumo.qtd_total) + ' precatórios');
        $('#' + prefixoId + '-total-valor').text(formatarMoeda(resumo.valor_total));

        $('#' + prefixoId + '-prospectados-qtd').text(formatarInteiro(resumo.qtd_prospectados) + ' precatórios');
        $('#' + prefixoId + '-prospectados-valor').text(formatarMoeda(resumo.valor_prospectados));

        $('#' + prefixoId + '-pendente-com-qtd').text(formatarInteiro(resumo.qtd_pendente_com_req) + ' precatórios');
        $('#' + prefixoId + '-pendente-com-valor').text(formatarMoeda(resumo.valor_pendente_com_req));

        $('#' + prefixoId + '-pendente-sem-qtd').text(formatarInteiro(resumo.qtd_pendente_sem_req) + ' precatórios');
        $('#' + prefixoId + '-pendente-sem-valor').text(formatarMoeda(resumo.valor_pendente_sem_req));
    }

    // ---- Tabela detalhe (por status, opcionalmente por consultora) ----

    function colunasTabela(agrupadoPorConsultora) {
        var colunas = [
            { data: 'Ente', title: 'Ente' },
            { data: 'StatusPrec', title: 'Status' },
            { data: 'StatusId', title: 'Status Id' }
        ];

        if (agrupadoPorConsultora) {
            colunas.push({ data: 'FirstName', title: 'Consultora' });
        }

        colunas.push(
            { data: 'QuantidadeTotal', title: 'Quantidade Total' },
            { data: 'ValorTotal', title: 'Valor Total', render: renderMoeda },
            { data: 'ComRequisitorio', title: 'Com Requisitório' },
            { data: 'ValorComRequisitorio', title: 'Valor Com Req.', render: renderMoeda },
            { data: 'SemRequisitorio', title: 'Sem Requisitório' },
            { data: 'ValorSemRequisitorio', title: 'Valor Sem Req.', render: renderMoeda },
            { data: 'QtdMelhores', title: 'Qtd. Melhores' },
            { data: 'ValorMelhores', title: 'Valor Melhores', render: renderMoeda },
            { data: 'QtdMelhoresComReq', title: 'Qtd. Melhores c/ Req' },
            { data: 'ValorMelhoresComReq', title: 'Valor Melhores c/ Req', render: renderMoeda },
            { data: 'QtdMelhoresSemReq', title: 'Qtd. Melhores s/ Req' },
            { data: 'ValorMelhoresSemReq', title: 'Valor Melhores s/ Req', render: renderMoeda }
        );

        return colunas;
    }

    function renderMoeda(data, type) {
        if (type === 'display') {
            return formatarMoeda(data);
        }
        return data;
    }

    function atualizarTabela(detalhe, agrupadoPorConsultora) {
        if (tabela) {
            tabela.destroy();
            $('#tabela-detalhe').empty().append('<thead></thead><tbody></tbody>');
        }

        var colunas = colunasTabela(agrupadoPorConsultora);
        var cabecalho = '<tr>' + colunas.map(function (c) { return '<th>' + c.title + '</th>'; }).join('') + '</tr>';
        $('#tabela-detalhe thead').html(cabecalho);

        tabela = $('#tabela-detalhe').DataTable({
            data: detalhe,
            columns: colunas,
            language: {
                url: 'https://cdn.datatables.net/plug-ins/2.1.8/i18n/pt-BR.json'
            },
            order: [],
            pageLength: 25
        });
    }

    // ---- Tabela-resumo por consultora ----

    function calcularResumoPorConsultora(detalhe) {
        var porConsultora = {};
        detalhe.forEach(function (linha) {
            var nome = linha.FirstName || '(não informado)';
            porConsultora[nome] = porConsultora[nome] || [];
            porConsultora[nome].push(linha);
        });

        return Object.keys(porConsultora).sort().map(function (nome) {
            var subset = porConsultora[nome];
            var geral = calcularResumoDeStatus(subset);
            var melhores = calcularResumoMelhores(subset);
            return {
                Consultora: nome,
                QtdTotal: geral.qtd_total, ValorTotal: geral.valor_total,
                QtdProspectados: geral.qtd_prospectados, ValorProspectados: geral.valor_prospectados,
                QtdPendenteComReq: geral.qtd_pendente_com_req, ValorPendenteComReq: geral.valor_pendente_com_req,
                QtdPendenteSemReq: geral.qtd_pendente_sem_req, ValorPendenteSemReq: geral.valor_pendente_sem_req,
                QtdMelhores: melhores.qtd_total, ValorMelhores: melhores.valor_total,
                QtdMelhoresProspectados: melhores.qtd_prospectados, ValorMelhoresProspectados: melhores.valor_prospectados,
                QtdMelhoresPendenteComReq: melhores.qtd_pendente_com_req, ValorMelhoresPendenteComReq: melhores.valor_pendente_com_req,
                QtdMelhoresPendenteSemReq: melhores.qtd_pendente_sem_req, ValorMelhoresPendenteSemReq: melhores.valor_pendente_sem_req
            };
        });
    }

    var COLUNAS_CONSULTORA_GERAL = [
        { data: 'Consultora', title: 'Consultora' },
        { data: 'QtdTotal', title: 'Qtd. Total' },
        { data: 'ValorTotal', title: 'Valor Total', render: renderMoeda },
        { data: 'QtdProspectados', title: 'Qtd. Prospectados' },
        { data: 'ValorProspectados', title: 'Valor Prospectados', render: renderMoeda },
        { data: 'QtdPendenteComReq', title: 'Qtd. Pendente c/ Req' },
        { data: 'ValorPendenteComReq', title: 'Valor Pendente c/ Req', render: renderMoeda },
        { data: 'QtdPendenteSemReq', title: 'Qtd. Pendente s/ Req' },
        { data: 'ValorPendenteSemReq', title: 'Valor Pendente s/ Req', render: renderMoeda }
    ];

    var COLUNAS_CONSULTORA_MELHORES = [
        { data: 'Consultora', title: 'Consultora' },
        { data: 'QtdMelhores', title: 'Qtd. Melhores' },
        { data: 'ValorMelhores', title: 'Valor Melhores', render: renderMoeda },
        { data: 'QtdMelhoresProspectados', title: 'Qtd. Melhores Prospectados' },
        { data: 'ValorMelhoresProspectados', title: 'Valor Melhores Prospectados', render: renderMoeda },
        { data: 'QtdMelhoresPendenteComReq', title: 'Qtd. Melhores Pend. c/ Req' },
        { data: 'ValorMelhoresPendenteComReq', title: 'Valor Melhores Pend. c/ Req', render: renderMoeda },
        { data: 'QtdMelhoresPendenteSemReq', title: 'Qtd. Melhores Pend. s/ Req' },
        { data: 'ValorMelhoresPendenteSemReq', title: 'Valor Melhores Pend. s/ Req', render: renderMoeda }
    ];

    function atualizarTabelaConsultora(containerId, linhas, colunas) {
        if (tabelasConsultora[containerId]) {
            tabelasConsultora[containerId].destroy();
            $('#' + containerId).empty().append('<thead></thead><tbody></tbody>');
        }

        var cabecalho = '<tr>' + colunas.map(function (c) { return '<th>' + c.title + '</th>'; }).join('') + '</tr>';
        $('#' + containerId + ' thead').html(cabecalho);

        tabelasConsultora[containerId] = $('#' + containerId).DataTable({
            data: linhas,
            columns: colunas,
            language: {
                url: 'https://cdn.datatables.net/plug-ins/2.1.8/i18n/pt-BR.json'
            },
            order: [],
            pageLength: 25,
            scrollX: true
        });
    }

    // ---- Gráficos genéricos ----

    // Formata o rótulo/tooltip padrão dos gráficos: valor em R$ + quantidade
    // de precatórios (lida do campo `qtd` anexado a cada ponto de dado).
    function tooltipValorEQtd() {
        return formatarMoeda(this.value) + '\n' + formatarInteiro(this.getData('qtd')) + ' precatórios';
    }

    function labelNomeEQtd() {
        return this.x + ' (' + formatarInteiro(this.getData('qtd')) + ')';
    }

    function desenharGraficoPizza(dados, containerId, titulo) {
        disposeChart(containerId);
        var chart = anychart.pie(dados);
        chart.title(titulo);
        chart.labels().format(labelNomeEQtd);
        chart.tooltip().format(tooltipValorEQtd);
        chart.container(containerId);
        chart.draw();
        charts[containerId] = chart;
        return chart;
    }

    // Gera um gráfico de barras horizontais agregando `campoValor`/`campoQtd`
    // por StatusPrec (ou por consultora, quando agrupado).
    function desenharGraficoPorStatus(detalhe, agrupadoPorConsultora, campoValor, campoQtd, tituloBase, containerId) {
        disposeChart(containerId);

        var agregados = {};
        var chave = agrupadoPorConsultora ? 'FirstName' : 'StatusPrec';

        detalhe.forEach(function (linha) {
            var rotulo = linha[chave] || '(não informado)';
            if (!agregados[rotulo]) {
                agregados[rotulo] = { valor: 0, qtd: 0 };
            }
            agregados[rotulo].valor += Number(linha[campoValor]) || 0;
            agregados[rotulo].qtd += Number(linha[campoQtd]) || 0;
        });

        var dados = Object.keys(agregados).map(function (rotulo) {
            return { x: rotulo, value: agregados[rotulo].valor, qtd: agregados[rotulo].qtd };
        });

        // anychart.bar() = barras horizontais. No AnyChart, xAxis()/yAxis()
        // seguem o papel do dado (categoria/valor), não a posição visual —
        // o eixo de valores continua sendo yAxis() mesmo com o gráfico deitado.
        var chart = anychart.bar(dados);
        chart.title(tituloBase + (agrupadoPorConsultora ? ' por Consultora' : ' por Status'));
        chart.yAxis().labels().format(function () {
            return formatarMoeda(this.value);
        });
        chart.tooltip().format(tooltipValorEQtd);
        chart.labels().enabled(true).format(function () {
            return formatarInteiro(this.getData('qtd'));
        });
        chart.container(containerId);
        chart.draw();
        charts[containerId] = chart;
        return chart;
    }

    // Barras empilhadas: uma barra por consultora, segmentada por StatusPrec.
    function desenharGraficoEmpilhado(detalhe, campoValor, tituloBase, containerId) {
        disposeChart(containerId);

        var consultoras = [];
        var statusList = [];
        var valores = {};

        detalhe.forEach(function (linha) {
            var consultora = linha.FirstName || '(não informado)';
            var status = linha.StatusPrec;
            if (consultoras.indexOf(consultora) === -1) {
                consultoras.push(consultora);
            }
            if (statusList.indexOf(status) === -1) {
                statusList.push(status);
            }
            valores[consultora] = valores[consultora] || {};
            valores[consultora][status] = (valores[consultora][status] || 0) + (Number(linha[campoValor]) || 0);
        });
        consultoras.sort();

        var chart = anychart.bar();
        chart.yScale().stackMode('value');

        statusList.forEach(function (status) {
            var serieDados = consultoras.map(function (consultora) {
                return { x: consultora, value: (valores[consultora] && valores[consultora][status]) || 0 };
            });
            chart.bar(serieDados).name(status);
        });

        chart.title(tituloBase + ' por Consultora e Status');
        chart.legend().enabled(true);
        chart.legend().position('bottom');
        chart.yAxis().labels().format(function () {
            return formatarMoeda(this.value);
        });
        chart.tooltip().format(function () {
            return this.seriesName + ': ' + formatarMoeda(this.value);
        });
        chart.container(containerId);
        chart.draw();
        charts[containerId] = chart;
        return chart;
    }

    // ---- Painel principal (sempre visível: geral + melhores negociações) ----

    function atualizarPainelPrincipal(resumo, detalhe, agrupadoPorConsultora) {
        preencherCards(resumo, 'card');
        preencherCards(calcularResumoMelhores(detalhe), 'card-melhores');

        desenharGraficoPizza([
            { x: 'Prospectados', value: resumo.valor_prospectados, qtd: resumo.qtd_prospectados },
            { x: 'Pendente c/ Requisitório', value: resumo.valor_pendente_com_req, qtd: resumo.qtd_pendente_com_req },
            { x: 'Pendente s/ Requisitório', value: resumo.valor_pendente_sem_req, qtd: resumo.qtd_pendente_sem_req }
        ], 'chart-resumo', 'Distribuição de Valor (Prospecção)');

        desenharGraficoPorStatus(detalhe, agrupadoPorConsultora, 'ValorTotal', 'QuantidadeTotal', 'Valor Total', 'chart-status');

        var comReq = 0, semReq = 0, qtdCom = 0, qtdSem = 0;
        detalhe.forEach(function (linha) {
            comReq += Number(linha.ValorMelhoresComReq) || 0;
            semReq += Number(linha.ValorMelhoresSemReq) || 0;
            qtdCom += Number(linha.QtdMelhoresComReq) || 0;
            qtdSem += Number(linha.QtdMelhoresSemReq) || 0;
        });
        desenharGraficoPizza([
            { x: 'Melhores c/ Requisitório', value: comReq, qtd: qtdCom },
            { x: 'Melhores s/ Requisitório', value: semReq, qtd: qtdSem }
        ], 'chart-melhores-resumo', 'Distribuição de Valor (Melhores Negociações)');

        desenharGraficoPorStatus(detalhe, agrupadoPorConsultora, 'ValorMelhores', 'QtdMelhores', 'Valor Melhores', 'chart-melhores-status');
    }

    // ---- Visão Geral por Consultora ----

    function atualizarSecaoConsultoraGeral() {
        var linhas = calcularResumoPorConsultora(ultimoDetalhe);
        atualizarTabelaConsultora('tabela-consultora-resumo', linhas, COLUNAS_CONSULTORA_GERAL);
        atualizarTabelaConsultora('tabela-consultora-resumo-melhores', linhas, COLUNAS_CONSULTORA_MELHORES);
        desenharGraficoEmpilhado(ultimoDetalhe, 'ValorTotal', 'Valor Total', 'chart-consultora-empilhado');
        desenharGraficoEmpilhado(ultimoDetalhe, 'ValorMelhores', 'Valor Melhores', 'chart-consultora-empilhado-melhores');
    }

    // ---- Detalhe por Consultora (uma pessoa por vez) ----

    function popularSelectConsultora(detalhe) {
        var nomes = Array.from(new Set(detalhe.map(function (l) { return l.FirstName; }).filter(Boolean))).sort();
        var select = $('#select-consultora');
        var atual = select.val();
        select.empty();
        nomes.forEach(function (nome) {
            select.append($('<option></option>').val(nome).text(nome));
        });
        if (nomes.indexOf(atual) !== -1) {
            select.val(atual);
        }
    }

    function atualizarSecaoConsultoraDetalhe() {
        var nome = $('#select-consultora').val();
        var subset = ultimoDetalhe.filter(function (linha) { return linha.FirstName === nome; });

        var geral = calcularResumoDeStatus(subset);
        var melhores = calcularResumoMelhores(subset);

        preencherCards(geral, 'card-consultora');
        preencherCards(melhores, 'card-consultora-melhores');

        desenharGraficoPizza([
            { x: 'Prospectados', value: geral.valor_prospectados, qtd: geral.qtd_prospectados },
            { x: 'Pendente c/ Requisitório', value: geral.valor_pendente_com_req, qtd: geral.qtd_pendente_com_req },
            { x: 'Pendente s/ Requisitório', value: geral.valor_pendente_sem_req, qtd: geral.qtd_pendente_sem_req }
        ], 'chart-consultora-resumo', 'Distribuição de Valor (Prospecção)' + (nome ? ' — ' + nome : ''));
        desenharGraficoPorStatus(subset, false, 'ValorTotal', 'QuantidadeTotal', 'Valor Total', 'chart-consultora-status');

        var comReq = 0, semReq = 0, qtdCom = 0, qtdSem = 0;
        subset.forEach(function (linha) {
            comReq += Number(linha.ValorMelhoresComReq) || 0;
            semReq += Number(linha.ValorMelhoresSemReq) || 0;
            qtdCom += Number(linha.QtdMelhoresComReq) || 0;
            qtdSem += Number(linha.QtdMelhoresSemReq) || 0;
        });
        desenharGraficoPizza([
            { x: 'Melhores c/ Requisitório', value: comReq, qtd: qtdCom },
            { x: 'Melhores s/ Requisitório', value: semReq, qtd: qtdSem }
        ], 'chart-consultora-melhores-resumo', 'Distribuição de Valor (Melhores Negociações)' + (nome ? ' — ' + nome : ''));
        desenharGraficoPorStatus(subset, false, 'ValorMelhores', 'QtdMelhores', 'Valor Melhores', 'chart-consultora-melhores-status');
    }

    // ---- Alternância de modo/visibilidade ----

    function modoConsultoraAtual() {
        return $('input[name="modo_consultora"]:checked').val() || 'geral';
    }

    function atualizarVisibilidade(agrupadoPorConsultora) {
        $('#opcoes-consultora').toggleClass('d-none', !agrupadoPorConsultora);

        var modo = modoConsultoraAtual();
        var mostrarGeral = agrupadoPorConsultora && modo === 'geral';
        var mostrarDetalhe = agrupadoPorConsultora && modo === 'detalhe';

        // No modo Detalhe por Consultora, os cards e gráficos gerais (não
        // filtrados por pessoa) somem por completo — só a seção dedicada,
        // já escopada para a consultora escolhida, aparece.
        $('#cards-resumo, #linha-graficos-geral, #cards-melhores, #linha-graficos-melhores')
            .toggleClass('d-none', mostrarDetalhe);

        // Dentro da linha de gráficos, a pizza (não agrupado) e o empilhado
        // por status (agrupado + Visão Geral) revezam o mesmo espaço, ao
        // lado da barra por consultora/status que já ocupava a outra metade.
        $('#chart-resumo, #chart-melhores-resumo').toggleClass('d-none', agrupadoPorConsultora);
        $('#chart-consultora-empilhado, #chart-consultora-empilhado-melhores').toggleClass('d-none', !mostrarGeral);

        $('#linha-tabela-consultora-geral, #linha-tabela-consultora-melhores').toggleClass('d-none', !mostrarGeral);
        $('#secao-consultora-detalhe, #secao-consultora-detalhe-melhores').toggleClass('d-none', !mostrarDetalhe);
        $('#select-consultora-wrapper').toggleClass('d-none', !mostrarDetalhe);
    }

    function renderizarModoConsultora() {
        if (!ultimoAgrupado) {
            return;
        }
        atualizarVisibilidade(true);
        if (modoConsultoraAtual() === 'geral') {
            atualizarSecaoConsultoraGeral();
        } else {
            atualizarSecaoConsultoraDetalhe();
        }
    }

    // ---- Carregamento principal ----

    function carregarDados() {
        limparErro();
        var filtros = coletarFiltros();

        $.getJSON('api/prospeccao.php', filtros)
            .done(function (resposta) {
                if (!resposta.ok) {
                    mostrarErro(resposta.erro || 'Não foi possível carregar os dados.');
                    return;
                }

                ultimoDetalhe = resposta.detalhe;
                ultimoResumo = resposta.resumo;
                ultimoAgrupado = resposta.agrupado_por_consultora;

                atualizarPainelPrincipal(ultimoResumo, ultimoDetalhe, ultimoAgrupado);
                atualizarTabela(ultimoDetalhe, ultimoAgrupado);
                atualizarVisibilidade(ultimoAgrupado);

                if (ultimoAgrupado) {
                    popularSelectConsultora(ultimoDetalhe);
                }
                renderizarModoConsultora();
            })
            .fail(function (xhr) {
                var mensagem = 'Não foi possível carregar os dados.';
                if (xhr.responseJSON && xhr.responseJSON.erro) {
                    mensagem = xhr.responseJSON.erro;
                }
                mostrarErro(mensagem);
            });
    }

    $(function () {
        $('#form-prospeccao').on('submit', function (e) {
            e.preventDefault();
            carregarDados();
        });

        $('input[name="modo_consultora"]').on('change', function () {
            renderizarModoConsultora();
        });

        $('#select-consultora').on('change', function () {
            atualizarSecaoConsultoraDetalhe();
        });
    });
})(jQuery);
