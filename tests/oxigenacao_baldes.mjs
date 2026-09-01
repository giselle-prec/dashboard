// Verificação das funções de agrupamento temporal de public/js/oxigenacao.js.
//
// O arquivo é uma IIFE que depende de jQuery e AnyChart, então não dá para
// importá-lo. Estas funções são puras, e o teste as extrai do fonte pelo nome
// para exercitá-las isoladamente — o que importa aqui é a aritmética de datas e
// o preenchimento dos períodos vazios, que é o que sustenta a linha da média.
//
// Uso: node tests/oxigenacao_baldes.mjs   (a partir da raiz do projeto)

import { readFileSync } from 'fs';

const src = readFileSync('public/js/oxigenacao.js', 'utf8');

// Extrai uma função pelo nome, casando as chaves.
function extrair(nome) {
    const i = src.indexOf('function ' + nome + '(');
    if (i < 0) {
        throw new Error('não achei a função ' + nome + ' em public/js/oxigenacao.js');
    }
    let abertas = 0;
    for (let k = src.indexOf('{', i); k < src.length; k++) {
        if (src[k] === '{') abertas++;
        else if (src[k] === '}') {
            abertas--;
            if (abertas === 0) return src.slice(i, k + 1);
        }
    }
    throw new Error('função ' + nome + ' sem fechamento');
}

const codigo = 'var MAX_BALDES = 400;\n'
    + ['segundaFeiraDa', 'chaveTemporal', 'somarDias', 'chavesDoPeriodo', 'agruparPorDia']
        .map(extrair).join('\n')
    + '\nreturn { chavesDoPeriodo, agruparPorDia, chaveTemporal };';

const m = new Function(codigo)();

let falhas = 0;
function ok(descricao, condicao, detalhe) {
    console.log((condicao ? '  ok   ' : '  FALHA ') + descricao
        + (condicao || !detalhe ? '' : ' — ' + detalhe));
    if (!condicao) falhas++;
}

const meses = m.chavesDoPeriodo('2025-11-03', '2026-02-20', 'mes');
ok('meses atravessam a virada de ano',
    JSON.stringify(meses) === JSON.stringify(['2025-11', '2025-12', '2026-01', '2026-02']),
    JSON.stringify(meses));

const dias = m.chavesDoPeriodo('2026-02-26', '2026-03-02', 'dia');
ok('dias atravessam o fim do mês',
    JSON.stringify(dias) === JSON.stringify(
        ['2026-02-26', '2026-02-27', '2026-02-28', '2026-03-01', '2026-03-02']),
    JSON.stringify(dias));

const semanas = m.chavesDoPeriodo('2026-01-07', '2026-01-25', 'semana');
ok('semanas começam na segunda-feira',
    semanas[0] === '2026-01-05'
    && semanas.every((d) => new Date(d + 'T00:00:00').getDay() === 1),
    JSON.stringify(semanas));

ok('faixa larga demais em dias devolve vazio, para não desenhar milhares de barras',
    m.chavesDoPeriodo('2000-01-01', '2026-01-01', 'dia').length === 0);
ok('a mesma faixa em meses cabe no teto',
    m.chavesDoPeriodo('2000-01-01', '2026-01-01', 'mes').length === 313);

ok('data final anterior à inicial não gera períodos',
    m.chavesDoPeriodo('2026-05-01', '2026-04-01', 'mes').length === 0);

// Preenchimento dos vazios e o efeito disso na média.
const porDia = [
    { rotulo: '2026-01-15', qtd: 10, valor: 1000 },
    { rotulo: '2026-03-02', qtd: 20, valor: 2000 },
];
const baldes = m.agruparPorDia(porDia, 'mes', { inicio: '2026-01-01', fim: '2026-03-31' });
ok('mês sem oxigenação aparece zerado no eixo',
    baldes.length === 3 && baldes[1].chave === '2026-02' && baldes[1].qtd === 0,
    JSON.stringify(baldes));

const media = baldes.reduce((soma, b) => soma + b.qtd, 0) / baldes.length;
ok('média divide pelos 3 meses do intervalo, não pelos 2 com movimento',
    media === 10, 'media=' + media);

ok('sem intervalo informado, só entram os períodos com dado',
    m.agruparPorDia(porDia, 'mes', null).length === 2);

console.log(falhas === 0
    ? '\nOK: ' + 9 + ' verificações, 0 falha(s).'
    : '\nFALHOU: ' + falhas + ' falha(s).');
process.exit(falhas ? 1 : 0);
