#!/bin/sh
# Baixa AnyChart e jQuery para tests/browser/libs/, que os testes de navegador
# carregam no lugar das CDNs.
#
# As páginas em produção usam .../releases/latest/ do CDN do AnyChart, então
# aqui também pegamos a última versão publicada: é o que faz o teste avisar
# quando uma versão nova quebra alguma API que o painel usa.
#
# Uso: sh tests/browser/preparar_libs.sh
set -e

DESTINO="$(cd "$(dirname "$0")" && pwd)/libs"
mkdir -p "$DESTINO"
TEMP="$(mktemp -d)"
trap 'rm -rf "$TEMP"' EXIT

echo "Baixando anychart e jquery do npm..."
cd "$TEMP"
npm pack anychart >/dev/null 2>&1
npm pack jquery >/dev/null 2>&1

for pacote in anychart-*.tgz jquery-*.tgz; do
    tar -xzf "$pacote"
    mv package "${pacote%%-[0-9]*}"
done

cp anychart/dist/js/anychart-base.min.js "$DESTINO/"
cp jquery/dist/jquery.min.js "$DESTINO/"

VERSAO="$(sed -n 's/.*"version"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' anychart/package.json | head -1)"
echo "$VERSAO" > "$DESTINO/VERSAO_ANYCHART"

echo "Pronto: AnyChart $VERSAO e jQuery em $DESTINO"
