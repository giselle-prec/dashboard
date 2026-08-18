(function ($) {
    'use strict';

    var moedaFormatter = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' });
    var inteiroFormatter = new Intl.NumberFormat('pt-BR');

    var TOP_ENTES = 15;

    // Última resposta de cada aba, para trocar métrica/agrupamento sem nova requisição.
    var ultimoPeriodo = null;
    var ultimaFoto = null;

    var charts = {};
    var tabelaEventos = null;
    var tabelaFoto = null;

    function formatarMoeda(valor) {
        return moedaFormatter.format(Number(valor) || 0);
    }

    function formatarInteiro(valor) {
        return inteiroFormatter.format(Number(valor) || 0);
    }

    function formatarData(valor) {
        if (!valor) {
            return '';
        }
        var partes = String(valor).substring(0, 10).split('-');
        if (partes.length !== 3) {
            return valor;
        }
        return partes[2] + '/' + partes[1] + '/' + partes[0];
    }

    function mostrarErro(mensagem) {
        $('#alerta-erro').removeClass('d-none').text(mensagem);
    }

    function limparErro() {
        $('#alerta-erro').addClass('d-none').text('');
    }

    function erroDaResposta(xhr) {
        if (xhr.responseJSON && xhr.responseJSON.erro) {
            return xhr.responseJSON.erro;
        }
        return 'Não foi possível carregar os dados.';
    }

    // Filtros compartilhados pelas duas abas. Campo vazio = "todos".
    function coletarFiltrosComuns() {
        var filtros = {
            ente_id: $('#ente_id').val() || [],
            orcamento: $('#orcamento').val() || [],
            consultor_id: $('#consultor_id').val() || [],
            valor_min: $('#valor_min').val(),
            valor_max: $('#valor_max').val(),
            previsao_max: $('#previsao_max').val()
        };
        if ($('#excluir_status_contrato').is(':checked')) {
            filtros.excluir_status_contrato = 1;
        }
        return filtros;
    }

    function metricaPeriodo() {
        return $('#metrica').val() === 'valor' ? 'valor' : 'qtd';
    }

    function rotuloMetrica(metrica) {
        return metrica === 'valor' ? 'Valor de face' : 'Quantidade';
    }

    function formatarMetrica(valor, metrica) {
        return metrica === 'valor' ? formatarMoeda(valor) : formatarInteiro(valor);
    }

    function desenhar(id, chart) {
        if (charts[id]) {
            charts[id].dispose();
        }
        charts[id] = chart;
        chart.container(id);
        chart.draw();
    }

    // ------------------------------------------------------------------
    // Agrupamento temporal (feito no cliente sobre a série diária)
    // ------------------------------------------------------------------

    function segundaFeiraDa(dataIso) {
        var d = new Date(dataIso + 'T00:00:00');
        var diaSemana = (d.getDay() + 6) % 7; // 0 = segunda
        d.setDate(d.getDate() - diaSemana);
        var mes = ('0' + (d.getMonth() + 1)).slice(-2);
        var dia = ('0' + d.getDate()).slice(-2);
        return d.getFullYear() + '-' + mes + '-' + dia;
    }

    function chaveTemporal(dataIso, granularidade) {
        if (granularidade === 'mes') {
            return dataIso.substring(0, 7);
        }
        if (granularidade === 'semana') {
            return segundaFeiraDa(dataIso);
        }
        return dataIso;
    }

    function rotuloTemporal(chave, granularidade) {
        if (granularidade === 'mes') {
            var partes = chave.split('-');
            return partes[1] + '/' + partes[0];
        }
        if (granularidade === 'semana') {
            return 'Semana de ' + formatarData(chave);
        }
        return formatarData(chave);
    }

    function agruparPorDia(porDia, granularidade) {
        var baldes = {};
        porDia.forEach(function (linha) {
            var chave = chaveTemporal(linha.rotulo, granularidade);
            if (!baldes[chave]) {
                baldes[chave] = { chave: chave, qtd: 0, valor: 0 };
            }
            baldes[chave].qtd += Number(linha.qtd) || 0;
            baldes[chave].valor += Number(linha.valor) || 0;
        });

        return Object.keys(baldes).sort().map(function (chave) {
            return baldes[chave];
        });
    }

    // ------------------------------------------------------------------
    // Aba 1 — oxigenação por período
    // ------------------------------------------------------------------

    function atualizarKpis(resposta) {
        var kpis = resposta.kpis;
        var base = resposta.base_sem_tentativa;

        $('#kpi-qtd').text(formatarInteiro(kpis.qtd) + ' precatórios');
        $('#kpi-valor').text(formatarMoeda(kpis.valor));

        $('#kpi-base-qtd').text(formatarInteiro(base.qtd) + ' precatórios');
        $('#kpi-base-valor').text(formatarMoeda(base.valor));

        $('#kpi-entes').text(formatarInteiro(kpis.entes_distintos) + ' entes');
        $('#kpi-ticket').text(formatarMoeda(kpis.ticket_medio));
    }

    function graficoTempo(porDia, granularidade, metrica) {
        var series = agruparPorDia(porDia, granularidade).map(function (balde) {
            return { x: rotuloTemporal(balde.chave, granularidade), value: balde[metrica] };
        });

        var chart = anychart.column(series);
        chart.title('Oxigenação no período — ' + rotuloMetrica(metrica));
        chart.yAxis().labels().format(function () {
            return formatarMetrica(this.value, metrica);
        });
        chart.tooltip().format(function () {
            return formatarMetrica(this.value, metrica);
        });
        chart.xAxis().labels().rotation(-45);
        desenhar('chart-tempo', chart);
    }

    function graficoBarras(id, dados, titulo, metrica, limite) {
        var lista = dados.slice();
        lista.sort(function (a, b) {
            return (Number(b[metrica]) || 0) - (Number(a[metrica]) || 0);
        });
        if (limite) {
            lista = lista.slice(0, limite);
        }

        var series = lista.map(function (linha) {
            return { x: linha.rotulo, value: linha[metrica] };
        });

        var chart = anychart.bar(series);
        chart.title(titulo + ' — ' + rotuloMetrica(metrica));
        chart.xAxis().labels().format(function () {
            return this.value;
        });
        chart.tooltip().format(function () {
            return formatarMetrica(this.value, metrica);
        });
        desenhar(id, chart);
    }

    function graficoStatusDestino(dados, metrica) {
        var series = dados.map(function (linha) {
            return { x: linha.rotulo, value: linha[metrica] };
        });

        var chart = anychart.pie(series);
        chart.title('Status de destino da oxigenação — ' + rotuloMetrica(metrica));
        chart.tooltip().format(function () {
            return formatarMetrica(this.value, metrica);
        });
        desenhar('chart-status-destino', chart);
    }

    function colunasEventos() {
        return [
            { data: 'Precatorio', title: 'Precatório' },
            { data: 'Processo', title: 'Processo' },
            { data: 'Ente', title: 'Ente' },
            { data: 'Orcamento', title: 'Orçamento' },
            { data: 'Consultor', title: 'Consultor' },
            {
                data: 'DataOxigenacao',
                title: 'Data da oxigenação',
                render: function (data, type) {
                    return type === 'display' ? formatarData(data) : data;
                }
            },
            { data: 'StatusDestino', title: 'Status de destino' },
            {
                data: 'ValorPrec',
                title: 'Valor de face',
                render: function (data, type) {
                    return type === 'display' ? formatarMoeda(data) : data;
                }
            },
            {
                data: 'Datarecebimento',
                title: 'Previsão de pagamento',
                render: function (data, type) {
                    return type === 'display' ? formatarData(data) : data;
                }
            },
            { data: 'StatusPrec', title: 'Status atual' }
        ];
    }

    function montarTabela(seletor, tabelaAtual, dados, colunas) {
        if (tabelaAtual) {
            tabelaAtual.destroy();
            $(seletor).empty().append('<thead></thead><tbody></tbody>');
        }

        var cabecalho = '<tr>' + colunas.map(function (c) { return '<th>' + c.title + '</th>'; }).join('') + '</tr>';
        $(seletor + ' thead').html(cabecalho);

        return $(seletor).DataTable({
            data: dados,
            columns: colunas,
            language: {
                url: 'https://cdn.datatables.net/plug-ins/2.1.8/i18n/pt-BR.json'
            },
            order: [],
            pageLength: 25
        });
    }

    function renderizarPeriodo() {
        if (!ultimoPeriodo) {
            return;
        }
        var metrica = metricaPeriodo();
        var granularidade = $('#granularidade').val();

        atualizarKpis(ultimoPeriodo);
        graficoTempo(ultimoPeriodo.por_dia, granularidade, metrica);
        graficoBarras('chart-ente', ultimoPeriodo.por_ente, 'Top ' + TOP_ENTES + ' entes', metrica, TOP_ENTES);
        graficoBarras('chart-consultor', ultimoPeriodo.por_consultor, 'Por consultor', metrica);
        graficoStatusDestino(ultimoPeriodo.por_status_destino, metrica);
    }

    function carregarPeriodo() {
        limparErro();
        var filtros = coletarFiltrosComuns();
        filtros.acao = 'oxigenacao';
        filtros.data_inicio = $('#data_inicio').val();
        filtros.data_fim = $('#data_fim').val();

        $.getJSON('api/oxigenacao.php', filtros)
            .done(function (resposta) {
                if (!resposta.ok) {
                    mostrarErro(resposta.erro || 'Não foi possível carregar os dados.');
                    return;
                }
                ultimoPeriodo = resposta;
                renderizarPeriodo();

                tabelaEventos = montarTabela('#tabela-eventos', tabelaEventos, resposta.eventos, colunasEventos());

                if (resposta.eventos_truncados) {
                    $('#aviso-truncado').removeClass('d-none').text(
                        'A tabela mostra apenas os primeiros ' + formatarInteiro(resposta.limite_detalhe) +
                        ' precatórios. Os gráficos e os totais consideram o período inteiro.'
                    );
                } else {
                    $('#aviso-truncado').addClass('d-none').text('');
                }
            })
            .fail(function (xhr) {
                mostrarErro(erroDaResposta(xhr));
            });
    }

    // ------------------------------------------------------------------
    // Aba 2 — foto por data
    // ------------------------------------------------------------------

    function nomeStatus(resposta, statusId) {
        var mapa = resposta.status_mapa || {};
        return mapa[statusId] || ('Status #' + statusId);
    }

    // Agrega os status filhos no status pai, quando pedido. O agrupamento é
    // feito pelo nome do pai, para que "Sem Tentativa" sem contato e o status
    // filho de mesmo nome caiam na mesma linha.
    function linhasFoto(resposta) {
        if (!$('#agrupar_pai').is(':checked')) {
            return resposta.linhas;
        }

        var baldes = {};
        resposta.linhas.forEach(function (linha) {
            var rotulo = linha.ParentId === null ? linha.Status : nomeStatus(resposta, linha.ParentId);
            if (!baldes[rotulo]) {
                baldes[rotulo] = {
                    StatusId: linha.ParentId === null ? linha.StatusId : linha.ParentId,
                    Status: rotulo,
                    ParentId: null,
                    Qtd: 0,
                    Valor: 0
                };
            }
            baldes[rotulo].Qtd += linha.Qtd;
            baldes[rotulo].Valor += linha.Valor;
        });

        return Object.keys(baldes).map(function (rotulo) {
            return baldes[rotulo];
        }).sort(function (a, b) {
            return b.Qtd - a.Qtd;
        });
    }

    function renderizarFoto() {
        if (!ultimaFoto) {
            return;
        }
        var metrica = $('#metrica_foto').val() === 'valor' ? 'valor' : 'qtd';
        var campo = metrica === 'valor' ? 'Valor' : 'Qtd';
        var linhas = linhasFoto(ultimaFoto);

        $('#foto-total-qtd').text(formatarInteiro(ultimaFoto.totais.qtd) + ' precatórios');
        $('#foto-total-valor').text(formatarMoeda(ultimaFoto.totais.valor));

        var series = linhas.slice().sort(function (a, b) {
            return b[campo] - a[campo];
        }).map(function (linha) {
            return { x: linha.Status, value: linha[campo] };
        });

        var chart = anychart.bar(series);
        chart.title('Precatórios por status em ' + formatarData(ultimaFoto.data_ref) + ' — ' + rotuloMetrica(metrica));
        chart.tooltip().format(function () {
            return formatarMetrica(this.value, metrica);
        });
        desenhar('chart-foto', chart);

        var colunas = [
            { data: 'Status', title: 'Status' },
            {
                data: 'Qtd',
                title: 'Quantidade',
                render: function (data, type) {
                    return type === 'display' ? formatarInteiro(data) : data;
                }
            },
            {
                data: 'Valor',
                title: 'Valor de face',
                render: function (data, type) {
                    return type === 'display' ? formatarMoeda(data) : data;
                }
            }
        ];
        tabelaFoto = montarTabela('#tabela-foto', tabelaFoto, linhas, colunas);
    }

    function carregarFoto() {
        limparErro();
        var filtros = coletarFiltrosComuns();
        filtros.acao = 'foto';
        filtros.data_ref = $('#data_ref').val();
        if ($('#somente_pendentes').is(':checked')) {
            filtros.somente_pendentes = 1;
        }

        $.getJSON('api/oxigenacao.php', filtros)
            .done(function (resposta) {
                if (!resposta.ok) {
                    mostrarErro(resposta.erro || 'Não foi possível carregar os dados.');
                    return;
                }
                ultimaFoto = resposta;
                renderizarFoto();
            })
            .fail(function (xhr) {
                mostrarErro(erroDaResposta(xhr));
            });
    }

    $(function () {
        $('#form-periodo').on('submit', function (e) {
            e.preventDefault();
            carregarPeriodo();
        });

        $('#form-foto').on('submit', function (e) {
            e.preventDefault();
            carregarFoto();
        });

        $('#granularidade, #metrica').on('change', renderizarPeriodo);
        $('#metrica_foto, #agrupar_pai').on('change', renderizarFoto);
    });
})(jQuery);
