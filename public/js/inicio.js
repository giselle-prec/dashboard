// Resumo semanal da página inicial. Sem filtros: os dois blocos são buscados
// em paralelo assim que a página abre, e cada um preenche a sua parte da tela
// (a prospecção é bem mais lenta e não segura a oxigenação).
(function ($) {
    'use strict';

    var moedaFormatter = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' });
    var inteiroFormatter = new Intl.NumberFormat('pt-BR');
    var percentualFormatter = new Intl.NumberFormat('pt-BR', { minimumFractionDigits: 1, maximumFractionDigits: 1 });

    var charts = {};

    // Consultor (PerfilId 2): a API já devolve só os precatórios dele.
    var somenteConsultor = $('body').hasClass('perfil-consultor');

    function formatarMoeda(valor) {
        return moedaFormatter.format(Number(valor) || 0);
    }

    function formatarInteiro(valor) {
        return inteiroFormatter.format(Number(valor) || 0);
    }

    function formatarData(valor) {
        var partes = String(valor || '').substring(0, 10).split('-');
        if (partes.length !== 3) {
            return valor;
        }
        return partes[2] + '/' + partes[1] + '/' + partes[0];
    }

    function descartarGrafico(id) {
        if (charts[id]) {
            charts[id].dispose();
            delete charts[id];
        }
    }

    // O contêiner começa com um "Carregando..." em HTML, que sai antes do desenho.
    function desenhar(id, chart) {
        // O gráfico por consultor não é desenhado na página para o consultor.
        if (!document.getElementById(id)) {
            chart.dispose();
            return;
        }
        descartarGrafico(id);
        $('#' + id).empty();
        charts[id] = chart;
        chart.container(id);
        chart.draw();
    }

    function mensagemNoGrafico(id, texto) {
        descartarGrafico(id);
        $('#' + id).empty().append($('<div class="inicio-aguardando"></div>').text(texto));
    }

    function mensagemDeErro(xhr) {
        // Sessão expirada: a API responde 401 e o usuário volta ao login.
        if (xhr.status === 401) {
            window.location = 'login';
        }
        if (xhr.responseJSON && xhr.responseJSON.erro) {
            return xhr.responseJSON.erro;
        }
        return 'Não foi possível carregar os dados.';
    }

    function buscar(acao, aoCarregar, aoFalhar) {
        $.getJSON('api/inicio', { acao: acao })
            .done(function (resposta) {
                if (!resposta.ok) {
                    aoFalhar(resposta.erro || 'Não foi possível carregar os dados.');
                    return;
                }
                aoCarregar(resposta);
            })
            .fail(function (xhr) {
                aoFalhar(mensagemDeErro(xhr));
            });
    }

    // ------------------------------------------------------------------
    // Oxigenação
    // ------------------------------------------------------------------

    // Texto da comparação com a semana anterior. A seta vai junto do número
    // para que o sentido não dependa só da cor.
    function variacaoSemanal(atual, anterior) {
        var diferenca = atual - anterior;
        if (diferenca === 0) {
            return { classe: '', texto: '= igual à semana anterior (' + formatarInteiro(anterior) + ')' };
        }

        var seta = diferenca > 0 ? '↑' : '↓';
        // Sem oxigenação na semana anterior não há base para percentual.
        var tamanho = anterior > 0
            ? percentualFormatter.format(Math.abs(diferenca) / anterior * 100) + '%'
            : (diferenca > 0 ? '+' : '−') + formatarInteiro(Math.abs(diferenca));

        return {
            classe: diferenca > 0 ? 'inicio-variacao-sobe' : 'inicio-variacao-desce',
            texto: seta + ' ' + tamanho + ' vs semana anterior (' + formatarInteiro(anterior) + ')'
        };
    }

    function graficoColunasQtd(id, dados, titulo) {
        if (!dados.length) {
            mensagemNoGrafico(id, 'Nenhuma oxigenação nos últimos 5 dias úteis.');
            return;
        }

        var series = dados.map(function (linha) {
            return { x: linha.rotulo, value: linha.qtd, valor: linha.valor };
        });

        var chart = anychart.column(series);
        chart.title(titulo);
        chart.yScale().minimum(0);
        // Quantidade de precatórios: com poucos eventos, sem isto o eixo
        // mostraria 0,5 — e o formato inteiro faria o rótulo se repetir.
        chart.yScale().ticks().allowFractional(false);
        chart.yAxis().labels().format(function () {
            return formatarInteiro(this.value);
        });
        chart.xAxis().labels().rotation(-45);
        chart.tooltip().format(function () {
            return formatarInteiro(this.value) + ' precatórios\n' + formatarMoeda(this.getData('valor'));
        });
        desenhar(id, chart);
    }

    function preencherOxigenacao(resposta) {
        var atual = Number(resposta.semana_atual.qtd) || 0;
        var anterior = Number(resposta.semana_anterior.qtd) || 0;
        var variacao = variacaoSemanal(atual, anterior);

        $('#card-oxi-qtd').text(formatarInteiro(atual) + ' precatórios');
        $('#card-oxi-variacao')
            .removeClass('inicio-variacao-sobe inicio-variacao-desce')
            .addClass(variacao.classe)
            .text(variacao.texto);

        var base = resposta.base_sem_tentativa;
        $('#card-base-qtd').text(formatarInteiro(base.qtd) + ' precatórios');
        $('#card-base-valor').text(formatarMoeda(base.valor));

        graficoColunasQtd('chart-oxi-consultor', resposta.por_consultor, 'Quantidade por consultor');
        graficoColunasQtd('chart-oxi-ente', resposta.por_ente, 'Top 15 entes — Quantidade');
    }

    function falhaOxigenacao(mensagem) {
        $('#alerta-oxigenacao').removeClass('d-none').text(mensagem);
        $('#card-oxi-variacao, #card-base-valor').text('Indisponível');
        mensagemNoGrafico('chart-oxi-consultor', 'Indisponível.');
        mensagemNoGrafico('chart-oxi-ente', 'Indisponível.');
    }

    // ------------------------------------------------------------------
    // Prospecção (mesmos gráficos do Painel de Prospecção, sem agrupar por
    // consultora)
    // ------------------------------------------------------------------

    function preencherCardsProspeccao(resumo) {
        var prefixo = '#card-prosp';
        $(prefixo + '-total-qtd').text(formatarInteiro(resumo.qtd_total) + ' precatórios');
        $(prefixo + '-total-valor').text(formatarMoeda(resumo.valor_total));
        $(prefixo + '-prospectados-qtd').text(formatarInteiro(resumo.qtd_prospectados) + ' precatórios');
        $(prefixo + '-prospectados-valor').text(formatarMoeda(resumo.valor_prospectados));
        $(prefixo + '-pendente-com-qtd').text(formatarInteiro(resumo.qtd_pendente_com_req) + ' precatórios');
        $(prefixo + '-pendente-com-valor').text(formatarMoeda(resumo.valor_pendente_com_req));
        $(prefixo + '-pendente-sem-qtd').text(formatarInteiro(resumo.qtd_pendente_sem_req) + ' precatórios');
        $(prefixo + '-pendente-sem-valor').text(formatarMoeda(resumo.valor_pendente_sem_req));
    }

    function tooltipValorEQtd() {
        return formatarMoeda(this.value) + '\n' + formatarInteiro(this.getData('qtd')) + ' precatórios';
    }

    function graficoPizzaProspeccao(resumo) {
        var chart = anychart.pie([
            { x: 'Prospectados', value: resumo.valor_prospectados, qtd: resumo.qtd_prospectados },
            { x: 'Pendente c/ Requisitório', value: resumo.valor_pendente_com_req, qtd: resumo.qtd_pendente_com_req },
            { x: 'Pendente s/ Requisitório', value: resumo.valor_pendente_sem_req, qtd: resumo.qtd_pendente_sem_req }
        ]);
        chart.title('Distribuição de Valor (Prospecção)');
        chart.labels().format(function () {
            return this.x + ' (' + formatarInteiro(this.getData('qtd')) + ')';
        });
        chart.tooltip().format(tooltipValorEQtd);
        desenhar('chart-prosp-resumo', chart);
    }

    // anychart.bar() = barras horizontais, como no painel. O eixo de valores
    // continua sendo yAxis() mesmo com o gráfico deitado.
    function graficoStatusProspeccao(porStatus) {
        if (!porStatus.length) {
            mensagemNoGrafico('chart-prosp-status', 'Nenhum precatório pendente.');
            return;
        }

        var chart = anychart.bar(porStatus.map(function (linha) {
            return { x: linha.StatusPrec, value: linha.ValorTotal, qtd: linha.QuantidadeTotal };
        }));
        chart.title('Valor Total por Status');
        chart.yAxis().labels().format(function () {
            return formatarMoeda(this.value);
        });
        chart.tooltip().format(tooltipValorEQtd);
        chart.labels().enabled(true).format(function () {
            return formatarInteiro(this.getData('qtd'));
        });
        desenhar('chart-prosp-status', chart);
    }

    // ------------------------------------------------------------------
    // Batch
    // ------------------------------------------------------------------

    // data_batch é texto livre: quando não dá para ler como data, mostra o
    // texto gravado e avisa que a idade não pôde ser calculada.
    function idadeDoBatch(dias) {
        if (dias === null || dias === undefined) {
            return 'Data gravada em formato livre, sem como calcular a idade';
        }
        if (dias <= 0) {
            return 'Último batch hoje';
        }
        return 'Último batch ' + (dias === 1 ? 'há 1 dia' : 'há ' + formatarInteiro(dias) + ' dias');
    }

    function preencherBatch(resposta) {
        var alvo = $('#batch-mais-antigos').empty();
        var lista = resposta.batch_mais_antigos || [];

        if (!lista.length) {
            alvo.append($('<div class="col-12 text-muted"></div>')
                .text('Nenhum ente com precatório pendente tem batch registrado.'));
        }

        lista.forEach(function (item, indice) {
            var data = item.data ? formatarData(item.data) : item.data_batch;
            var cartao = $('<div class="card text-bg-warning h-100"><div class="card-body"></div></div>');
            cartao.find('.card-body').append(
                $('<h6 class="card-title"></h6>').text((indice + 1) + 'º · ' + (item.nome_ente || 'Ente ' + item.ente_id)),
                $('<p class="card-text fs-5 mb-0"></p>').text(data),
                $('<p class="card-text"></p>').text(idadeDoBatch(item.dias))
            );
            alvo.append($('<div class="col-md-4"></div>').append(cartao));
        });

        var semBatch = Number(resposta.entes_sem_batch) || 0;
        $('#batch-nota').text(semBatch > 0
            ? formatarInteiro(semBatch) + (semBatch === 1 ? ' ente' : ' entes') +
              ' com precatórios pendentes nunca ' + (semBatch === 1 ? 'passou' : 'passaram') +
              ' por batch e não ' + (semBatch === 1 ? 'entra' : 'entram') + ' nesta lista.'
            : '');
    }

    function preencherProspeccao(resposta) {
        var resumo = resposta.resumo;

        $('#card-pendente-valor').text(formatarMoeda(resumo.valor_total));
        $('#card-pendente-qtd').text(formatarInteiro(resumo.qtd_total) + ' precatórios ativos em ' +
            formatarInteiro(resposta.qtd_entes) + ' entes');

        $('#prosp-subtitulo').text(somenteConsultor
            ? 'Seus precatórios pendentes estão em ' + formatarInteiro(resposta.qtd_entes) + ' entes. Mesmos ' +
              'números do Painel de Prospecção com esses entes selecionados e nenhum outro filtro.'
            : formatarInteiro(resposta.qtd_entes) + ' entes. Mesmos números do Painel de ' +
              'Prospecção com todos esses entes selecionados e nenhum outro filtro.');

        preencherCardsProspeccao(resumo);
        graficoPizzaProspeccao(resumo);
        graficoStatusProspeccao(resposta.por_status || []);
        preencherBatch(resposta);
    }

    function falhaProspeccao(mensagem) {
        $('#alerta-prospeccao').removeClass('d-none').text(mensagem);
        $('#card-pendente-qtd').text('Indisponível');
        mensagemNoGrafico('chart-prosp-resumo', 'Indisponível.');
        mensagemNoGrafico('chart-prosp-status', 'Indisponível.');
        $('#batch-mais-antigos').empty().append($('<div class="col-12 text-muted"></div>').text('Indisponível.'));
    }

    $(function () {
        buscar('oxigenacao', preencherOxigenacao, falhaOxigenacao);
        buscar('prospeccao', preencherProspeccao, falhaProspeccao);
    });
})(jQuery);
