(function ($) {
    'use strict';

    var tabela;
    // Evita que a resposta de uma consulta antiga sobrescreva a mais nova
    // quando o usuário troca de ente antes do TJ responder.
    var consultaAtual = 0;

    var colunasComuns = [
        { data: 'OrdemPagamento',       title: 'Ordem',             name: 'OrdemPagamento' },
        { data: 'OrdemRateio',          title: 'Ordem Rateio',      name: 'OrdemRateio', visible: false },
        { data: 'EntidadeDevedora',     title: 'Entidade Devedora', name: 'EntidadeDevedora' },
        { data: 'NumeroPrecatorio',     title: 'Precatório',        name: 'NumeroPrecatorio' },
        { data: 'SituacaoTratada',      title: 'Situação',          name: 'SituacaoTratada' },
        { data: 'NumeroProcOriginario', title: 'Processo',          name: 'NumeroProcOriginario' },
        { data: 'Natureza',             title: 'Natureza',          name: 'Natureza' }
    ];

    var colunasOrdensDefault = [
        { data: 'DataProtocolo',           title: 'Data Protocolo',  name: 'DataProtocolo' },
        { data: 'Orcamento',               title: 'Orçamento',       name: 'Orcamento' },
        { data: 'ValorHistoricoFormatado', title: 'Valor Histórico', name: 'ValorHistoricoFormatado' },
        { data: 'SaldoFormatado',          title: 'Valor TJRJ',      name: 'SaldoFormatado' },
        { data: 'Pago',                    title: 'Pago',            name: 'Pago', visible: false }
    ];

    var colunasRateio = [
        { data: 'AnoOrcamento',            title: 'Ano Orçamento',   name: 'AnoOrcamento',            visible: false },
        { data: 'ValorPagamentoFormatado', title: 'Valor Pagamento', name: 'ValorPagamentoFormatado', visible: false },
        { data: 'TipoPagamento',           title: 'Tipo Pagamento',  name: 'TipoPagamento',           visible: false }
    ];

    // Colunas reservadas para dados internos que ainda serão cruzados com o
    // Precabot; por enquanto ficam vazias e escondidas.
    var colunasInternas = ['reu_id', 'fracao_aporte', 'aporte_acumulado', 'previ', 'status',
                           'consultor_precabot', 'consultor_novo'].map(function (nome) {
        return { data: null, defaultContent: '', title: nome, name: nome, visible: false };
    });

    var configColunas = {
        'default': {
            visiveis: ['OrdemPagamento', 'EntidadeDevedora', 'NumeroPrecatorio', 'SituacaoTratada',
                       'NumeroProcOriginario', 'Natureza', 'DataProtocolo', 'Orcamento',
                       'ValorHistoricoFormatado', 'SaldoFormatado'],
            ocultas:  ['OrdemRateio', 'AnoOrcamento', 'ValorPagamentoFormatado', 'TipoPagamento']
        },
        '4': {
            visiveis: ['OrdemRateio', 'EntidadeDevedora', 'NumeroPrecatorio', 'SituacaoTratada',
                       'NumeroProcOriginario', 'Natureza', 'AnoOrcamento',
                       'ValorPagamentoFormatado', 'TipoPagamento'],
            ocultas:  ['OrdemPagamento', 'DataProtocolo', 'Orcamento',
                       'ValorHistoricoFormatado', 'SaldoFormatado']
        }
    };

    function mostrarErro(mensagem) {
        $('#alerta-erro').removeClass('d-none').text(mensagem);
    }

    function limparErro() {
        $('#alerta-erro').addClass('d-none').text('');
    }

    function mensagemDeFalha(xhr) {
        if (xhr.status === 401) {
            window.location = 'login';
            return null;
        }
        if (xhr.responseJSON && xhr.responseJSON.erro) {
            return xhr.responseJSON.erro;
        }
        return 'Não foi possível carregar os dados.';
    }

    function ordemSelecionada() {
        return $('input[name="ordem"]:checked').val() || '2';
    }

    // Consultor (PerfilId 2) não tem os botões de exportação; sem eles o
    // DataTables volta ao layout padrão (seletor de itens por página).
    function layoutComExportacao() {
        if ($('body').hasClass('perfil-consultor')) {
            return {};
        }
        return {
            topStart: {
                buttons: ['copyHtml5', 'excelHtml5', 'csvHtml5', 'pdfHtml5']
            }
        };
    }

    function criarTabela() {
        tabela = new DataTable('#tabela-tjrj', {
            layout: layoutComExportacao(),
            pageLength: 25,
            order: [[0, 'asc']],
            scrollX: true,
            data: [],
            columns: colunasComuns.concat(colunasOrdensDefault, colunasRateio, colunasInternas),
            columnDefs: [{ targets: '_all', defaultContent: '' }]
        });
    }

    function atualizarColunas(ordem) {
        var config = configColunas[ordem] || configColunas['default'];
        config.visiveis.forEach(function (nome) {
            tabela.column(nome + ':name').visible(true, false);
        });
        config.ocultas.forEach(function (nome) {
            tabela.column(nome + ':name').visible(false, false);
        });
        tabela.columns.adjust().draw(false);
    }

    function carregarEntes() {
        $.ajax({ url: 'api/tjrj', method: 'GET', dataType: 'json', data: { acao: 'entes' } })
            .done(function (resposta) {
                var $select = $('#ente_id').empty()
                    .append($('<option>', { value: '', text: 'Selecione um Ente', selected: true, disabled: true }));
                resposta.entes.forEach(function (ente) {
                    $select.append($('<option>', { value: ente.id, text: ente.nome }));
                });
                $select.prop('disabled', false);
            })
            .fail(function (xhr) {
                var mensagem = mensagemDeFalha(xhr);
                if (mensagem) {
                    $('#ente_id').empty().append($('<option>', { value: '', text: 'Entes indisponíveis' }));
                    mostrarErro(mensagem);
                }
            });
    }

    function buscarPrecatorios() {
        var enteId = $('#ente_id').val();
        if (!enteId) {
            return;
        }
        var ordem = ordemSelecionada();
        var consulta = ++consultaAtual;

        limparErro();
        atualizarColunas(ordem);
        tabela.clear().draw();
        $('#carregando').removeClass('d-none');

        $.ajax({
            url: 'api/tjrj',
            method: 'GET',
            dataType: 'json',
            data: { acao: 'precatorios', ente_id: enteId, ordem: ordem }
        })
            .done(function (resposta) {
                if (consulta !== consultaAtual) {
                    return;
                }
                tabela.rows.add(resposta.precatorios).draw();
            })
            .fail(function (xhr) {
                if (consulta !== consultaAtual) {
                    return;
                }
                var mensagem = mensagemDeFalha(xhr);
                if (mensagem) {
                    mostrarErro(mensagem);
                }
            })
            .always(function () {
                if (consulta === consultaAtual) {
                    $('#carregando').addClass('d-none');
                }
            });
    }

    $(function () {
        criarTabela();
        atualizarColunas(ordemSelecionada());
        carregarEntes();

        $('#ente_id').on('change', buscarPrecatorios);

        // Trocar o tipo de consulta limpa a tabela e pede o ente de novo.
        $('input[name="ordem"]').on('change', function () {
            consultaAtual++;
            $('#carregando').addClass('d-none');
            limparErro();
            tabela.clear().draw();
            $('#ente_id').val('');
            atualizarColunas(ordemSelecionada());
        });

        $('#form-tjrj').on('submit', function (e) {
            e.preventDefault();
        });
    });
})(jQuery);
