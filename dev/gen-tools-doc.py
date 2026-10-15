#!/usr/bin/env python3
"""Dev-only: собирает docs/TOOLS.md из исходников инструментов (имя, набор, область, уровень, описание).

Запуск: python3 dev/gen-tools-doc.py
Файл docs/TOOLS.md — производный, вручную не редактировать.
"""
import glob
import os
import re

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))

PACKS = [
    ("core", "Ядро", "com_dharma_mcp/site/src/Tool", "Всегда доступно."),
    ("shop", "Магазин (Dharma Shop)", "plg_dharmamcp_shop/src/Tool", "Плагин `dharmamcp/shop`. Включён по умолчанию, если установлен Dharma Shop."),
    ("content", "Материалы и меню", "plg_dharmamcp_content/src/Tool", "Плагин `dharmamcp/content`. Включён по умолчанию."),
    ("template", "Шаблон", "plg_dharmamcp_template/src/Tool", "Плагин `dharmamcp/template`. **Выключен по умолчанию** (доступ к файлам). Нужен Суперпользователь для записи файлов."),
    ("system", "Система", "plg_dharmamcp_system/src/Tool", "Плагин `dharmamcp/system`. **Выключен по умолчанию** (настройки, расширения, база). Только Суперпользователь."),
]

LEVELS = {
    "Read": "чтение",
    "Draft": "черновик",
    "Write": "запись",
    "Danger": "опасное",
}


def text(pattern, src, default=""):
    m = re.search(pattern, src, re.S)
    return m.group(1) if m else default


def php_string(expr):
    """Склеивает конкатенацию PHP-строк 'a' . 'b' в один текст (без вычисляемых кусков)."""
    # Известные константы подставляются числами, иначе они выпали бы из текста.
    for const, value in {"self::MAX_ROWS": "'200'", "self::MAX_PRODUCTS": "'100'", "self::CONFIRM_ABOVE": "'10'"}.items():
        expr = expr.replace(const, value)
    parts = re.findall(r"'((?:[^'\\]|\\.)*)'", expr)
    return "".join(p.replace("\\'", "'").replace("\\\\", "\\") for p in parts)


def read_tool(path):
    src = open(path, encoding="utf-8").read()
    if "abstract class" in src:
        return None
    name = text(r"function getName\(\): string\s*\{\s*return '([^']+)'", src)
    if not name:
        return None
    title = text(r"function getTitle\(\): string\s*\{\s*return '([^']*)'", src, name)
    scope = text(r"function getRequiredScope\(\): string\s*\{\s*return '([^']*)'", src, "")
    access = text(r"Access::(Read|Draft|Write|Danger)", src, "Read")
    desc_expr = text(r"function getDescription\(\): string\s*\{\s*return (.*?);\s*\}", src)
    description = php_string(desc_expr)
    description = re.sub(r"\s+", " ", description).strip()
    return {"name": name, "title": title, "scope": scope or "—", "access": access, "description": description}


def main():
    out = [
        "# Справочник инструментов",
        "",
        "> Файл создаётся скриптом `dev/gen-tools-doc.py` из исходников. Не редактируйте вручную.",
        "",
        "Описания инструментов написаны по-английски: их читает ИИ-ассистент, и так он выбирает инструмент точнее. "
        "Уровни доступа и подтверждения — в [SECURITY.md](SECURITY.md).",
        "",
        "| Уровень | Значение | Подтверждение |",
        "|---|---|---|",
        "| чтение | ничего не меняет | не нужно |",
        "| черновик | создаёт то, что не видно посетителям (неопубликованное) | не нужно |",
        "| запись | меняет «живые» данные сайта | режим «черновики»: всегда; «полный доступ»: если инструмент просит (массовые изменения) |",
        "| опасное | файлы, настройки, плагины | всегда |",
        "",
    ]
    total = 0
    for key, title, folder, note in PACKS:
        tools = []
        for path in sorted(glob.glob(os.path.join(ROOT, folder, "*Tool.php"))):
            tool = read_tool(path)
            if tool:
                tools.append(tool)
        if not tools:
            continue
        total += len(tools)
        out += [f"## {title}", "", note, "", "| Инструмент | Область | Уровень | Что делает |", "|---|---|---|---|"]
        for t in sorted(tools, key=lambda x: x["name"]):
            description = t["description"].replace("|", "\\|")
            out.append(f"| `{t['name']}` | `{t['scope']}` | {LEVELS[t['access']]} | {description} |")
        out.append("")
    out.insert(4, f"Всего инструментов: **{total}**.")
    out.insert(5, "")
    with open(os.path.join(ROOT, "docs", "TOOLS.md"), "w", encoding="utf-8") as f:
        f.write("\n".join(out) + "\n")
    print(f"docs/TOOLS.md: {total} инструментов")


if __name__ == "__main__":
    main()
