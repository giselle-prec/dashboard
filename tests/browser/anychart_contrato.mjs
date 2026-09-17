// Contrato do AnyChart: verifica no navegador as APIs e os comportamentos da
// biblioteca de que os painéis dependem.
//
// Por que existe: as páginas carregam o AnyChart de .../releases/latest/ do
// CDN, ou seja, a versão muda sozinha e sem aviso. Este teste roda contra a
// última versão publicada e falha assim que um método some ou um
// comportamento muda — antes de aparecer como gráfico quebrado na tela.
//
// Não depende de nenhum arquivo do painel, de banco ou de PHP: qualquer branch
// que use os mesmos gráficos é protegida por ele.
//
// Uso:
//   sh tests/browser/preparar_libs.sh          (uma vez, baixa as bibliotecas)
//   node tests/browser/anychart_contrato.mjs

import { readFileSync, existsSync, writeFileSync, mkdtempSync } from 'fs';
import { tmpdir } from 'os';
import { join, dirname } from 'path';
import { fileURLToPath, pathToFileURL } from 'url';
import { createRequire } from 'module';

const AQUI = dirname(fileURLToPath(import.meta.url));
const LIBS = join(AQUI, 'libs');
const ANYCHART = join(LIBS, 'anychart-base.min.js');

if (!existsSync(ANYCHART)) {
    console.error('Bibliotecas ausentes. Rode antes: sh tests/browser/preparar_libs.sh');
    process.exit(2);
}

// O Playwright costuma estar instalado globalmente neste ambiente.
let chromium;
try {
    ({ chromium } = createRequire(import.meta.url)('playwright'));
} catch (e) {
    try {
        ({ chromium } = createRequire('/opt/node22/lib/node_modules/x.js')('playwright'));
    } catch (e2) {
        console.error('Playwright não encontrado. Instale com: npm i -g playwright');
        process.exit(2);
    }
}

const versao = existsSync(join(LIBS, 'VERSAO_ANYCHART'))
    ? readFileSync(join(LIBS, 'VERSAO_ANYCHART'), 'utf8').trim()
    : '(desconhecida)';

let falhas = 0;
function ok(descricao, condicao, detalhe) {
    console.log((condicao ? '  ok   ' : '  FALHA ') + descricao
        + (condicao || !detalhe ? '' : ' — ' + detalhe));
    if (!condicao) falhas++;
}

// Página mínima com uma pizza, uma coluna e uma barra, montadas como o painel
// monta: é o suficiente para exercitar tudo o que usamos da biblioteca.
const PAGINA = `<!doctype html><html><head><meta charset="utf-8"></head><body>
<div id="pizza" style="width:800px;height:400px;"></div>
<div id="coluna" style="width:800px;height:300px;"></div>
<script src="${pathToFileURL(ANYCHART).href}"></script>
<script>
window.dados = [
  { x: 'Fatia A', value: 6 }, { x: 'Fatia B', value: 11 },
  { x: 'Fatia C', value: 4 }, { x: 'Fatia D', value: 4 }
];
window.cliques = [];

var pizza = anychart.pie(window.dados);
window.pizza = pizza;
pizza.legend().position('right').itemsLayout('vertical').align('center');

function escolher(indice, rotulo) {
  pizza.unselect();
  if (indice !== null && indice !== undefined) pizza.select(indice);
  window.cliques.push(rotulo);
}
pizza.listen('pointClick', function (e) {
  var i = (e.pointIndex !== undefined && e.pointIndex !== null) ? e.pointIndex
        : (e.iterator && e.iterator.getIndex ? e.iterator.getIndex() : null);
  var x = (e.point && e.point.get) ? e.point.get('x')
        : (e.iterator && e.iterator.get ? e.iterator.get('x') : null);
  escolher(i, x);
});
pizza.listen('legendItemClick', function (e) {
  e.preventDefault();
  var ponto = window.dados[e.itemIndex];
  escolher(e.itemIndex, ponto ? ponto.x : null);
});
pizza.container('pizza'); pizza.draw();

var coluna = anychart.column([{ x: 'jan', value: 2 }, { x: 'fev', value: 8 }]);
window.coluna = coluna;
coluna.lineMarker().value(5).axis(coluna.yAxis())
      .stroke({ color: '#d63384', dash: '5 3', thickness: 2 }).zIndex(100);
coluna.container('coluna'); coluna.draw();

window.selecionadas = function () {
  return (pizza.getSelectedPoints() || []).map(function (p) { return p.get('x'); });
};
</script></body></html>`;

const dir = mkdtempSync(join(tmpdir(), 'anychart-contrato-'));
const arquivo = join(dir, 'contrato.html');
writeFileSync(arquivo, PAGINA);

const navegador = await chromium.launch({
    executablePath: process.env.PLAYWRIGHT_CHROMIUM || '/opt/pw-browsers/chromium',
});
const pagina = await navegador.newPage({ viewport: { width: 1100, height: 800 } });
const errosJs = [];
pagina.on('pageerror', (e) => errosJs.push(e.message));
await pagina.goto(pathToFileURL(arquivo).href, { waitUntil: 'load' });
await pagina.waitForTimeout(1200);

console.log(`AnyChart ${versao}\n`);
console.log('APIs usadas pelos painéis:');

ok('a página monta os gráficos sem erro de JS', errosJs.length === 0, errosJs.join(' | '));

const api = await pagina.evaluate(() => ({
    pie: typeof anychart.pie === 'function',
    column: typeof anychart.column === 'function',
    bar: typeof anychart.bar === 'function',
    select: typeof window.pizza.select === 'function',
    unselect: typeof window.pizza.unselect === 'function',
    getSelectedPoints: typeof window.pizza.getSelectedPoints === 'function',
    getPoint: typeof window.pizza.getPoint === 'function',
    legend: typeof window.pizza.legend === 'function',
    lineMarker: typeof window.coluna.lineMarker === 'function',
    dispose: typeof window.pizza.dispose === 'function',
}));
Object.keys(api).forEach((nome) => ok('existe ' + nome + '()', api[nome]));

// A pizza não tem modo de seleção única: é justamente por isso que o painel
// chama unselect() antes de select(). Se um dia passar a ter, dá para
// simplificar — e este teste avisa.
ok('a pizza continua sem selectionMode (o painel compensa na mão)',
    await pagina.evaluate(() => typeof window.pizza.selectionMode !== 'function'));

console.log('\nSeleção por clique na fatia:');
const caixa = await pagina.locator('#pizza').boundingBox();
// Sem clicar às cegas: descobre onde cada fatia responde varrendo a área.
const alvos = new Map();
for (let fx = 0.05; fx <= 0.55 && alvos.size < 3; fx += 0.03) {
    for (let fy = 0.15; fy <= 0.9 && alvos.size < 3; fy += 0.05) {
        const antes = await pagina.evaluate(() => window.cliques.length);
        await pagina.mouse.click(caixa.x + caixa.width * fx, caixa.y + caixa.height * fy);
        await pagina.waitForTimeout(60);
        const r = await pagina.evaluate((n) => ({
            novo: window.cliques.length > n,
            rotulo: window.cliques[window.cliques.length - 1],
            x: 0,
        }), antes);
        if (r.novo && !alvos.has(r.rotulo)) alvos.set(r.rotulo, { fx, fy });
    }
}
ok('cliques nas fatias chegam ao código da página', alvos.size >= 2,
    'fatias alcançadas: ' + [...alvos.keys()].join(', '));

const clicar = async ({ fx, fy }) => {
    await pagina.mouse.click(caixa.x + caixa.width * fx, caixa.y + caixa.height * fy);
    await pagina.waitForTimeout(300);
    return pagina.evaluate(() => window.selecionadas());
};
const listaAlvos = [...alvos.values()];
const sel1 = await clicar(listaAlvos[0]);
const sel2 = await clicar(listaAlvos[1]);
const sel3 = await clicar(listaAlvos[0]);
ok('nunca fica mais de uma fatia destacada',
    sel1.length === 1 && sel2.length === 1 && sel3.length === 1,
    JSON.stringify([sel1, sel2, sel3]));
ok('a fatia destacada é a última clicada',
    sel1[0] !== sel2[0] && sel3[0] === sel1[0], JSON.stringify([sel1, sel2, sel3]));

console.log('\nSeleção por clique na legenda:');
const itensLegenda = await pagina.evaluate(() => [...document.querySelectorAll('#pizza text')]
    .map((t) => { const r = t.getBoundingClientRect();
        return { txt: t.textContent, x: r.x + r.width / 2, y: r.y + r.height / 2, w: r.width }; })
    .filter((i) => /^Fatia [A-D]$/.test(i.txt) && i.w > 20));
ok('os itens da legenda são encontráveis', itensLegenda.length >= 3,
    'achados: ' + itensLegenda.length);

if (itensLegenda.length >= 3) {
    // Remede a cada clique: selecionar uma fatia redesenha o gráfico e as
    // posições medidas antes ficam obsoletas.
    const medirLegenda = () => pagina.evaluate(() => [...document.querySelectorAll('#pizza text')]
        .map((t) => { const r = t.getBoundingClientRect();
            return { txt: t.textContent, x: r.x + r.width / 2, y: r.y + r.height / 2, w: r.width }; })
        .filter((i) => /^Fatia [A-D]$/.test(i.txt) && i.w > 20));

    const clicarLegenda = async (indice) => {
        const itens = await medirLegenda();
        await pagina.mouse.click(itens[indice].x, itens[indice].y);
        await pagina.waitForTimeout(300);
        return {
            rotulo: itens[indice].txt,
            selecionadas: await pagina.evaluate(() => window.selecionadas()),
            avisado: await pagina.evaluate(() => window.cliques[window.cliques.length - 1]),
        };
    };

    const leg1 = await clicarLegenda(1);
    const leg2 = await clicarLegenda(2);
    const legSel1 = leg1.selecionadas, avisou1 = leg1.avisado;
    const legSel2 = leg2.selecionadas, avisou2 = leg2.avisado;
    itensLegenda[1] = { txt: leg1.rotulo };
    itensLegenda[2] = { txt: leg2.rotulo };

    ok('clicar na legenda avisa a página, como o clique na fatia',
        avisou1 === itensLegenda[1].txt && avisou2 === itensLegenda[2].txt,
        JSON.stringify([avisou1, avisou2]));
    ok('a legenda também não acumula seleção',
        legSel1.length === 1 && legSel2.length === 1, JSON.stringify([legSel1, legSel2]));
    ok('preventDefault impede a legenda de esconder a fatia',
        await pagina.evaluate(() => {
            let visiveis = 0;
            for (let i = 0; i < window.dados.length; i++) {
                const p = window.pizza.getPoint(i);
                if (p && (!p.exists || p.exists())) visiveis++;
            }
            return visiveis === window.dados.length;
        }));
}

console.log('\nLinha da média no gráfico de colunas:');
ok('o marcador de linha aceita valor, eixo e zIndex',
    await pagina.evaluate(() => {
        const m = window.coluna.lineMarker();
        return m.value() === 5 && typeof m.zIndex === 'function' && m.zIndex() === 100;
    }));

await navegador.close();

console.log(falhas === 0
    ? `\nOK: contrato do AnyChart ${versao} confirmado.`
    : `\nFALHOU: ${falhas} verificação(ões).`);
process.exit(falhas ? 1 : 0);
