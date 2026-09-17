# Testes de navegador

Verificam, em um navegador de verdade, os comportamentos da biblioteca de
gráficos (AnyChart) de que os painéis dependem.

## Por que existem

As páginas carregam o AnyChart de `https://cdn.anychart.com/releases/latest/`.
"latest" significa que a versão **muda sozinha e sem aviso**: uma atualização
pode remover um método ou mudar um comportamento e quebrar os gráficos sem que
nada tenha sido alterado no repositório.

O teste de contrato roda contra a última versão publicada e falha assim que
isso acontece. Ele não depende de PHP, de banco, nem de nenhum arquivo do
painel — qualquer branch que use os mesmos gráficos é protegida por ele.

Dois comportamentos que já causaram defeito e agora estão cobertos:

- A pizza do AnyChart **não tem modo de seleção única** (`selectionMode` não
  existe nela). Sem desfazer o destaque na mão, clicar em vários status
  deixava todos destacados ao mesmo tempo.
- Clicar na legenda **seleciona a fatia por conta própria** e, por padrão,
  esconde a fatia. O painel usa `preventDefault()` para que o clique na legenda
  siga o mesmo caminho do clique na fatia.

## Como rodar

```sh
sh tests/browser/preparar_libs.sh          # uma vez: baixa AnyChart e jQuery
node tests/browser/anychart_contrato.mjs
```

O primeiro comando busca as bibliotecas no npm e as grava em
`tests/browser/libs/`, que não é versionado. Ele pega a **última versão
publicada**, de propósito: é o mesmo que o CDN entrega em produção.

Requisitos: Node e Playwright com o Chromium instalado. Se o Chromium estiver
em outro lugar, aponte com a variável `PLAYWRIGHT_CHROMIUM`.

## Ao mexer nos gráficos

Se o painel passar a usar um método novo do AnyChart, acrescente-o à lista de
APIs verificadas em `anychart_contrato.mjs`. É barato e evita descobrir a
ausência dele em produção.

Uma armadilha ao escrever testes que clicam em gráficos: **remeça as posições
antes de cada clique**. Selecionar uma fatia redesenha o que está em volta e
desloca o layout — coordenadas medidas uma única vez ficam obsoletas e os
cliques seguintes caem no vazio, o que parece uma falha do código sem ser.
