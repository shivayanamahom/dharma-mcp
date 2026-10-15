#!/usr/bin/env bash
# Dev-only: выпуск версии. Версия берётся из pkg/pkg_dharma_mcp.xml.
#
#   dev/release.sh            — сухой прогон: сборка, проверка, показ того, что будет опубликовано
#   dev/release.sh --publish  — публикация: тег и релиз на GitHub со ссылкой для установки, затем XML обновлений
#
# Порядок публикации (важен): 1) всё закоммичено и отправлено; 2) релиз с файлом пакета;
# 3) updates/pkg_dharma_mcp.xml с адресом файла и SHA-256 — после этого Joomla на сайтах увидит обновление.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

REPO="shivayanamahom/dharma-mcp"
GH="${GH:-/opt/homebrew/bin/gh}"
VERSION="$(sed -n 's|.*<version>\(.*\)</version>.*|\1|p' pkg/pkg_dharma_mcp.xml | head -1)"
TAG="v$VERSION"
ZIP="dist/pkg_dharma_mcp-$VERSION.zip"
URL="https://github.com/$REPO/releases/download/$TAG/pkg_dharma_mcp-$VERSION.zip"
PUBLISH=0
[ "${1:-}" = "--publish" ] && PUBLISH=1

# Версии должны совпадать во всех манифестах, иначе Joomla покажет путаницу в списке расширений.
for f in com_dharma_mcp/dharma_mcp.xml plg_*/*.xml; do
  v="$(sed -n 's|.*<version>\(.*\)</version>.*|\1|p' "$f" | head -1)"
  [ "$v" = "$VERSION" ] || { echo "Версия в $f ($v) не совпадает с пакетом ($VERSION)"; exit 1; }
done

if [ -n "$(git status --porcelain | grep -v '^?? dist/' || true)" ]; then
  echo "Есть незакоммиченные изменения — сначала закоммитьте."; git status --short; exit 1
fi

./build.sh >/dev/null
SHA="$(shasum -a 256 "$ZIP" | awk '{print $1}')"
echo "Версия:  $VERSION"
echo "Файл:    $ZIP ($(wc -c < "$ZIP" | tr -d ' ') байт)"
echo "SHA-256: $SHA"
echo "Ссылка:  $URL"

write_update_xml() {
  mkdir -p updates
  cat > updates/pkg_dharma_mcp.xml <<XML
<?xml version="1.0" encoding="utf-8"?>
<updates>
	<update>
		<name>Dharma MCP</name>
		<description>MCP-сервер для сайта на Joomla: магазин, материалы, меню, модули, шаблон и настройки через ИИ-ассистента.</description>
		<element>pkg_dharma_mcp</element>
		<type>package</type>
		<version>$VERSION</version>
		<infourl title="Dharma MCP $VERSION">https://github.com/$REPO/releases/tag/$TAG</infourl>
		<downloads>
			<downloadurl type="full" format="zip">$URL</downloadurl>
		</downloads>
		<sha256>$SHA</sha256>
		<tags>
			<tag>stable</tag>
		</tags>
		<maintainer>Dharma</maintainer>
		<maintainerurl>https://github.com/$REPO</maintainerurl>
		<targetplatform name="joomla" version="((5\.[4-9])|(6\.[0-9]+))" />
		<php_minimum>8.3</php_minimum>
	</update>
</updates>
XML
}

if [ "$PUBLISH" -eq 0 ]; then
  write_update_xml
  echo "--- updates/pkg_dharma_mcp.xml (сухой прогон, не публикуется) ---"
  cat updates/pkg_dharma_mcp.xml
  # Сухой прогон ничего не оставляет: файл возвращается к закоммиченному виду или удаляется.
  if git ls-files --error-unmatch updates/pkg_dharma_mcp.xml >/dev/null 2>&1; then
    git checkout -- updates
  else
    rm -rf updates
  fi
  echo "Для публикации: dev/release.sh --publish"
  exit 0
fi

git fetch -q origin
[ "$(git rev-parse HEAD)" = "$(git rev-parse origin/main)" ] || { echo "HEAD не совпадает с origin/main: сначала git push."; exit 1; }
"$GH" release view "$TAG" -R "$REPO" >/dev/null 2>&1 && { echo "Релиз $TAG уже есть. Увеличьте версию."; exit 1; }

NOTES="$(mktemp)"
cat > "$NOTES" <<MD
## Dharma MCP $VERSION

MCP-сервер для сайта на Joomla 6: подключите ИИ-ассистента (Claude, ChatGPT, Claude Code) и управляйте магазином, страницами, меню, модулями, шаблоном и настройками из чата.

### Установка

Joomla → **Система → Установка → Расширения → Установка из URL**, вставьте ссылку:

\`\`\`
$URL
\`\`\`

Или скачайте файл и загрузите на вкладке «Загрузить файл пакета».

После установки в **Система → Обновление → Расширения** будут появляться новые версии (источник обновлений зарегистрируется сам).

### Дальше

1. Компоненты → Dharma MCP → Токены доступа → создайте токен.
2. Адрес сервера: \`https://ваш-сайт/mcp\`.
3. Подробно: [установка и подключение](https://github.com/$REPO/blob/main/docs/INSTALL.md), [безопасность](https://github.com/$REPO/blob/main/docs/SECURITY.md).

Требования: Joomla 6, PHP 8.3+, HTTPS.

> Тестовая версия: не используйте на рабочем сайте без резервной копии.

SHA-256: \`$SHA\`
MD
"$GH" release create "$TAG" "$ZIP" -R "$REPO" --target main --title "Dharma MCP $VERSION" --notes-file "$NOTES"
rm -f "$NOTES"

write_update_xml
git add updates/pkg_dharma_mcp.xml
git -c user.name=Dharma -c user.email=shivayanamahom87@gmail.com commit -q -m "Обновления: Dharma MCP $VERSION в списке обновлений Joomla"
git push -q origin main
echo "Опубликовано: https://github.com/$REPO/releases/tag/$TAG"
