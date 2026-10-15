#!/usr/bin/env bash
# Dev-only: раскладывает исходники в тестовый сайт без пересборки пакета (после первой установки пакета).
# Путь к сайту: DHARMA_SITE (по умолчанию /Volumes/Work/Server/www/test).
set -euo pipefail

S="$(cd "$(dirname "$0")/.." && pwd)"
T="${DHARMA_SITE:-/Volumes/Work/Server/www/test}"
R="rsync -a --delete --exclude .DS_Store"

C="$S/com_dharma_mcp"
$R "$C/admin/" "$T/administrator/components/com_dharma_mcp/" --exclude language
cp "$C/dharma_mcp.xml" "$T/administrator/components/com_dharma_mcp/"
$R "$C/site/" "$T/components/com_dharma_mcp/" --exclude language
for l in ru-RU en-GB; do
  cp "$C/admin/language/$l/"*.ini "$T/administrator/language/$l/"
  cp "$C/site/language/$l/com_dharma_mcp.ini" "$T/language/$l/com_dharma_mcp.ini"
done

for P in "$S"/plg_dharmamcp_*; do
  EL="${P##*plg_dharmamcp_}"
  D="$T/plugins/dharmamcp/$EL"
  mkdir -p "$D"
  for d in services src language; do $R "$P/$d/" "$D/$d/"; done
  cp "$P/$EL.xml" "$D/"
done

echo "Синхронизировано с $T"
