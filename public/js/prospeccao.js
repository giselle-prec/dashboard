(function ($) {
    'use strict';

    var moedaFormatter = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' });
    var inteiroFormatter = new Intl.NumberFormat('pt-BR');

    var tabela = null;
    var chartResumo = null;
    var chartStatus = null;

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

    function atualizarGraficoResumo(resumo) {
        if (chartResumo) {
            chartResumo.dispose();
        }
        var dados = [
            { x: 'Prospectados', value: resumo.valor_prospectados },
            { x: 'Pendente c/ Requisitório', value: resumo.valor_pendente_com_req },
            { x: 'Pendente s/ Requisitório', value: resumo.valor_pendente_sem_req }
        ];
        chartResumo = anychart.pie(dados);
        chartResumo.title('Distribuição de Valor (Prospecção)');
        chartResumo.tooltip().format(function () {
            return formatarMoeda(this.value);
        });
        chartResumo.container('chart-resumo');
        chartResumo.draw();
    }

    function atualizarGraficoStatus(detalhe, agrupadoPorConsultora) {
        if (chartStatus) {
            chartStatus.dispose();
        }

        var agregados = {};
        var chave = agrupadoPorConsultora ? 'FirstName' : 'StatusPrec';

        detalhe.forEach(function (linha) {
            var rotulo = linha[chave] || '(não informado)';
            if (!agregados[rotulo]) {
                agregados[rotulo] = 0;
            }
            agregados[rotulo] += Number(linha.ValorTotal) || 0;
        });

        var dados = Object.keys(agregados).map(function (rotulo) {
            return { x: rotulo, value: agregados[rotulo] };
        });

        chartStatus = anychart.column(dados);
        chartStatus.title(agrupadoPorConsultora ? 'Valor Total por Consultora' : 'Valor Total por Status');
        chartStatus.yAxis().labels().format(function () {
            return formatarMoeda(this.value);
        });
        chartStatus.tooltip().format(function () {
            return formatarMoeda(this.value);
        });
        chartStatus.container('chart-status');
        chartStatus.draw();
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
                atualizarTabela(resposta.detalhe, resposta.agrupado_por_consultora);
                atualizarGraficoResumo(resposta.resumo);
                atualizarGraficoStatus(resposta.detalhe, resposta.agrupado_por_consultora);
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
