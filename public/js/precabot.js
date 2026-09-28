(function ($) {
    'use strict';

    var tabela;

    function mostrarErro(mensagem) {
        $('#alerta-erro').removeClass('d-none').text(mensagem);
    }

    function limparErro() {
        $('#alerta-erro').addClass('d-none').text('');
    }

    function renderRequisitorio(valor) {
        if (valor == 1) return 'Não possui';
        if (valor == 3) return 'Físico';
        return 'Eletrônico';
    }

    function criarTabela() {
        tabela = new DataTable('#tabela-precabot', {
            layout: {
                topStart: {
                    buttons: ['copyHtml5', 'excelHtml5', 'csvHtml5', 'pdfHtml5']
                }
            },
            pageLength: 25,
            order: [[0, 'asc']],
            scrollX: true,
            data: [],
            columns: [
                { data: 'Precatorio',      title: 'Precatório' },
                { data: 'Ente',            title: 'Entidade Devedora' },
                { data: 'Orcamento',       title: 'Orçamento' },
                { data: 'StatusPrec',      title: 'Status' },
                { data: 'FirstName',       title: 'Negociador' },
                { data: 'Processo',        title: 'Processo' },
                { data: 'ValorPrec',       title: 'Valor do Precatório' },
                { data: 'NomeReu',         title: 'Nome do Réu' },
                { data: 'ReuId',           title: 'ID do Réu' },
                { data: 'prec_pg',         title: 'Precatório Pago' },
                { data: 'vlr_atual_tj',    title: 'Valor Atual TJ' },
                { data: 'Datarecebimento', title: 'Previsão de Recebimento' },
                { data: 'LastContact',     title: 'Último Contato' },
                { data: 'NextContact',     title: 'Próximo Contato' },
                { data: 'RequisitorioId',  title: 'Requisitório', render: renderRequisitorio }
            ],
            columnDefs: [{ targets: '_all', defaultContent: '' }]
        });
    }

    function coletarFiltros() {
        return {
            escopo: $('input[name="escopo"]:checked').val() || 'ente',
            ente_id: $('#ente_id').val() || '',
            apenas_pendentes: $('#apenas_pendentes').is(':checked') ? 1 : 0
        };
    }

    function buscarDados() {
        limparErro();
        var filtros = coletarFiltros();

        if (filtros.escopo === 'ente' && !filtros.ente_id) {
            mostrarErro('Selecione um Ente.');
            return;
        }

        $('#btn-buscar').prop('disabled', true);
        $('#carregando').removeClass('d-none');

        $.ajax({
            url: 'api/precabot.php',
            method: 'GET',
            dataType: 'json',
            data: filtros
        })
            .done(function (resposta) {
                if (!resposta.ok) {
                    mostrarErro(resposta.erro || 'Não foi possível carregar os dados.');
                    return;
                }
                tabela.clear();
                tabela.rows.add(resposta.dados);
                tabela.draw();
            })
            .fail(function (xhr) {
                if (xhr.status === 401) {
                    window.location = 'login.php';
                    return;
                }
                var mensagem = 'Não foi possível carregar os dados.';
                if (xhr.responseJSON && xhr.responseJSON.erro) {
                    mensagem = xhr.responseJSON.erro;
                }
                mostrarErro(mensagem);
            })
            .always(function () {
                $('#btn-buscar').prop('disabled', false);
                $('#carregando').addClass('d-none');
            });
    }

    $(function () {
        criarTabela();

        // Fora do escopo "Pelo Ente" o select não se aplica, e o volume é
        // grande: já sugere o recorte de pendentes.
        $('input[name="escopo"]').on('change', function () {
            var porEnte = $(this).val() === 'ente';
            $('#grupo-ente').toggleClass('d-none', !porEnte);
            $('#apenas_pendentes').prop('checked', !porEnte);
        });

        $('#form-precabot').on('submit', function (e) {
            e.preventDefault();
            buscarDados();
        });
    });
})(jQuery);
