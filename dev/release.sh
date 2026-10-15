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
SHA="$( (sha256sum "$ZIP" 2>/dev/null || shasum -a 256 "$ZIP") | awk '{print $1}')"
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
		<client>site</client>
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

# Раздел версии из CHANGELOG.md — это «Что нового» в заметках релиза.
CHANGES="$(awk -v v="$VERSION" '$0 ~ "^## " v " " {f=1; next} /^## /{f=0} f' CHANGELOG.md | sed '/^$/d')"
[ -n "$CHANGES" ] || CHANGES="- Подробности: [CHANGELOG](https://github.com/$REPO/blob/main/CHANGELOG.md)."

# Файл со стабильным именем: ссылка .../releases/latest/download/pkg_dharma_mcp.zip всегда ведёт на последнюю версию.
cp "$ZIP" dist/pkg_dharma_mcp.zip
LATEST="https://github.com/$REPO/releases/latest/download/pkg_dharma_mcp.zip"

NOTES="$(mktemp)"
cat > "$NOTES" <<MD
### Релиз Dharma MCP v$VERSION

$CHANGES

---

### Быстрая установка / обновление по URL:
\`\`\`text
$URL
\`\`\`

1. В админке Joomla: **Система** → **Установка** → **Расширения** → вкладка **«Установить по URL-адресу»**.
2. Вставьте ссылку в поле **«URL пакета установки»** и нажмите **«Проверить и установить»**.

Ссылка на всегда последнюю версию: \`$LATEST\`

После установки новые версии появляются в **Система → Обновление → Расширения**. Дальше: **Компоненты → Dharma MCP → Подключение** ([инструкция](https://github.com/$REPO/blob/main/docs/INSTALL.md), [безопасность](https://github.com/$REPO/blob/main/docs/SECURITY.md)).

Требования: Joomla 6, PHP 8.3+, HTTPS. Версия 0.x тестовая: не используйте на рабочем сайте без резервной копии.

SHA-256: \`$SHA\`
MD

if [ "$PUBLISH" -eq 0 ]; then
  write_update_xml
  echo "--- updates/pkg_dharma_mcp.xml (сухой прогон, не публикуется) ---"
  cat updates/pkg_dharma_mcp.xml
  echo "--- заметки релиза ---"
  cat "$NOTES"
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

"$GH" release create "$TAG" "$ZIP" dist/pkg_dharma_mcp.zip -R "$REPO" --target main --title "v$VERSION — Dharma MCP" --notes-file "$NOTES"
rm -f "$NOTES"

write_update_xml
git add updates/pkg_dharma_mcp.xml
git -c user.name=Dharma -c user.email=shivayanamahom87@gmail.com commit -q -m "Обновления: Dharma MCP $VERSION в списке обновлений Joomla"
git push -q origin main
echo "Опубликовано: https://github.com/$REPO/releases/tag/$TAG"
