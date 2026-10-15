#!/usr/bin/env python3
"""Автотест Dharma MCP: проходит протокол, все доступные токену инструменты, сценарии записи, защиту и ошибки.

Запуск:
    MCP_TOKEN=dhmcp_... python3 dev/qa/mcp_qa.py --url https://сайт/mcp [--write] [--insecure] [--rate] [--ro-token ТОКЕН]

  --write      включить сценарии записи (создают записи «QA-MCP …», потом переносят в корзину)
  --insecure   не проверять сертификат (локальные стенды)
  --rate       проверить лимит запросов (делает около 80 быстрых запросов)
  --ro-token   токен только с областями на чтение: проверяется скрытие инструментов записи

Что не проверяется: вход пользователя в браузере (OAuth-экран согласия) и работа облачных клиентов.
Токен берётся из переменной MCP_TOKEN, а не из командной строки, чтобы не попасть в историю команд.
Код возврата 0 — всё прошло, 1 — есть ошибки.
"""
import argparse
import json
import os
import ssl
import sys
import time
import urllib.error
import urllib.request

PASS = FAIL = SKIP = 0
MODE = "drafts"
FAILED = []


def check(name, ok, detail=""):
    global PASS, FAIL
    if ok:
        PASS += 1
        print(f"  ✔ {name}")
    else:
        FAIL += 1
        FAILED.append(name)
        print(f"  ✖ {name}  {detail}")
    return ok


def skip(name, why):
    global SKIP
    SKIP += 1
    print(f"  – {name}: пропущено ({why})")


def section(title):
    print(f"\n== {title}")


class Client:
    def __init__(self, url, token, insecure):
        self.url = url
        self.token = token
        self.ctx = ssl.create_default_context()
        if insecure:
            self.ctx.check_hostname = False
            self.ctx.verify_mode = ssl.CERT_NONE
        self.n = 0
        self.retry429 = True

    def http(self, method="POST", body=None, token="__default__", headers=None, url=None):
        tok = self.token if token == "__default__" else token
        h = {"Content-Type": "application/json"}
        if tok:
            h["Authorization"] = "Bearer " + tok
        h.update(headers or {})
        data = None
        if body is not None:
            data = (body if isinstance(body, str) else json.dumps(body)).encode()
        for attempt in range(5):
            req = urllib.request.Request(url or self.url, data=data, method=method, headers=h)
            try:
                with urllib.request.urlopen(req, context=self.ctx, timeout=60) as r:
                    return r.status, r.read().decode("utf-8", "replace"), dict(r.headers)
            except urllib.error.HTTPError as e:
                if e.code == 429 and self.retry429 and attempt < 4:
                    # Лимит запросов в минуту: ждём, как это сделал бы вежливый клиент.
                    time.sleep(int(e.headers.get("Retry-After", "30")) + 2)
                    continue
                return e.code, e.read().decode("utf-8", "replace"), dict(e.headers)

    def rpc(self, method, params=None, token="__default__"):
        self.n += 1
        code, body, hdr = self.http(body={"jsonrpc": "2.0", "id": self.n, "method": method, "params": params or {}}, token=token)
        try:
            return code, json.loads(body), hdr
        except ValueError:
            return code, {"_raw": body[:300]}, hdr

    def call(self, name, args=None, token="__default__"):
        """Возвращает (isError, данные). Для ошибок протокола — (True, {'_rpc_error': ...})."""
        code, d, _ = self.rpc("tools/call", {"name": name, "arguments": args or {}}, token=token)
        if "error" in d:
            return True, {"_rpc_error": d["error"].get("message", ""), "_http": code}
        if "result" not in d:
            return True, {"_bad": d}
        r = d["result"]
        try:
            t = json.loads(r["content"][0]["text"])
        except Exception:
            t = {"_text": r["content"][0]["text"] if r.get("content") else ""}
        return bool(r.get("isError")), t

    def confirmed(self, name, args):
        """Двухшаговое подтверждение: предпросмотр, затем повтор с кодом (человека играет тест)."""
        err, t = self.call(name, args)
        if isinstance(t, dict) and t.get("status") == "confirmation_required":
            a = dict(args)
            a["confirm_code"] = t["confirm_code"]
            err2, t2 = self.call(name, a)
            return t, err2, t2
        return None, err, t


def run(args):
    token = os.environ.get("MCP_TOKEN", "")
    if not token:
        print("Нужна переменная окружения MCP_TOKEN")
        return 2
    c = Client(args.url, token, args.insecure)
    P = args.prefix
    created = {"articles": [], "categories": [], "menu_items": [], "modules": []}

    # ---------------------------------------------------------------- протокол
    section("Протокол")
    code, d, hdr = c.rpc("initialize", {"protocolVersion": "2025-06-18", "capabilities": {}, "clientInfo": {"name": "qa", "version": "1"}})
    res = d.get("result", {})
    check("initialize: версия протокола согласована", res.get("protocolVersion") == "2025-06-18", str(d)[:150])
    check("initialize: есть имя и версия сервера", bool(res.get("serverInfo", {}).get("version")) and res["serverInfo"]["version"] != "unknown", str(res.get("serverInfo")))
    check("initialize: есть инструкции для ассистента", len(res.get("instructions", "")) > 100)
    code, body, _ = c.http(body={"jsonrpc": "2.0", "method": "notifications/initialized"})
    check("уведомление без id получает 202", code == 202 and body == "", f"{code} {body[:60]}")
    code, body, _ = c.http(body=[{"jsonrpc": "2.0", "id": 1, "method": "ping"}, {"jsonrpc": "2.0", "id": 2, "method": "tools/list"}])
    try:
        ids = [x["id"] for x in json.loads(body)]
    except Exception:
        ids = []
    check("пакетный запрос обрабатывается", code == 200 and ids == [1, 2], f"{code} {body[:80]}")
    code, body, _ = c.http(body="{oops")
    check("битый JSON → 400", code == 400, str(code))
    code, body, _ = c.http(body="")
    check("пустое тело → 400", code == 400, str(code))
    code, body, hdr = c.http(token="")
    check("без токена → 401", code == 401, str(code))
    code, body, hdr = c.http(body={"jsonrpc": "2.0", "id": 1, "method": "ping"}, token="")
    lower = {k.lower(): v for k, v in hdr.items()}
    check("401 с подсказкой WWW-Authenticate", code == 401 and "www-authenticate" in lower and "resource_metadata" in lower["www-authenticate"], str(hdr)[:200])
    code, body, _ = c.http(body={"jsonrpc": "2.0", "id": 1, "method": "ping"}, token="dhmcp_wrong")
    check("неверный токен → 401", code == 401, str(code))
    code, body, _ = c.http(method="GET", body=None)
    check("GET → 405", code == 405, str(code))
    code, body, hdr = c.http(method="OPTIONS", body=None, token="")
    check("OPTIONS (CORS) → 204", code == 204 and "access-control-allow-origin" in {k.lower() for k in hdr}, str(code))
    code, d, _ = c.rpc("no/such/method")
    check("неизвестный метод → ошибка JSON-RPC", d.get("error", {}).get("code") == -32601, str(d)[:120])
    err, t = c.call("no.such.tool")
    check("неизвестный инструмент → ошибка", err and "_rpc_error" in t, str(t)[:100])

    # ---------------------------------------------------------------- список инструментов
    section("Список инструментов")
    code, d, _ = c.rpc("tools/list")
    tools = d.get("result", {}).get("tools", [])
    names = [t["name"] for t in tools]
    have = set(names)
    check("список не пуст", len(tools) > 0, str(d)[:120])
    check("имена уникальны", len(names) == len(have))
    probs = []
    for t in tools:
        s = t.get("inputSchema", {})
        if s.get("type") != "object":
            probs.append(t["name"] + ": схема не object")
        props = s.get("properties", {})
        for r in s.get("required", []):
            if r not in props:
                probs.append(f"{t['name']}: required '{r}' нет в properties")
        if len(t.get("description", "")) < 40:
            probs.append(t["name"] + ": короткое описание")
        if not t.get("title"):
            probs.append(t["name"] + ": нет title")
        a = t.get("annotations", {})
        if "readOnlyHint" not in a or "destructiveHint" not in a:
            probs.append(t["name"] + ": нет аннотаций")
        if not a.get("readOnlyHint") and "confirm_code" not in props:
            probs.append(t["name"] + ": у записи нет confirm_code")
    check("схемы, описания, названия и аннотации корректны", not probs, "; ".join(probs[:6]))
    print(f"  инструментов: {len(tools)}; наборы: " + ", ".join(sorted({n.split('.')[0] for n in names})))

    # ---------------------------------------------------------------- чтение
    section("Чтение (все доступные инструменты)")
    def read(name, args=None, expect=None):
        if name not in have:
            skip(name, "нет у токена или набор выключен")
            return None
        err, t = c.call(name, args or {})
        ok = (not err) and (expect is None or expect(t))
        check(name, ok, json.dumps(t, ensure_ascii=False)[:220])
        return t if ok else None

    pong = read("ping", {}, lambda t: t.get("status") == "pong" and "server_version" in t)
    global MODE
    MODE = (pong or {}).get("server_mode", "drafts")
    print(f"  режим сервера: {MODE}")
    shop = read("shop.info", {}, lambda t: "order_statuses" in t)
    orders = read("orders.list", {"limit": 3}, lambda t: "items" in t and "total" in t)
    products = read("products.search", {"limit": 3}, lambda t: "items" in t)
    read("stock.low", {"limit": 3}, lambda t: "items" in t)
    if orders and orders["items"]:
        o = orders["items"][0]
        t = read("orders.get", {"id": o["id"]}, lambda t: t.get("id") == o["id"] and "items" in t)
        if t:
            raw = json.dumps(t, ensure_ascii=False)
            pii = shop and shop.get("mcp", {}).get("personal_data_allowed")
            check("orders.get: персональные данные скрыты или разрешены настройкой", pii or ("[REDACTED]" in raw or not t.get("customer_email")), raw[:160])
    elif "orders.get" in have:
        skip("orders.get", "в магазине нет заказов")
    if products and products["items"]:
        p = products["items"][0]
        read("products.get", {"id": p["id"]}, lambda t: t.get("id") == p["id"])
    elif "products.get" in have:
        skip("products.get", "в магазине нет товаров")
    cats = read("categories.list", {"limit": 5}, lambda t: "items" in t)
    arts = read("articles.list", {"limit": 3, "state": "all"}, lambda t: "items" in t)
    if arts and arts["items"]:
        read("articles.get", {"id": arts["items"][0]["id"]}, lambda t: "intro_text" in t)
    menus = read("menus.list", {}, lambda t: "menus" in t)
    if menus and menus["menus"]:
        mt = menus["menus"][0]["menutype"]
        read("menus.items", {"menutype": mt, "state": "all"}, lambda t: "items" in t)
    mods = read("modules.list", {"limit": 3}, lambda t: "items" in t)
    if mods and mods["items"]:
        read("modules.get", {"id": mods["items"][0]["id"]}, lambda t: "params" in t)
    read("modules.positions", {}, lambda t: "positions" in t)
    styles = read("template.styles", {}, lambda t: len(t.get("styles", [])) > 0)
    if styles:
        home = [s for s in styles["styles"] if s["home"]] or styles["styles"]
        read("template.style_get", {"id": home[0]["id"]}, lambda t: "declared_params" in t)
        files = read("template.files", {}, lambda t: t.get("count", 0) > 0)
        if files:
            idx = [f for f in files["files"] if f["path"].endswith("index.php")] or files["files"]
            t = read("template.file_read", {"path": idx[0]["path"]}, lambda t: len(t.get("sha256", "")) == 64)
            read("template.file_backups", {"path": idx[0]["path"]}, lambda t: "backups" in t)
    read("system.info", {}, lambda t: "php" in t and "joomla" in t)
    t = read("system.config_get", {}, lambda t: "config" in t)
    if t:
        raw = json.dumps(t)
        check("system.config_get: нет секретов (пароль БД, secret)", '"password"' not in raw and '"secret"' not in raw)
    read("plugins.list", {"group": "dharmamcp"}, lambda t: t.get("total", 0) >= 4)
    read("updates.list", {}, lambda t: "updates" in t)
    read("logs.read", {}, lambda t: "files" in t)
    read("db.tables", {"search": "content", "limit": 3}, lambda t: "tables" in t)
    read("db.query", {"sql": "SELECT COUNT(*) AS n FROM #__content"}, lambda t: t.get("row_count") == 1)
    read("robots.get", {}, lambda t: "exists" in t)

    # ---------------------------------------------------------------- защита
    section("Защита")
    if "db.query" in have:
        for label, sql in [("UPDATE", "UPDATE #__content SET title='x'"), ("два оператора", "SELECT 1; SELECT 2"), ("комментарий", "SELECT 1 /* x */"),
                           ("INTO OUTFILE", "SELECT 1 INTO OUTFILE '/tmp/qa'"), ("таблица пользователей", "SELECT password FROM #__users"),
                           ("таблица токенов", "SELECT token_hash FROM #__dharma_mcp_tokens"), ("переменные сессии", "SELECT @@version"), ("SLEEP", "SELECT SLEEP(1)")]:
            err, t = c.call("db.query", {"sql": sql})
            check(f"db.query отклоняет: {label}", err, json.dumps(t)[:100])
    if "template.file_read" in have:
        for label, path in [("выход через ..", "../../configuration.php"), ("скрытый файл", ".htaccess"), ("запрещённое расширение", "x.sql")]:
            err, t = c.call("template.file_read", {"path": path})
            check(f"template.file_read отклоняет: {label}", err, json.dumps(t)[:100])
        err, t = c.call("template.files", {"template": "../administrator"})
        check("template.files отклоняет подмену имени шаблона", err, json.dumps(t)[:100])
    if "articles.get" in have:
        err, t = c.call("articles.get", {"id": 99999999})
        check("articles.get: несуществующая запись → not_found", err and t.get("error") == "not_found", json.dumps(t)[:100])
        err, t = c.call("articles.get", {})
        check("articles.get: без id → validation", err and t.get("error") == "validation", json.dumps(t)[:100])
    if "articles.update" in have:
        err, t = c.call("articles.update", {"id": 99999999, "title": "x"})
        check("запись по несуществующему id не создаёт подтверждения", err, json.dumps(t)[:100])
    if args.ro_token:
        code, d, _ = c.rpc("tools/list", token=args.ro_token)
        ro_tools = d.get("result", {}).get("tools", [])
        writers = [t["name"] for t in ro_tools if not t["annotations"]["readOnlyHint"]]
        check("токен только на чтение не видит инструментов записи", not writers, ", ".join(writers[:5]))
        if writers == [] and "articles.create" in have:
            err, t = c.call("articles.create", {"title": "x", "category_id": 2}, token=args.ro_token)
            check("токен только на чтение не может вызвать запись", err and "_rpc_error" in t)

    # ---------------------------------------------------------------- запись
    if MODE == "read_only":
        section("Режим «Только чтение»")
        writers = [t["name"] for t in tools if not t["annotations"]["readOnlyHint"]]
        check("в режиме «только чтение» не видно ни одного инструмента записи", not writers, ", ".join(writers[:6]))
        err, t = c.call("articles.create", {"title": "x", "category_id": 2})
        check("запись в режиме «только чтение» отклонена", err, json.dumps(t)[:100])
    elif not args.write:
        print("\n(сценарии записи пропущены: добавьте --write)")
    else:
        write_flows(c, have, P, created)

    # ---------------------------------------------------------------- лимит
    if args.rate:
        section("Лимит запросов")
        c.retry429 = False
        n429 = 0
        first = None
        for i in range(90):
            code, _, _ = c.http(body={"jsonrpc": "2.0", "id": i, "method": "ping"})
            if code == 429:
                n429 += 1
                first = first or i + 1
        check("лимит запросов срабатывает (429)", n429 > 0, f"429 не получено за 90 запросов")
        print(f"  первый 429 на запросе №{first}; всего 429: {n429}")
        time.sleep(61)

    print(f"\nИтог: ✔ {PASS}  ✖ {FAIL}  – {SKIP}")
    if created["articles"] or created["categories"] or created["menu_items"] or created["modules"]:
        print("Созданные тестом записи (перенесены в корзину):", json.dumps(created))
    if FAILED:
        print("Не прошло:")
        for f in FAILED:
            print("  -", f)
    return 1 if FAIL else 0


def write_flows(c, have, P, created):
    section("Запись: материалы и меню")
    if "articles.create" in have:
        err, t = c.call("categories.create", {"title": f"{P} категория"})
        check("categories.create: черновик", not err and t.get("state") == "unpublished", json.dumps(t)[:150])
        if not err:
            created["categories"].append(t["id"])
        err, t = c.call("articles.create", {"title": f"{P} статья", "category_id": 2, "text": "<p>QAMARK-ONE</p>", "meta_description": "qa"})
        ok = check("articles.create: черновик", not err and t.get("state") == "unpublished", json.dumps(t)[:150])
        if ok:
            a = t["id"]
            created["articles"].append(a)
            err, g = c.call("articles.get", {"id": a})
            check("articles.get видит созданную статью", not err and g.get("state") == "unpublished" and "QAMARK-ONE" in json.dumps(g))
            prev, err, t = c.confirmed("articles.update", {"id": a, "text": "<p>QAMARK-TWO</p>", "title": f"{P} статья 2"})
            check("articles.update: " + ("сразу выполнено (полный доступ)" if MODE == "full_write" else "предпросмотр, затем выполнение"), (prev is not None) == (MODE != "full_write") and not err and t.get("updated"), json.dumps(t)[:150])
            err, g = c.call("articles.get", {"id": a})
            check("articles.update применился", "QAMARK-TWO" in json.dumps(g) and "QAMARK-ONE" not in json.dumps(g))
            if MODE != "full_write":
                err, t = c.call("articles.update", {"id": a, "title": "подмена", "confirm_code": "DEADBEEF"})
                check("articles.update: неверный код не выполняет", t.get("status") == "confirmation_required", json.dumps(t)[:120])
            prev, err, t = c.confirmed("articles.set_state", {"id": a, "state": "published"})
            check("articles.set_state: опубликована", not err and t.get("state") == "published", json.dumps(t)[:120])
            prev, err, t = c.confirmed("articles.set_state", {"id": a, "state": "trashed"})
            check("articles.set_state: в корзину", not err and t.get("state") == "trashed", json.dumps(t)[:120])
    else:
        skip("статьи и категории", "нет инструментов записи материалов")
    if "menus.item_create" in have:
        err, m = c.call("menus.list")
        mt = m["menus"][0]["menutype"] if not err and m.get("menus") else None
        if mt:
            err, t = c.call("menus.item_create", {"menutype": mt, "title": f"{P} ссылка", "kind": "url", "url": "https://example.com/qa"})
            if check("menus.item_create: черновик", not err and t.get("state") == "unpublished", json.dumps(t)[:120]):
                created["menu_items"].append(t["id"])
                prev, err, t2 = c.confirmed("menus.item_set_state", {"id": t["id"], "state": "published"})
                check("menus.item_set_state: опубликован", not err and t2.get("state") == "published", json.dumps(t2)[:120])
                prev, err, t2 = c.confirmed("menus.item_set_state", {"id": t["id"], "state": "trashed"})
                check("menus.item_set_state: в корзину", not err and t2.get("state") == "trashed", json.dumps(t2)[:120])
            err, its = c.call("menus.items", {"menutype": mt, "state": "all"})
            home = [i for i in its.get("items", []) if i.get("home")]
            if home:
                err, t = c.call("menus.item_set_state", {"id": home[0]["id"], "state": "unpublished"})
                check("главную страницу скрыть нельзя", err and t.get("error") == "forbidden", json.dumps(t)[:120])
    if "modules.create_custom" in have:
        err, pos = c.call("modules.positions")
        free = [x["position"] for x in pos.get("positions", []) if x.get("declared_by_template") and x.get("modules") == 0]
        position = free[0] if free else "main-top"
        err, t = c.call("modules.create_custom", {"title": f"{P} модуль", "position": position, "content": "<p>QAMOD-ONE</p>"})
        if check("modules.create_custom: черновик", not err and t.get("state") == "unpublished", json.dumps(t)[:120]):
            mid = t["id"]
            created["modules"].append(mid)
            prev, err, t2 = c.confirmed("modules.update", {"id": mid, "content": "<p>QAMOD-TWO</p>", "params": {"cache_time": 600}})
            check("modules.update: содержимое и параметры", not err and t2.get("updated"), json.dumps(t2)[:150])
            err, g = c.call("modules.get", {"id": mid})
            check("modules.get: обновление видно, привязка к страницам не потеряна", "QAMOD-TWO" in json.dumps(g) and g.get("shown_on", {}).get("assignment") == "all pages", json.dumps(g)[:160])
            prev, err, t2 = c.confirmed("modules.set_state", {"id": mid, "state": "trashed"})
            check("modules.set_state: в корзину", not err and t2.get("state") == "trashed", json.dumps(t2)[:120])

    section("Запись: магазин")
    if "products.update" in have:
        err, pr = c.call("products.search", {"limit": 1, "state": "all"})
        if not err and pr.get("items"):
            p = pr["items"][0]
            err, g = c.call("products.get", {"id": p["id"]})
            old = g.get("meta_description") or ""
            prev, err, t = c.confirmed("products.update", {"id": p["id"], "meta_description": f"{P} проверка"})
            check("products.update: " + ("сразу выполнено (полный доступ)" if MODE == "full_write" else "изменение с подтверждением"), (prev is not None) == (MODE != "full_write") and not err and t.get("updated"), json.dumps(t)[:150])
            err, g2 = c.call("products.get", {"id": p["id"]})
            check("products.update применился", g2.get("meta_description") == f"{P} проверка", json.dumps(g2)[:120])
            prev, err, t = c.confirmed("products.update", {"id": p["id"], "meta_description": old})
            check("products.update: исходное значение возвращено", not err and t.get("updated"), json.dumps(t)[:120])
        else:
            skip("products.update", "в магазине нет товаров")
    if "orders.set_status" in have:
        skip("orders.set_status", "меняет настоящие заказы и может отправлять письма: проверяется только предпросмотр")
        err, od = c.call("orders.list", {"limit": 1})
        if not err and od.get("items"):
            o = od["items"][0]
            err, t = c.call("orders.set_status", {"order_id": o["id"], "status_id": o["status"]["id"]})
            if MODE == "full_write":
                # Статус тот же, что и был: заказ не меняется, а подтверждение в этом режиме не требуется.
                check("orders.set_status: тот же статус ничего не меняет", not err and t.get("changed") is False, json.dumps(t)[:150])
            else:
                check("orders.set_status: без кода возвращает предпросмотр", t.get("status") == "confirmation_required" or err, json.dumps(t)[:150])

    section("Запись: шаблон")
    if "template.file_write" in have:
        path = "mcp-qa-test.css"
        body1 = "/* QA */\n.qa{color:red}\n"
        prev, err, t = c.confirmed("template.file_write", {"path": path, "content": body1})
        ok = check("template.file_write: новый файл", prev is not None and not err and t.get("written"), json.dumps(t)[:160])
        if ok:
            err, r = c.call("template.file_read", {"path": path})
            sha = r.get("sha256")
            check("template.file_read читает записанное", not err and "QA" in json.dumps(r))
            err, t = c.call("template.file_write", {"path": path, "content": "x"})
            check("перезапись без sha256 отклонена", err and t.get("error") == "validation", json.dumps(t)[:100])
            err, t = c.call("template.file_write", {"path": path, "content": "x", "expected_sha256": "00" * 32})
            check("перезапись с чужим sha256 отклонена", err and t.get("error") == "conflict", json.dumps(t)[:100])
            err, t = c.call("template.file_write", {"path": "mcp-qa-bad.php", "content": "<?php if ( {"})
            check("PHP с синтаксической ошибкой отклонён", err and "syntax" in json.dumps(t).lower(), json.dumps(t)[:120])
            err, t = c.call("template.file_write", {"path": "mcp-qa-bad.json", "content": "{oops"})
            check("битый JSON отклонён", err, json.dumps(t)[:100])
            prev, err, t = c.confirmed("template.file_write", {"path": path, "content": "/* QA 2 */\n", "expected_sha256": sha})
            check("template.file_write: перезапись с резервной копией", not err and t.get("backup_id"), json.dumps(t)[:150])
            bid = t.get("backup_id")
            if bid:
                err, b = c.call("template.file_backups", {"path": path})
                check("template.file_backups видит копию", any(x["backup_id"] == bid for x in b.get("backups", [])), json.dumps(b)[:120])
                prev, err, t = c.confirmed("template.file_restore", {"path": path, "backup_id": bid})
                check("template.file_restore: откат", not err and t.get("restored"), json.dumps(t)[:120])
                err, r = c.call("template.file_read", {"path": path})
                check("после отката содержимое прежнее", "QA" in json.dumps(r) and "QA 2" not in json.dumps(r))
            print(f"  ! тестовый файл templates/<шаблон>/{path} и его копии удалите вручную или скриптом dev/qa/cleanup_local.php")
        err, t = c.call("template.override_create", {"kind": "layout", "layout_path": "../../configuration"})
        check("template.override_create: путь с .. отклонён", err, json.dumps(t)[:100])
    else:
        skip("шаблон", "набор выключен или нет области")

    section("Запись: система")
    if "robots.set" in have:
        err, r = c.call("robots.get")
        if not err:
            sha = r.get("sha256")
            body = r.get("content", {})
            text = body.get("user_content", "") if isinstance(body, dict) else ""
            prev, err, t = c.confirmed("robots.set", {"content": text + "\n# QA\n", "expected_sha256": sha} if sha else {"content": "# QA\n"})
            check("robots.set: запись с подтверждением", prev is not None and not err and t.get("written"), json.dumps(t)[:150])
            if t.get("backup_id") or not sha:
                err, r2 = c.call("robots.get")
                prev, err, t2 = c.confirmed("robots.set", {"restore_backup_id": t["backup_id"], "expected_sha256": r2.get("sha256")}) if t.get("backup_id") else (None, True, {})
                check("robots.set: восстановление из копии", not err and t2.get("written"), json.dumps(t2)[:120])
                err, r3 = c.call("robots.get")
                check("robots.txt вернулся к исходному", r3.get("sha256") == sha, "")
    if "cache.clear" in have:
        prev, err, t = c.confirmed("cache.clear", {})
        check("cache.clear", not err and t.get("cleared"), json.dumps(t)[:120])
    if "system.config_set" in have:
        err, t = c.call("system.config_set", {"values": {"secret": "x"}})
        check("system.config_set: запрещённый ключ отклонён", err, json.dumps(t)[:100])
        err, t = c.call("system.config_set", {"values": {"offline": "5"}})
        check("system.config_set: неверное значение отклонено", err, json.dumps(t)[:100])
    if "plugins.set_state" in have:
        err, pl = c.call("plugins.list", {"group": "authentication"})
        if pl.get("items"):
            err, t = c.call("plugins.set_state", {"id": pl["items"][0]["id"], "enabled": False})
            check("plugins.set_state: группа authentication защищена", err and t.get("error") == "forbidden", json.dumps(t)[:100])
    if "db.query" in have:
        for tab in created["articles"]:
            pass


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--url", required=True)
    ap.add_argument("--write", action="store_true")
    ap.add_argument("--insecure", action="store_true")
    ap.add_argument("--rate", action="store_true")
    ap.add_argument("--ro-token", default="")
    ap.add_argument("--prefix", default="QA-MCP")
    sys.exit(run(ap.parse_args()))


if __name__ == "__main__":
    main()
