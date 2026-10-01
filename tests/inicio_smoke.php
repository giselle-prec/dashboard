<?php

// Verificação do resumo semanal da página inicial (src/inicio_repository.php)
// e do último batch por ente (src/prospeccao_repository.php).
//
// Não precisa de banco nem de CSV: roda contra um SQLite em memória anexado
// com o nome "precappapp", assim as consultas usam os mesmos nomes de tabela
// de produção (precappapp.Precatorio etc.) sem nada a sobrescrever.
//
// Uso:
//   php tests/inicio_smoke.php

require __DIR__ . '/../src/inicio_repository.php';

$falhas = 0;
$total = 0;

function verificar($descricao, $condicao, $detalhe = '') {
    global $falhas, $total;
    $total++;
    if ($condicao) {
        echo "  ok   {$descricao}\n";
        return;
    }
    $falhas++;
    echo "  FALHA {$descricao}" . ($detalhe !== '' ? " — {$detalhe}" : '') . "\n";
}

function lanca($classe, callable $fn) {
    try {
        $fn();
    } catch (Throwable $e) {
        return $e instanceof $classe;
    }
    return false;
}

function inserir(PDO $pdo, $tabela, array $colunas, array $linhas) {
    $stmt = $pdo->prepare("INSERT INTO precappapp.{$tabela} (" . implode(', ', $colunas) . ') VALUES ('
        . implode(', ', array_fill(0, count($colunas), '?')) . ')');
    foreach ($linhas as $linha) {
        $stmt->execute($linha);
    }
}

// ---------------------------------------------------------------------------
echo "Semana — janelas de dias úteis\n";
// ---------------------------------------------------------------------------

function so_dias_uteis(array $dias) {
    foreach ($dias as $dia) {
        if ((int)(new DateTimeImmutable($dia))->format('N') > 5) {
            return false;
        }
    }
    return true;
}

function contiguas(array $janelas) {
    $diaSeguinte = (new DateTimeImmutable($janelas['anterior']['fim']))->modify('+1 day')->format('Y-m-d');
    return $diaSeguinte === $janelas['atual']['inicio'];
}

// Quarta-feira: hoje entra.
$j = inicio_janelas_semana('2026-09-30');
verificar('quarta: semana atual de 24/09 a 30/09',
    $j['atual']['dias'] === ['2026-09-24', '2026-09-25', '2026-09-28', '2026-09-29', '2026-09-30']
    && $j['atual']['inicio'] === '2026-09-24' && $j['atual']['fim'] === '2026-09-30',
    json_encode($j['atual']));
verificar('quarta: semana anterior de 17/09 a 23/09',
    $j['anterior']['dias'] === ['2026-09-17', '2026-09-18', '2026-09-21', '2026-09-22', '2026-09-23']
    && $j['anterior']['inicio'] === '2026-09-17' && $j['anterior']['fim'] === '2026-09-23',
    json_encode($j['anterior']));
verificar('quarta: janelas contíguas', contiguas($j));
verificar('rótulo da janela', inicio_rotulo_janela($j['atual']) === '24/09 a 30/09/2026',
    inicio_rotulo_janela($j['atual']));

// Segunda-feira: a semana atual começa na terça anterior.
$j = inicio_janelas_semana('2026-09-28');
verificar('segunda: semana atual de 22/09 a 28/09',
    $j['atual']['dias'] === ['2026-09-22', '2026-09-23', '2026-09-24', '2026-09-25', '2026-09-28'],
    json_encode($j['atual']['dias']));
verificar('segunda: semana anterior de 15/09 a 21/09',
    $j['anterior']['dias'] === ['2026-09-15', '2026-09-16', '2026-09-17', '2026-09-18', '2026-09-21']
    && $j['anterior']['fim'] === '2026-09-21');
verificar('segunda: janelas contíguas', contiguas($j));

// Sábado e domingo: hoje não é dia útil, mas continua sendo o fim da janela
// (um contato feito hoje ainda conta).
foreach (['2026-10-03' => 'sábado', '2026-10-04' => 'domingo'] as $hoje => $nome) {
    $j = inicio_janelas_semana($hoje);
    verificar("{$nome}: dias úteis de 28/09 a 02/10, até hoje",
        $j['atual']['dias'] === ['2026-09-28', '2026-09-29', '2026-09-30', '2026-10-01', '2026-10-02']
        && $j['atual']['fim'] === $hoje, json_encode($j['atual']));
    verificar("{$nome}: semana anterior de 21/09 a 27/09",
        $j['anterior']['inicio'] === '2026-09-21' && $j['anterior']['fim'] === '2026-09-27');
    verificar("{$nome}: janelas contíguas", contiguas($j));
}

$ok = true;
for ($d = new DateTimeImmutable('2026-01-01'); $d < new DateTimeImmutable('2027-01-01'); $d = $d->modify('+1 day')) {
    $j = inicio_janelas_semana($d->format('Y-m-d'));
    if (count($j['atual']['dias']) !== 5 || count($j['anterior']['dias']) !== 5
        || !so_dias_uteis($j['atual']['dias']) || !so_dias_uteis($j['anterior']['dias']) || !contiguas($j)) {
        $ok = false;
        break;
    }
}
verificar('o ano inteiro: sempre 5 dias úteis em cada janela e janelas contíguas', $ok);

verificar('rótulo com virada de ano mostra os dois anos',
    inicio_rotulo_janela(['inicio' => '2025-12-29', 'fim' => '2026-01-02']) === '29/12/2025 a 02/01/2026');
verificar('data inválida é recusada',
    lanca(InvalidArgumentException::class, function () { inicio_janelas_semana('30/09/2026'); }));

// ---------------------------------------------------------------------------
echo "Batch — data gravada como texto\n";
// ---------------------------------------------------------------------------

verificar('Y-m-d', inicio_data_do_batch('2024-07-15') === '2024-07-15');
verificar('Y-m-d com hora', inicio_data_do_batch('2024-07-15 10:30:00') === '2024-07-15');
verificar('d/m/Y', inicio_data_do_batch('15/07/2024') === '2024-07-15');
verificar('d/m/Y com hora', inicio_data_do_batch('15/07/2024 10:30') === '2024-07-15');
verificar('texto livre não vira data', inicio_data_do_batch('lote de julho') === null);
verificar('data impossível não vira data', inicio_data_do_batch('2024-02-30') === null);

// ---------------------------------------------------------------------------
// Banco de teste
// ---------------------------------------------------------------------------

$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec("ATTACH DATABASE ':memory:' AS precappapp");

$pdo->exec('CREATE TABLE precappapp.StatusPrecatorio (statusPrecatorio_id INTEGER, Status TEXT, ParentId INTEGER)');
$pdo->exec('CREATE TABLE precappapp.HistoricoContato (historicoContato_id INTEGER, PrecatorioId INTEGER,
            DataContato TEXT, ResultContatoId INTEGER, Negociador INTEGER)');
// DataRecebimento serve também como Datarecebimento: o SQLite não diferencia
// maiúsculas nos nomes de coluna.
$pdo->exec('CREATE TABLE precappapp.precatoriodetalhe (precatorio_id INTEGER, Precatorio TEXT, Processo TEXT,
            ente_id INTEGER, EnteId INTEGER, Ente TEXT, Orcamento TEXT, Negociador INTEGER, FirstName TEXT,
            ValorPrec TEXT, vlr_atual_tj TEXT, DataRecebimento TEXT, StatusId INTEGER, StatusPrec TEXT,
            prec_pg TEXT, Active INTEGER, RequisitorioId INTEGER, NaturezaId INTEGER)');
$pdo->exec('CREATE TABLE precappapp.Precatorio (precatorio_id INTEGER, EnteId INTEGER, StatusId INTEGER,
            ValorPrec TEXT, prec_pg TEXT, Negociador INTEGER)');
$pdo->exec('CREATE TABLE precappapp.BatchControl (idBatchControl INTEGER, data_batch TEXT, ente_id INTEGER)');
$pdo->exec('CREATE TABLE precappapp.Ente (ente_id INTEGER, Ente TEXT)');

inserir($pdo, 'StatusPrecatorio', ['statusPrecatorio_id', 'Status', 'ParentId'], [
    [1, 'Sem Tentativa', null],
    [65, 'Sem Tentativa', 1],
    [2, 'Em negociação', null],
    [3, 'Contatado', null],
    [66, 'Contrato assinado', null],
]);

inserir($pdo, 'Ente', ['ente_id', 'Ente'], [
    [66, 'Município A'], [70, 'Município B'], [100, 'Município C'],
    [101, 'Município D'], [102, 'Município E'], [999, 'Outro'],
]);

// ---------------------------------------------------------------------------
echo "Oxigenação da semana\n";
// ---------------------------------------------------------------------------

// Precatórios só da oxigenação: Active = 0 os deixa fora da prospecção, que
// usa a mesma view.
$colunasDetalhe = ['precatorio_id', 'Precatorio', 'ente_id', 'EnteId', 'Ente', 'Negociador', 'FirstName',
                   'ValorPrec', 'StatusId', 'StatusPrec', 'prec_pg', 'Active', 'RequisitorioId'];
inserir($pdo, 'precatoriodetalhe', $colunasDetalhe, [
    [1, 'P1', 66, 66, 'Município A', 10, 'Ana', '1000', 2, 'Em negociação', null, 0, 1],
    [2, 'P2', 66, 66, 'Município A', 11, 'Bia', '500',  3, 'Contatado',     null, 0, 1],
    [3, 'P3', 70, 70, 'Município B', 10, 'Ana', '200',  2, 'Em negociação', null, 0, 1],
    [4, 'P4', 66, 66, 'Município A', 10, 'Ana', '300',  2, 'Em negociação', null, 0, 1],
    [5, 'P5', 70, 70, 'Município B', 11, 'Bia', '50',   2, 'Em negociação', null, 0, 1],
    [6, 'P6', 66, 66, 'Município A', 10, 'Ana', '70',   2, 'Em negociação', null, 0, 1],
    [7, 'P7', 66, 66, 'Município A', 10, 'Ana', '80',   65, 'Sem Tentativa', null, 0, 1],
    [8, 'P8', 66, 66, 'Município A', 10, 'Ana', '90',   2, 'Em negociação', null, 0, 1],
]);
inserir($pdo, 'HistoricoContato', ['historicoContato_id', 'PrecatorioId', 'DataContato', 'ResultContatoId', 'Negociador'], [
    [1, 1, '2026-09-25 10:00:00', 2, 10],    // semana atual
    [2, 2, '2026-09-29 15:00:00', 3, 11],    // semana atual
    [3, 3, '2026-09-27 09:00:00', 2, 10],    // domingo dentro da semana atual
    [4, 4, '2026-09-18 11:00:00', 2, 10],    // semana anterior
    [5, 5, '2026-09-20 11:00:00', 2, 11],    // domingo dentro da semana anterior
    [6, 6, '2026-09-10 11:00:00', 2, 10],    // oxigenado antes das duas janelas...
    [7, 6, '2026-09-25 11:00:00', 3, 10],    // ...um contato novo não o conta de novo
    [8, 7, '2026-09-26 11:00:00', 65, 10],   // continua em Sem Tentativa: não é oxigenação
    [9, 8, '2026-10-01 11:00:00', 2, 10],    // depois de hoje
]);

// 17 entes com uma oxigenação cada, para exercitar o corte do top 15.
$detalhe = [];
$historico = [];
for ($i = 0; $i < 17; $i++) {
    $id = 300 + $i;
    $detalhe[] = [$id, "P{$id}", $id, $id, "Ente {$id}", 12, 'Caio', '10', 2, 'Em negociação', null, 0, 1];
    $historico[] = [$id, $id, '2026-09-28 10:00:00', 2, 12];
}
inserir($pdo, 'precatoriodetalhe', $colunasDetalhe, $detalhe);
inserir($pdo, 'HistoricoContato', ['historicoContato_id', 'PrecatorioId', 'DataContato', 'ResultContatoId', 'Negociador'], $historico);

// Base atual (tabela Precatorio): 1001 e 1002 estão em Sem Tentativa.
inserir($pdo, 'Precatorio', ['precatorio_id', 'EnteId', 'StatusId', 'ValorPrec', 'prec_pg'], [
    [1001, 66,   65, '100', null],
    [1002, 70,   1,  '50',  null],
    [1003, 80,   2,  '70',  '1'],    // quitado: o ente 80 não tem pendente
    [1004, null, 2,  '10',  null],   // sem ente
    [1005, 0,    2,  '10',  null],   // ente 0 não é ente
    [1006, 66,   2,  '999', null],
    [1007, 100,  2,  '1',   null],
    [1008, 101,  2,  '1',   null],
    [1009, 102,  2,  '1',   null],
]);

$oxi = inicio_resumo_oxigenacao($pdo, inicio_janelas_semana('2026-09-30'));

verificar('semana atual conta P1, P2, P3 e os 17 do top',
    $oxi['semana_atual']['qtd'] === 20, (string)$oxi['semana_atual']['qtd']);
verificar('valor da semana atual',
    abs($oxi['semana_atual']['valor'] - (1000 + 500 + 200 + 170)) < 0.001, (string)$oxi['semana_atual']['valor']);
verificar('semana anterior conta P4 e o domingo P5',
    $oxi['semana_anterior']['qtd'] === 2 && abs($oxi['semana_anterior']['valor'] - 350) < 0.001,
    json_encode($oxi['semana_anterior']));
verificar('top de entes corta em 15', count($oxi['por_ente']) === 15, (string)count($oxi['por_ente']));
verificar('top de entes começa pelo de maior quantidade',
    $oxi['por_ente'][0]['rotulo'] === 'Município A' && $oxi['por_ente'][0]['qtd'] === 2,
    json_encode($oxi['por_ente'][0]));
$consultores = array_column($oxi['por_consultor'], 'qtd', 'rotulo');
verificar('por consultor, ordenado por quantidade',
    $consultores === ['Caio' => 17, 'Ana' => 2, 'Bia' => 1], json_encode($consultores));
verificar('base atual em Sem Tentativa',
    $oxi['base_sem_tentativa']['qtd'] === 2 && abs($oxi['base_sem_tentativa']['valor'] - 150) < 0.001,
    json_encode($oxi['base_sem_tentativa']));

// ---------------------------------------------------------------------------
echo "Entes pendentes\n";
// ---------------------------------------------------------------------------

$entes = inicio_entes_pendentes($pdo);
verificar('só entes com precatório pendente, sem nulo e sem zero',
    $entes === [66, 70, 100, 101, 102], json_encode($entes));

// ---------------------------------------------------------------------------
echo "Prospecção de todos os entes pendentes\n";
// ---------------------------------------------------------------------------

inserir($pdo, 'precatoriodetalhe', $colunasDetalhe, [
    [201, 'Q1', 66,  66,  'Município A', 10, 'Ana', '1000', 2,  'Em negociação',     null, 1, 2],
    [202, 'Q2', 66,  66,  'Município A', 10, 'Ana', '500',  65, 'Sem Tentativa',     null, 1, 1],
    [203, 'Q3', 70,  70,  'Município B', 11, 'Bia', '200',  65, 'Sem Tentativa',     null, 1, 5],
    [204, 'Q4', 66,  66,  'Município A', 10, 'Ana', '300',  66, 'Contrato assinado', null, 1, 2],
    [205, 'Q5', 70,  70,  'Município B', 11, 'Bia', '40',   2,  'Em negociação',     null, 0, 2],  // inativo
    [206, 'Q6', 66,  66,  'Município A', 10, 'Ana', '60',   2,  'Em negociação',     '1',  1, 2],  // quitado
    [207, 'Q7', 999, 999, 'Outro',       10, 'Ana', '70',   2,  'Em negociação',     null, 1, 2],  // ente fora
]);

$prosp = inicio_resumo_prospeccao($pdo, $entes);
$r = $prosp['resumo'];
verificar('total pendente de pagamento (ativos, entes pendentes)',
    $r['qtd_total'] === 4 && abs($r['valor_total'] - 2000) < 0.001, json_encode($r));
verificar('prospectados e pendentes com/sem requisitório',
    $r['qtd_prospectados'] === 1 && $r['qtd_pendente_com_req'] === 1 && $r['qtd_pendente_sem_req'] === 1
    && abs($r['valor_pendente_sem_req'] - 500) < 0.001, json_encode($r));
verificar('uma linha por status, somando os entes, na ordem do painel',
    $prosp['por_status'] === [
        ['StatusPrec' => 'Sem Tentativa', 'QuantidadeTotal' => 2, 'ValorTotal' => 700.0],
        ['StatusPrec' => 'Em negociação', 'QuantidadeTotal' => 1, 'ValorTotal' => 1000.0],
    ], json_encode($prosp['por_status']));

$vazio = inicio_resumo_prospeccao($pdo, []);
verificar('sem entes pendentes: zeros, sem erro',
    $vazio['resumo']['qtd_total'] === 0 && $vazio['por_status'] === []);

// ---------------------------------------------------------------------------
echo "Último batch por ente\n";
// ---------------------------------------------------------------------------

inserir($pdo, 'BatchControl', ['idBatchControl', 'data_batch', 'ente_id'], [
    [1, '2024-01-10', 66],
    [2, '2024-03-01', 70],
    [3, '15/05/2024', 66],
    [4, 'lote antigo', 100],
    [5, '2024-06-01', 999],
    [6, '2024-06-02 08:00:00', 101],
]);

$porEnte = prospeccao_ultimo_batch_por_ente($pdo, $entes);
verificar('do mais antigo para o mais recente, só o último de cada ente',
    array_map('intval', array_column($porEnte, 'ente_id')) === [70, 66, 100, 101]
    && $porEnte[1]['data_batch'] === '15/05/2024', json_encode($porEnte));

$batch = inicio_batch_mais_antigos($pdo, $entes, '2026-09-30');
$antigos = $batch['mais_antigos'];
verificar('os 3 mais antigos', array_column($antigos, 'ente_id') === [70, 66, 100], json_encode($antigos));
verificar('nome, data e idade do batch',
    $antigos[0]['nome_ente'] === 'Município B' && $antigos[0]['data'] === '2024-03-01'
    && $antigos[0]['dias'] === 943, json_encode($antigos[0]));
verificar('data em d/m/Y é reconhecida', $antigos[1]['data'] === '2024-05-15');
verificar('texto livre fica sem data e sem idade',
    $antigos[2]['data'] === null && $antigos[2]['dias'] === null && $antigos[2]['data_batch'] === 'lote antigo');
verificar('ente pendente sem batch nenhum é contado à parte', $batch['entes_sem_batch'] === 1,
    (string)$batch['entes_sem_batch']);

// O Painel de Prospecção continua recebendo o mesmo registro de antes.
$ultimo = prospeccao_ultimo_batch($pdo, [66, 70, 999]);
verificar('prospeccao_ultimo_batch: o registro de maior id entre os entes',
    $ultimo === ['data_batch' => '2024-06-01', 'ente_id' => 999, 'nome_ente' => 'Outro'], json_encode($ultimo));
$ultimo = prospeccao_ultimo_batch($pdo, [66]);
verificar('prospeccao_ultimo_batch: um ente só', $ultimo['data_batch'] === '15/05/2024');
verificar('prospeccao_ultimo_batch: sem entes', prospeccao_ultimo_batch($pdo, []) === null);
verificar('prospeccao_ultimo_batch: ente sem batch', prospeccao_ultimo_batch($pdo, [102]) === null);

// ---------------------------------------------------------------------------
echo "Consultor: só os precatórios em que é o negociador\n";
// ---------------------------------------------------------------------------

// Na tabela Precatorio (base "Sem Tentativa" e entes pendentes): 1001 e 1006
// são da Ana (10), 1002 e 1007 da Bia (11); os demais não têm negociador.
$pdo->exec('UPDATE precappapp.Precatorio SET Negociador = 10 WHERE precatorio_id IN (1001, 1006)');
$pdo->exec('UPDATE precappapp.Precatorio SET Negociador = 11 WHERE precatorio_id IN (1002, 1007)');

$oxiAna = inicio_resumo_oxigenacao($pdo, inicio_janelas_semana('2026-09-30'), 10);
verificar('oxigenação da semana só da Ana (P1 e P3)',
    $oxiAna['semana_atual']['qtd'] === 2 && abs($oxiAna['semana_atual']['valor'] - 1200) < 0.001,
    json_encode($oxiAna['semana_atual']));
verificar('semana anterior só da Ana (P4)',
    $oxiAna['semana_anterior']['qtd'] === 1 && abs($oxiAna['semana_anterior']['valor'] - 300) < 0.001,
    json_encode($oxiAna['semana_anterior']));
verificar('por consultor traz só a Ana',
    array_column($oxiAna['por_consultor'], 'qtd', 'rotulo') === ['Ana' => 2], json_encode($oxiAna['por_consultor']));
verificar('top de entes só com os entes da Ana', count($oxiAna['por_ente']) === 2, json_encode($oxiAna['por_ente']));
verificar('base em Sem Tentativa só da Ana (1001)',
    $oxiAna['base_sem_tentativa']['qtd'] === 1 && abs($oxiAna['base_sem_tentativa']['valor'] - 100) < 0.001,
    json_encode($oxiAna['base_sem_tentativa']));

verificar('entes pendentes da Ana', inicio_entes_pendentes($pdo, 10) === [66],
    json_encode(inicio_entes_pendentes($pdo, 10)));
verificar('entes pendentes da Bia', inicio_entes_pendentes($pdo, 11) === [70, 100],
    json_encode(inicio_entes_pendentes($pdo, 11)));

$prospAna = inicio_resumo_prospeccao($pdo, [66, 70], 10);
verificar('prospecção só com os precatórios da Ana (Q1, Q2 e Q4)',
    $prospAna['resumo']['qtd_total'] === 3 && abs($prospAna['resumo']['valor_total'] - 1800) < 0.001
    && $prospAna['resumo']['qtd_prospectados'] === 1 && $prospAna['resumo']['qtd_pendente_sem_req'] === 1,
    json_encode($prospAna['resumo']));
$prospBia = inicio_resumo_prospeccao($pdo, [66, 70], 11);
verificar('prospecção só com os precatórios da Bia (Q3)',
    $prospBia['resumo']['qtd_total'] === 1 && $prospBia['resumo']['qtd_pendente_com_req'] === 1
    && $prospBia['por_status'] === [['StatusPrec' => 'Sem Tentativa', 'QuantidadeTotal' => 1, 'ValorTotal' => 200.0]],
    json_encode($prospBia));

// O que a API do Painel de Prospecção faz para o consultor: agrupado por
// consultora e restrito ao negociador da sessão.
$pdo->exec('CREATE TABLE precappapp.Usuario (usuario_id INTEGER, PerfilId INTEGER, FirstName TEXT)');
inserir($pdo, 'Usuario', ['usuario_id', 'PerfilId', 'FirstName'], [[10, 2, 'Ana'], [11, 2, 'Bia']]);
$detalheAna = prospeccao_detalhe($pdo, prospeccao_parse_filtros(['ente_id' => [66, 70], 'por_consultora' => 1], 10));
verificar('painel de prospecção: detalhe por consultora só com a Ana',
    array_values(array_unique(array_column($detalheAna, 'FirstName'))) === ['Ana']
    && array_sum(array_column($detalheAna, 'QuantidadeTotal')) === 2, json_encode($detalheAna));

// O negociador vem da sessão: o que chega na requisição não vale.
$filtrosOxi = oxigenacao_parse_filtros(
    ['consultor_id' => ['11', '12'], 'data_inicio' => '2026-09-01', 'data_fim' => '2026-09-30'], 'periodo', 10);
verificar('oxigenação: negociador substitui o consultor da requisição', $filtrosOxi['consultor_id'] === [10],
    json_encode($filtrosOxi['consultor_id']));
$filtrosOxi = oxigenacao_parse_filtros(
    ['consultor_id' => ['11'], 'data_inicio' => '2026-09-01', 'data_fim' => '2026-09-30'], 'periodo');
verificar('oxigenação: sem negociador, vale o consultor escolhido', $filtrosOxi['consultor_id'] === [11]);
verificar('prospecção: negociador_id da requisição é ignorado',
    prospeccao_parse_filtros(['ente_id' => [66], 'negociador_id' => 11])['negociador_id'] === null);
verificar('prospecção: negociador da sessão entra como inteiro',
    prospeccao_parse_filtros(['ente_id' => [66]], '10')['negociador_id'] === 10);

echo "\n{$total} verificações, {$falhas} falha(s).\n";
exit($falhas > 0 ? 1 : 0);
