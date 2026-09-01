-- Reconciliação entre uma consulta direta no banco e a aba "Foto por data" do
-- Painel de Oxigenação.
--
-- Motivo: uma consulta de "primeiro contato até a data" devolveu 8.128
-- precatórios, enquanto o card "Demais status" do painel mostrou 1.908 para a
-- mesma data. As duas medidas respondem perguntas diferentes, e as consultas
-- abaixo mostram exatamente onde cada precatório entra ou sai.
--
-- Uso: ajuste @data_ref e rode bloco a bloco. Cada consulta devolve um rótulo e
-- uma quantidade, para montar a conta de cima para baixo.

SET @data_ref   = '2023-10-08';
SET @limite     = DATE_ADD(@data_ref, INTERVAL 1 DAY);  -- limite exclusivo
SET @ano_ref    = YEAR(@data_ref);

-- Família "Sem Tentativa": o status 1 e todos os filhos dele. O painel lê essa
-- lista do banco em vez de fixar no código, então confira o que ela traz hoje.
SELECT statusPrecatorio_id, Status
FROM StatusPrecatorio
WHERE statusPrecatorio_id = 1 OR ParentId = 1;

-- ---------------------------------------------------------------------------
-- 1. O ponto de partida: precatórios cujo PRIMEIRO contato ocorreu até a data.
--    É a medida acumulada — inclui quem depois voltou para Sem Tentativa e quem
--    já foi quitado.
-- ---------------------------------------------------------------------------
SELECT 'primeiro contato até a data' AS Linha, COUNT(*) AS Qtd
FROM HistoricoContato hc
INNER JOIN (
    SELECT PrecatorioId, MIN(historicoContato_id) AS primeiro_id
    FROM HistoricoContato
    WHERE PrecatorioId IS NOT NULL
    GROUP BY PrecatorioId
) pri ON hc.historicoContato_id = pri.primeiro_id
WHERE hc.DataContato < @limite;

-- ---------------------------------------------------------------------------
-- 2. Descontos que explicam a diferença. Cada linha sai do total acima.
-- ---------------------------------------------------------------------------

-- 2a. Contato cujo PrecatorioId não existe mais na tabela Precatorio. A consulta
--     direta conta (o LEFT JOIN não descarta); o painel não, porque parte da
--     tabela de precatórios.
SELECT 'sem precatório correspondente' AS Linha, COUNT(*) AS Qtd
FROM HistoricoContato hc
INNER JOIN (
    SELECT PrecatorioId, MIN(historicoContato_id) AS primeiro_id
    FROM HistoricoContato WHERE PrecatorioId IS NOT NULL GROUP BY PrecatorioId
) pri ON hc.historicoContato_id = pri.primeiro_id
LEFT JOIN Precatorio p ON p.precatorio_id = hc.PrecatorioId
WHERE hc.DataContato < @limite AND p.precatorio_id IS NULL;

-- 2b. Contato sem resultado gravado. O painel ignora esses registros, porque
--     sem ResultContatoId não há status a atribuir.
SELECT 'primeiro contato sem resultado' AS Linha, COUNT(*) AS Qtd
FROM HistoricoContato hc
INNER JOIN (
    SELECT PrecatorioId, MIN(historicoContato_id) AS primeiro_id
    FROM HistoricoContato WHERE PrecatorioId IS NOT NULL GROUP BY PrecatorioId
) pri ON hc.historicoContato_id = pri.primeiro_id
WHERE hc.DataContato < @limite AND hc.ResultContatoId IS NULL;

-- 2c. Primeiro contato que já resultou em Sem Tentativa: houve contato, mas o
--     precatório não saiu do status, então não é oxigenação.
SELECT 'primeiro contato resultou em Sem Tentativa' AS Linha, COUNT(*) AS Qtd
FROM HistoricoContato hc
INNER JOIN (
    SELECT PrecatorioId, MIN(historicoContato_id) AS primeiro_id
    FROM HistoricoContato WHERE PrecatorioId IS NOT NULL GROUP BY PrecatorioId
) pri ON hc.historicoContato_id = pri.primeiro_id
WHERE hc.DataContato < @limite
  AND hc.ResultContatoId IN (SELECT statusPrecatorio_id FROM StatusPrecatorio
                             WHERE statusPrecatorio_id = 1 OR ParentId = 1);

-- 2d. Voltou para Sem Tentativa: o ÚLTIMO contato até a data devolveu o
--     precatório à família. Conta na medida acumulada, não conta na foto.
SELECT 'voltou para Sem Tentativa até a data' AS Linha, COUNT(*) AS Qtd
FROM (
    SELECT h2.PrecatorioId, h2.ResultContatoId
    FROM HistoricoContato h2
    INNER JOIN (
        SELECT h3.PrecatorioId, MAX(h3.historicoContato_id) AS ult_id
        FROM HistoricoContato h3
        INNER JOIN (
            SELECT PrecatorioId, MAX(DataContato) AS ult_data
            FROM HistoricoContato
            WHERE ResultContatoId IS NOT NULL AND DataContato < @limite
            GROUP BY PrecatorioId
        ) ultd ON ultd.PrecatorioId = h3.PrecatorioId AND h3.DataContato = ultd.ult_data
        WHERE h3.ResultContatoId IS NOT NULL
        GROUP BY h3.PrecatorioId
    ) ulti ON ulti.ult_id = h2.historicoContato_id
) ult
WHERE ult.ResultContatoId IN (SELECT statusPrecatorio_id FROM StatusPrecatorio
                              WHERE statusPrecatorio_id = 1 OR ParentId = 1);

-- ---------------------------------------------------------------------------
-- 3. O recorte "somente pendentes de pagamento na data", que vem marcado na
--    tela. É quase sempre o maior desconto em datas antigas.
-- ---------------------------------------------------------------------------

-- Quitados hoje que NÃO têm data de quitação registrada em fonte nenhuma. Para
-- esses o painel estima o ano de pagamento pelo orçamento.
SELECT 'quitados sem data registrada' AS Linha, COUNT(*) AS Qtd
FROM Precatorio p
LEFT JOIN (
    SELECT CAST(n_batch_quit AS SIGNED) AS BatchQuit, CAST(ente_id AS SIGNED) AS EnteId,
           MIN(data_batch) AS DataRodada
    FROM BatchControl
    WHERE n_batch_quit IS NOT NULL AND CAST(n_batch_quit AS SIGNED) <> 0
      AND ente_id IS NOT NULL AND data_batch IS NOT NULL AND data_batch <> ''
    GROUP BY CAST(n_batch_quit AS SIGNED), CAST(ente_id AS SIGNED)
) q ON q.BatchQuit = p.batch AND q.EnteId = p.EnteId
WHERE p.prec_pg IS NOT NULL AND q.DataRodada IS NULL;

-- Como a estimativa classifica esses quitados na data escolhida. "pendente na
-- data" volta para a foto; "quitado antes da data" fica de fora.
SELECT
    CASE
        WHEN est.AnoEstimado IS NULL          THEN 'sem estimativa possível'
        WHEN est.AnoEstimado >= @ano_ref      THEN 'pendente na data (estimado)'
        ELSE 'quitado antes da data (estimado)'
    END AS Linha,
    COUNT(*) AS Qtd
FROM (
    SELECT p.precatorio_id,
           CASE
               WHEN CAST(p.Orcamento AS SIGNED) < 1990 OR CAST(p.Orcamento AS SIGNED) > 2100 THEN NULL
               WHEN p.EnteId = 1     THEN CAST(p.Orcamento AS SIGNED) + 5
               WHEN e.Especial = 1   THEN CAST(p.Orcamento AS SIGNED) + 4
               WHEN e.Especial = 0   THEN CAST(p.Orcamento AS SIGNED) + 2
           END AS AnoEstimado
    FROM Precatorio p
    LEFT JOIN Ente e ON e.ente_id = p.EnteId
    WHERE p.prec_pg IS NOT NULL
) est
GROUP BY 1;

-- ---------------------------------------------------------------------------
-- 4. Universo da foto, para fechar a conta com os cards da tela.
-- ---------------------------------------------------------------------------
SELECT 'precatórios que já existiam na data' AS Linha, COUNT(*) AS Qtd
FROM Precatorio p
WHERE p.DataCadastra IS NULL OR p.DataCadastra < @limite;

SELECT 'nunca quitados (pendentes em qualquer data)' AS Linha, COUNT(*) AS Qtd
FROM Precatorio p
WHERE (p.DataCadastra IS NULL OR p.DataCadastra < @limite) AND p.prec_pg IS NULL;

-- ---------------------------------------------------------------------------
-- 5. Status apagados que ainda aparecem no histórico. Os ids 25 a 28 o painel
--    já mostra como "Credor não Estava/Ocupado (Status Antigo)"; o que sobrar
--    aqui aparece na tela como "Status #NN" e ainda precisa ser identificado.
-- ---------------------------------------------------------------------------
SELECT hc.ResultContatoId AS StatusId, COUNT(*) AS Ocorrencias,
       MIN(hc.DataContato) AS PrimeiroUso, MAX(hc.DataContato) AS UltimoUso
FROM HistoricoContato hc
LEFT JOIN StatusPrecatorio sp ON sp.statusPrecatorio_id = hc.ResultContatoId
WHERE hc.ResultContatoId IS NOT NULL AND sp.statusPrecatorio_id IS NULL
GROUP BY hc.ResultContatoId
ORDER BY Ocorrencias DESC;
