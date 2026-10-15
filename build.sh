#!/usr/bin/env bash
# Сборка установочного пакета pkg_dharma_mcp-<версия>.zip (вложенные zip: компонент + плагины-наборы).
# Версия берётся из pkg/pkg_dharma_mcp.xml. Результат — dist/. Перед сборкой проверяется синтаксис всех PHP.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DIST="$ROOT/dist"
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

PHP_BIN="${PHP_BIN:-$(command -v php || echo /Applications/ServBay/package/php/8.4/current/bin/php)}"
VERSION="$(sed -n 's|.*<version>\(.*\)</version>.*|\1|p' "$ROOT/pkg/pkg_dharma_mcp.xml" | head -1)"

echo "Dharma MCP $VERSION: проверка синтаксиса PHP..."
FAILED=0
while IFS= read -r -d '' f; do
  "$PHP_BIN" -l "$f" >/dev/null 2>&1 || { echo "  ОШИБКА: $f"; FAILED=1; }
done < <(find "$ROOT/com_dharma_mcp" "$ROOT"/plg_* "$ROOT/pkg" -name '*.php' -print0)
[ "$FAILED" -eq 0 ] || { echo "Сборка остановлена: есть синтаксические ошибки."; exit 1; }

rm -rf "$DIST"; mkdir -p "$DIST" "$STAGE/pkg"

zip_dir() { # <каталог> <имя zip>
  ( cd "$1" && zip -qr "$STAGE/pkg/$2" . -x '*.DS_Store' -x '*/.git/*' )
}

zip_dir "$ROOT/com_dharma_mcp" com_dharma_mcp.zip
for p in "$ROOT"/plg_*; do
  zip_dir "$p" "$(basename "$p").zip"
done

cp "$ROOT/pkg/pkg_dharma_mcp.xml" "$ROOT/pkg/script.php" "$STAGE/pkg/"
cp -R "$ROOT/pkg/language" "$STAGE/pkg/language"

( cd "$STAGE/pkg" && zip -qr "$DIST/pkg_dharma_mcp-$VERSION.zip" . -x '*.DS_Store' )

echo "Готово: $DIST/pkg_dharma_mcp-$VERSION.zip"
unzip -l "$DIST/pkg_dharma_mcp-$VERSION.zip" | sed -n '4,$p' | head -20
