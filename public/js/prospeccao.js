(function ($) {
    'use strict';

    var moedaFormatter = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' });
    var inteiroFormatter = new Intl.NumberFormat('pt-BR');
    var STATUS_ID_PENDENTE = 65;

    var tabela = null;
    var chartResumo = null;
    var chartStatus = null;
    var chartMelhoresResumo = null;
    var chartMelhoresStatus = null;

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

    function atualizarCards(resumo) {
        $('#card-total-qtd').text(formatarInteiro(resumo.qtd_total) + ' precatórios');
        $('#card-total-valor').text(formatarMoeda(resumo.valor_total));

        $('#card-prospectados-qtd').text(formatarInteiro(resumo.qtd_prospectados) + ' precatórios');
        $('#card-prospectados-valor').text(formatarMoeda(resumo.valor_prospectados));

        $('#card-pendente-com-qtd').text(formatarInteiro(resumo.qtd_pendente_com_req) + ' precatórios');
        $('#card-pendente-com-valor').text(formatarMoeda(resumo.valor_pendente_com_req));

        $('#card-pendente-sem-qtd').text(formatarInteiro(resumo.qtd_pendente_sem_req) + ' precatórios');
        $('#card-pendente-sem-valor').text(formatarMoeda(resumo.valor_pendente_sem_req));
    }

    // Espelha atualizarCards(), mas usando os campos "Melhores" (já filtrados
    // por previsão de pagamento e valor mínimo) agregados a partir do detalhe.
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

    function atualizarCardsMelhores(detalhe) {
        var resumo = calcularResumoMelhores(detalhe);

        $('#card-melhores-total-qtd').text(formatarInteiro(resumo.qtd_total) + ' precatórios');
        $('#card-melhores-total-valor').text(formatarMoeda(resumo.valor_total));

        $('#card-melhores-prospectados-qtd').text(formatarInteiro(resumo.qtd_prospectados) + ' precatórios');
        $('#card-melhores-prospectados-valor').text(formatarMoeda(resumo.valor_prospectados));

        $('#card-melhores-pendente-com-qtd').text(formatarInteiro(resumo.qtd_pendente_com_req) + ' precatórios');
        $('#card-melhores-pendente-com-valor').text(formatarMoeda(resumo.valor_pendente_com_req));

        $('#card-melhores-pendente-sem-qtd').text(formatarInteiro(resumo.qtd_pendente_sem_req) + ' precatórios');
        $('#card-melhores-pendente-sem-valor').text(formatarMoeda(resumo.valor_pendente_sem_req));
    }

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

    // Formata o rótulo/tooltip padrão dos gráficos: valor em R$ + quantidade
    // de precatórios (lida do campo `qtd` anexado a cada ponto de dado).
    function tooltipValorEQtd() {
        return formatarMoeda(this.value) + '\n' + formatarInteiro(this.getData('qtd')) + ' precatórios';
    }

    function labelNomeEQtd() {
        return this.x + ' (' + formatarInteiro(this.getData('qtd')) + ')';
    }

    function atualizarGraficoResumo(resumo) {
        if (chartResumo) {
            chartResumo.dispose();
        }
        var dados = [
            { x: 'Prospectados', value: resumo.valor_prospectados, qtd: resumo.qtd_prospectados },
            { x: 'Pendente c/ Requisitório', value: resumo.valor_pendente_com_req, qtd: resumo.qtd_pendente_com_req },
            { x: 'Pendente s/ Requisitório', value: resumo.valor_pendente_sem_req, qtd: resumo.qtd_pendente_sem_req }
        ];
        chartResumo = anychart.pie(dados);
        chartResumo.title('Distribuição de Valor (Prospecção)');
        chartResumo.labels().format(labelNomeEQtd);
        chartResumo.tooltip().format(tooltipValorEQtd);
        chartResumo.container('chart-resumo');
        chartResumo.draw();
    }

    // Gera um gráfico de colunas agregando `campoValor`/`campoQtd` por
    // StatusPrec (ou por consultora, quando agrupado). Reaproveitado tanto
    // para o valor total quanto para o valor das "melhores negociações".
    function desenharGraficoPorStatus(detalhe, agrupadoPorConsultora, campoValor, campoQtd, tituloBase, containerId) {
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

        // anychart.bar() = barras horizontais: categorias (status/consultora)
        // nas linhas (eixo Y), valores nas colunas (eixo X).
        var chart = anychart.bar(dados);
        chart.title(tituloBase + (agrupadoPorConsultora ? ' por Consultora' : ' por Status'));
        chart.xAxis().labels().format(function () {
            return formatarMoeda(this.value);
        });
        chart.tooltip().format(tooltipValorEQtd);
        chart.labels().enabled(true).format(function () {
            return formatarInteiro(this.getData('qtd'));
        });
        chart.container(containerId);
        chart.draw();
        return chart;
    }

    function atualizarGraficoStatus(detalhe, agrupadoPorConsultora) {
        if (chartStatus) {
            chartStatus.dispose();
        }
        chartStatus = desenharGraficoPorStatus(detalhe, agrupadoPorConsultora, 'ValorTotal', 'QuantidadeTotal', 'Valor Total', 'chart-status');
    }

    function atualizarGraficoMelhoresResumo(detalhe) {
        if (chartMelhoresResumo) {
            chartMelhoresResumo.dispose();
        }

        var valorComReq = 0;
        var valorSemReq = 0;
        var qtdComReq = 0;
        var qtdSemReq = 0;
        detalhe.forEach(function (linha) {
            valorComReq += Number(linha.ValorMelhoresComReq) || 0;
            valorSemReq += Number(linha.ValorMelhoresSemReq) || 0;
            qtdComReq += Number(linha.QtdMelhoresComReq) || 0;
            qtdSemReq += Number(linha.QtdMelhoresSemReq) || 0;
        });

        var dados = [
            { x: 'Melhores c/ Requisitório', value: valorComReq, qtd: qtdComReq },
            { x: 'Melhores s/ Requisitório', value: valorSemReq, qtd: qtdSemReq }
        ];
        chartMelhoresResumo = anychart.pie(dados);
        chartMelhoresResumo.title('Distribuição de Valor (Melhores Negociações)');
        chartMelhoresResumo.labels().format(labelNomeEQtd);
        chartMelhoresResumo.tooltip().format(tooltipValorEQtd);
        chartMelhoresResumo.container('chart-melhores-resumo');
        chartMelhoresResumo.draw();
    }

    function atualizarGraficoMelhoresStatus(detalhe, agrupadoPorConsultora) {
        if (chartMelhoresStatus) {
            chartMelhoresStatus.dispose();
        }
        chartMelhoresStatus = desenharGraficoPorStatus(detalhe, agrupadoPorConsultora, 'ValorMelhores', 'QtdMelhores', 'Valor Melhores', 'chart-melhores-status');
    }

    function carregarDados() {
        limparErro();
        var filtros = coletarFiltros();

        $.getJSON('api/prospeccao.php', filtros)
            .done(function (resposta) {
                if (!resposta.ok) {
                    mostrarErro(resposta.erro || 'Não foi possível carregar os dados.');
                    return;
                }
                atualizarCards(resposta.resumo);
                atualizarCardsMelhores(resposta.detalhe);
                atualizarTabela(resposta.detalhe, resposta.agrupado_por_consultora);
                atualizarGraficoResumo(resposta.resumo);
                atualizarGraficoStatus(resposta.detalhe, resposta.agrupado_por_consultora);
                atualizarGraficoMelhoresResumo(resposta.detalhe);
                atualizarGraficoMelhoresStatus(resposta.detalhe, resposta.agrupado_por_consultora);
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
    });
})(jQuery);
