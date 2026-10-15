import { Client } from "@modelcontextprotocol/sdk/client/index.js";
import { StreamableHTTPClientTransport } from "@modelcontextprotocol/sdk/client/streamableHttp.js";
// Проверка сервера официальной клиентской библиотекой MCP (она строго разбирает ответы по схемам протокола).
//   mkdir /tmp/sdk && cd /tmp/sdk && npm init -y && npm i @modelcontextprotocol/sdk
//   MCP_TOKEN=dhmcp_... node /путь/к/dev/sdk-check.mjs https://ваш-сайт/mcp
// Только чтение и предпросмотр: ничего не меняет. Токен — только в переменной окружения.
const token = process.env.MCP_TOKEN;
if (!token || !process.argv[2]) { console.error("Нужны MCP_TOKEN и адрес сервера"); process.exit(1); }
const url = new URL(process.argv[2]);
const client = new Client({ name: "sdk-check", version: "1.0.0" });
const transport = new StreamableHTTPClientTransport(url, { requestInit: { headers: { Authorization: `Bearer ${token}` } } });
try {
  await client.connect(transport);
  const info = client.getServerVersion();
  console.log("connect ok:", info.name, info.version, "| протокол:", transport.protocolVersion ?? "?");
  console.log("instructions:", (client.getInstructions() ?? "").slice(0, 90) + "…");
  const { tools } = await client.listTools();
  console.log("listTools ok:", tools.length, "инструментов");
  const bad = tools.filter(t => !t.inputSchema || t.inputSchema.type !== "object");
  console.log("схем с type!=object:", bad.length);
  const ro = tools.filter(t => t.annotations?.readOnlyHint).length, dest = tools.filter(t => t.annotations?.destructiveHint).length;
  console.log("аннотации: readOnly", ro, "| destructive", dest, "| со всеми title:", tools.every(t => t.title));
  const ping = await client.callTool({ name: "ping", arguments: {} });
  console.log("callTool ping ok, structuredContent:", JSON.stringify(ping.structuredContent).slice(0, 110));
  const info2 = await client.callTool({ name: "shop.info", arguments: {} });
  console.log("callTool shop.info:", info2.isError ? "ошибка" : "ok", "| товаров:", info2.structuredContent?.published_products);
  const art = await client.callTool({ name: "articles.list", arguments: { limit: 2, state: "all" } });
  console.log("articles.list total:", art.structuredContent?.total);
  const bad2 = await client.callTool({ name: "articles.get", arguments: { id: 999999 } });
  console.log("несуществующая статья → isError:", bad2.isError, "|", bad2.content[0].text.slice(0, 80));
  // Вызов записи без кода подтверждения только возвращает предпросмотр и ничего не меняет.
  const prev = await client.callTool({ name: "articles.set_state", arguments: { id: 999999, state: "published" } });
  const pj = JSON.parse(prev.content[0].text);
  console.log("запись без подтверждения →", pj.status ?? pj.error);
  await client.close(); console.log("ГОТОВО: SDK принял все ответы без ошибок разбора");
} catch (e) { console.log("ОШИБКА SDK:", e.constructor.name, "-", String(e.message).slice(0, 300)); process.exit(1); }
