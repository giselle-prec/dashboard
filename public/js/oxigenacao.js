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

    // Status escolhido na pizza de cada aba, que alimenta as quebras de baixo.
    var statusSelecionado = null;
    var statusFotoSelecionado = null;

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
            natureza_id: $('#natureza_id').val() || [],
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

    // Pizza de status com legenda à direita (ocupa a linha inteira) e clique
    // para escolher o status detalhado nos dois gráficos de baixo. Serve às duas
    // abas: cada uma passa o próprio id de contêiner e o que fazer no clique.
    function graficoPizzaStatus(id, series, titulo, metrica, aoClicar) {
        var chart = anychart.pie(series);
        chart.title(titulo);
        chart.tooltip().format(function () {
            return formatarMetrica(this.value, metrica);
        });
        chart.legend()
            .position('right')
            .itemsLayout('vertical')
            .align('center');
        chart.listen('pointClick', function (e) {
            var status = null;
            if (e.point && typeof e.point.get === 'function') {
                status = e.point.get('x');
            } else if (e.iterator && typeof e.iterator.get === 'function') {
                status = e.iterator.get('x');
            }
            if (status) {
                aoClicar(status);
            }
        });
        desenhar(id, chart);
    }

    // Detalhamento do status escolhido na pizza, em um dos dois recortes.
    function graficoDetalheStatus(id, dados, titulo, metrica, selecionado) {
        var container = $('#' + id);

        if (!selecionado) {
            if (charts[id]) {
                charts[id].dispose();
                delete charts[id];
            }
            container.empty().append(
                '<div class="text-muted d-flex align-items-center justify-content-center h-100">' +
                'Selecione um status no gráfico acima.</div>'
            );
            return;
        }

        container.empty();
        graficoBarras(id, dados || [], titulo + ' — "' + selecionado + '"', metrica, TOP_ENTES);
    }

    function graficosDoStatus(metrica) {
        var cruzEnte = ultimoPeriodo && (ultimoPeriodo.por_status_ente || {})[statusSelecionado];
        var cruzCons = ultimoPeriodo && (ultimoPeriodo.por_status_consultor || {})[statusSelecionado];
        graficoDetalheStatus('chart-status-ente', cruzEnte, 'Por ente', metrica,
            ultimoPeriodo ? statusSelecionado : null);
        graficoDetalheStatus('chart-status-consultor', cruzCons, 'Por consultor', metrica,
            ultimoPeriodo ? statusSelecionado : null);
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
        graficoPizzaStatus(
            'chart-status-destino',
            ultimoPeriodo.por_status_destino.map(function (linha) {
                return { x: linha.rotulo, value: linha[metrica] };
            }),
            'Status de destino da oxigenação — ' + rotuloMetrica(metrica),
            metrica,
            function (status) {
                // Clicar de novo no mesmo status desfaz a seleção.
                statusSelecionado = (statusSelecionado === status) ? null : status;
                graficosDoStatus(metricaPeriodo());
            }
        );

        // Um status selecionado numa busca anterior pode não existir na nova.
        if (statusSelecionado && !(ultimoPeriodo.por_status_ente || {})[statusSelecionado]) {
            statusSelecionado = null;
        }
        graficosDoStatus(metrica);
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
        var info = (resposta.status_mapa || {})[statusId];
        return (info && info.nome) || ('Status #' + statusId);
    }

    // Os cruzamentos vêm por id de status; a pizza mostra rótulos, que podem ser
    // o do próprio status ou o do pai, conforme o agrupamento escolhido. Esta
    // função junta os ids que caem sob o rótulo clicado.
    function cruzamentoDoRotulo(cruzamento, rotulo) {
        var mapa = ultimaFoto.status_mapa || {};
        var agrupando = $('#agrupar_pai').is(':checked');
        var baldes = {};

        Object.keys(cruzamento).forEach(function (statusId) {
            var info = mapa[statusId];
            var nome;
            if (!info) {
                nome = 'Status #' + statusId;
            } else if (agrupando && info.pai && mapa[info.pai]) {
                nome = mapa[info.pai].nome;
            } else {
                nome = info.nome;
            }
            if (nome !== rotulo) {
                return;
            }
            cruzamento[statusId].forEach(function (item) {
                if (!baldes[item.rotulo]) {
                    baldes[item.rotulo] = { rotulo: item.rotulo, qtd: 0, valor: 0 };
                }
                baldes[item.rotulo].qtd += item.qtd;
                baldes[item.rotulo].valor += item.valor;
            });
        });

        return Object.keys(baldes).map(function (chave) { return baldes[chave]; });
    }

    function graficosDaFoto(metrica) {
        var selecionado = ultimaFoto ? statusFotoSelecionado : null;
        graficoDetalheStatus('chart-foto-ente',
            selecionado ? cruzamentoDoRotulo(ultimaFoto.por_status_ente || {}, selecionado) : null,
            'Por ente', metrica, selecionado);
        graficoDetalheStatus('chart-foto-consultor',
            selecionado ? cruzamentoDoRotulo(ultimaFoto.por_status_consultor || {}, selecionado) : null,
            'Por consultor', metrica, selecionado);
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
                    // Agrupando por pai, os filhos de "Sem Tentativa" (Valor
                    // Baixo, Inserido pelo Robô) caem no mesmo balde e herdam
                    // o tratamento à parte.
                    SemTentativa: rotulo === 'Sem Tentativa',
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

    // A data da quitação vem de três fontes (coluna de data, histórico de lotes
    // ou nenhuma). O aviso diz quanto da foto é exato e quanto é aproximação.
    function atualizarCobertura() {
        var aviso = $('#aviso-cobertura');
        var cobertura = ultimaFoto && ultimaFoto.cobertura;

        if (!cobertura) {
            if (ultimaFoto && ultimaFoto.usa_status_atual) {
                aviso.removeClass('d-none alert-warning').addClass('alert-info').text(
                    'Na data de hoje o painel usa o status atual do precatório: é exato e inclui as mudanças ' +
                    'feitas fora do fluxo de contato.'
                );
            } else {
                aviso.addClass('d-none').empty();
            }
            return;
        }

        var exatos = Number(cobertura.quitados_data_exata) || 0;
        var porLote = Number(cobertura.quitados_data_lote) || 0;
        var semData = Number(cobertura.quitados_sem_data) || 0;
        var pendentesHoje = Number(cobertura.pendentes_hoje) || 0;
        var naData = Number(ultimaFoto.totais.qtd) || 0;

        var partes = [];
        var aproximado = semData > 0;

        if (ultimaFoto.usa_status_atual) {
            partes.push('Na data de hoje o status vem da própria tabela de precatórios: é exato e inclui as ' +
                'mudanças feitas fora do fluxo de contato.');
        }

        if (!aproximado) {
            partes.push('Número exato: todos os precatórios quitados destes filtros têm data de quitação registrada (' +
                formatarInteiro(exatos) + ' pela coluna ' + cobertura.coluna_data + ', ' +
                formatarInteiro(porLote) + ' pelo histórico de lotes).');
        } else {
            partes.push('Valor aproximado: ' + formatarInteiro(semData) + ' precatórios já quitados não têm data de ' +
                'quitação em nenhuma fonte e entram como quitados em qualquer data, então o número real de pendentes ' +
                'nesta data é maior que o mostrado.');
            partes.push('Com data: ' + formatarInteiro(exatos) + ' pela coluna ' + cobertura.coluna_data + ' e ' +
                formatarInteiro(porLote) + ' pelo histórico de lotes.');
        }

        if (pendentesHoje > 0) {
            var variacao = ((naData - pendentesHoje) / pendentesHoje) * 100;
            var sentido = variacao >= 0 ? 'a mais que' : 'a menos que';
            partes.push('Nesta data: ' + formatarInteiro(naData) + ' pendentes, ' +
                Math.abs(variacao).toFixed(1).replace('.', ',') + '% ' + sentido + ' os ' +
                formatarInteiro(pendentesHoje) + ' pendentes de hoje com os mesmos filtros.');
        }

        if (!cobertura.tem_coluna_data) {
            partes.push('A coluna ' + cobertura.coluna_data + ' ainda não existe na tabela Precatorio; ' +
                'assim que ela for preenchida nas alterações em lote, passa a ser usada automaticamente.');
        }
        if (cobertura.inicio_historico && ultimaFoto.data_ref < cobertura.inicio_historico) {
            partes.push('O histórico de lotes começa em ' + formatarData(cobertura.inicio_historico) + '.');
        }

        aviso.removeClass('d-none')
            .removeClass('alert-info alert-warning')
            .addClass(aproximado ? 'alert-warning' : 'alert-info')
            .text(partes.join(' '));
    }

    function renderizarFoto() {
        if (!ultimaFoto) {
            return;
        }
        atualizarCobertura();
        var metrica = $('#metrica_foto').val() === 'valor' ? 'valor' : 'qtd';
        var campo = metrica === 'valor' ? 'Valor' : 'Qtd';
        var linhas = linhasFoto(ultimaFoto);

        var totais = ultimaFoto.totais;
        $('#foto-total-qtd').text(formatarInteiro(totais.qtd) + ' precatórios');
        $('#foto-total-valor').text(formatarMoeda(totais.valor));
        $('#foto-sem-tentativa-qtd').text(formatarInteiro(totais.sem_tentativa_qtd) + ' precatórios');
        $('#foto-sem-tentativa-valor').text(formatarMoeda(totais.sem_tentativa_valor));
        $('#foto-outros-qtd').text(formatarInteiro(totais.outros_qtd) + ' precatórios');
        $('#foto-outros-valor').text(formatarMoeda(totais.outros_valor));

        // Sem Tentativa sai do gráfico: é quase sempre a maior fatia e achataria
        // todas as outras. O número dele está no card ao lado do total.
        var series = linhas.filter(function (linha) {
            return !linha.SemTentativa;
        }).sort(function (a, b) {
            return b[campo] - a[campo];
        }).map(function (linha) {
            return { x: linha.Status, value: linha[campo] };
        });

        graficoPizzaStatus(
            'chart-foto',
            series,
            'Status em ' + formatarData(ultimaFoto.data_ref) + ', exceto Sem Tentativa — ' + rotuloMetrica(metrica),
            metrica,
            function (status) {
                statusFotoSelecionado = (statusFotoSelecionado === status) ? null : status;
                graficosDaFoto(metrica);
            }
        );

        // Um status escolhido numa busca anterior pode não existir na nova.
        if (statusFotoSelecionado && !linhas.some(function (linha) {
            return !linha.SemTentativa && linha.Status === statusFotoSelecionado;
        })) {
            statusFotoSelecionado = null;
        }
        graficosDaFoto(metrica);

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

    // Mesmo padrão do Painel de Prospecção.
    function ligarSelect2() {
        $('.select2-multi').each(function () {
            $(this).select2({
                theme: 'bootstrap-5',
                width: '100%',
                placeholder: $(this).data('placeholder') || '',
                closeOnSelect: false
            });
        });

        $('.btn-selecionar-todos').on('click', function () {
            var select = $($(this).data('target'));
            var todos = select.find('option').map(function () { return $(this).val(); }).get();
            select.val(todos).trigger('change');
        });

        $('.btn-limpar-selecao').on('click', function () {
            $($(this).data('target')).val(null).trigger('change');
        });
    }

    // Consultores inativos ficam fora da lista por padrão. As opções são
    // guardadas destacadas do DOM e reinseridas na posição original quando a
    // caixa é marcada; ao desmarcar, uma seleção de inativo é desfeita junto,
    // senão o filtro continuaria valendo sem aparecer na tela.
    function ligarConsultoresInativos() {
        var select = $('#consultor_id');
        var caixa = $('#incluir_consultores_inativos');
        if (!select.length || !caixa.length) {
            return;
        }

        var inativos = select.find('option[data-ativo="0"]').map(function () {
            return { elemento: $(this), indice: $(this).index() };
        }).get();

        function aplicar() {
            if (caixa.is(':checked')) {
                inativos.forEach(function (opcao) {
                    var irmaos = select.children();
                    if (opcao.indice >= irmaos.length) {
                        select.append(opcao.elemento);
                    } else {
                        irmaos.eq(opcao.indice).before(opcao.elemento);
                    }
                });
            } else {
                var selecionados = select.val() || [];
                inativos.forEach(function (opcao) {
                    var valor = opcao.elemento.val();
                    selecionados = selecionados.filter(function (v) { return v !== valor; });
                    opcao.elemento.detach();
                });
                select.val(selecionados);
            }
            select.trigger('change');
        }

        aplicar();
        caixa.on('change', aplicar);
    }

    $(function () {
        ligarConsultoresInativos();
        ligarSelect2();
        graficosDoStatus(metricaPeriodo());
        graficosDaFoto('qtd');

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

        // O recorte de pendentes é feito no servidor, então precisa de nova busca.
        $('#somente_pendentes').on('change', function () {
            if (ultimaFoto) {
                carregarFoto();
            }
        });
    });
})(jQuery);
