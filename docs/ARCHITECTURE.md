# Архитектура Dharma Shop MCP (`com_dharmashop_mcp`)

## 1. Назначение и границы

Компонент `com_dharmashop_mcp` предоставляет стандартный интерфейс **Model Context Protocol (MCP)** для интернет-магазина Dharma Shop на CMS Joomla! 6+.

Компонент полностью отделён от ядра `com_dharmashop` и выступает в роли защищённого шлюза API между внешними ИИ-ассистентами (клиентами) и сервисами интернет-магазина.

---

## 2. Схема базы данных

Таблицы создаются независимо с префиксом `#__dharmashop_mcp_*`.

```sql
-- Таблица токенов доступа
CREATE TABLE IF NOT EXISTS `#__dharmashop_mcp_tokens` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `user_id` int unsigned NOT NULL,
  `token_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `token_prefix` varchar(16) NOT NULL,
  `scopes` text NOT NULL,
  `state` tinyint NOT NULL DEFAULT 1,
  `expires_at` datetime NULL,
  `last_used_at` datetime NULL,
  `last_used_ip` varchar(45) NULL,
  `requests_count` int unsigned NOT NULL DEFAULT 0,
  `created` datetime NOT NULL,
  `created_by` int unsigned NOT NULL DEFAULT 0,
  `params` text NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_token_hash` (`token_hash`),
  KEY `idx_user_state` (`user_id`, `state`),
  KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Журнал аудита вызовов инструментов
CREATE TABLE IF NOT EXISTS `#__dharmashop_mcp_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `token_id` int unsigned NULL,
  `user_id` int unsigned NULL,
  `method` varchar(64) NOT NULL,
  `tool_name` varchar(64) NULL,
  `arguments_json` mediumtext NULL,
  `status` varchar(20) NOT NULL DEFAULT 'ok',
  `duration_ms` int unsigned NOT NULL DEFAULT 0,
  `error_message` varchar(500) NULL,
  `ip` varchar(45) NULL,
  `user_agent` varchar(255) NULL,
  `created` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_token_created` (`token_id`, `created`),
  KEY `idx_created` (`created`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Таблица отложенных подтверждений для опасных операций
CREATE TABLE IF NOT EXISTS `#__dharmashop_mcp_confirmations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `token_id` int unsigned NOT NULL,
  `tool_name` varchar(64) NOT NULL,
  `arguments_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `payload_json` mediumtext NOT NULL,
  `confirm_code` varchar(32) NOT NULL,
  `expires_at` datetime NOT NULL,
  `created` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_code` (`confirm_code`),
  KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

## 3. Модель полномочий и скоупы (`scopes`)

Каждый токен наделяется набором разрешений:

| Скоуп | Описание |
|---|---|
| `shop:read` | Базовый доступ на чтение информации о магазине (`ping`, `shop.info`) |
| `catalog:read` | Чтение товаров, категорий, цен, остатков, характеристик |
| `orders:read` | Просмотр заказов, статусов, истории без PII |
| `analytics:read` | Доступ к аналитическим сводкам (продажи, средний чек, топ) |
| `drafts:write` | Создание и редактирование черновиков (товары, описания, SEO) |
| `orders:write` | Смена статусов заказов, добавление административных заметок |
| `catalog:write` | Изменение цен, остатков, публикация товаров |
| `customers:pii` | Доступ к персональным данным (ФИО, телефон, адрес, email) |
| `maintenance:exec`| Запуск команд обслуживания (переиндексация, очистка кэша) |

---

## 4. Архитектура обработки запроса

```
Client (Claude / ChatGPT) 
    │ HTTPS POST (JSON-RPC 2.0) + Bearer Token
    ▼
/index.php?option=com_dharmashop_mcp&format=json
    │
    ├─ 1. Authenticator: извлечение Bearer токена, проверка SHA-256 хэша в #__dharmashop_mcp_tokens,
    │                    проверка срока действия и флага активности (state=1).
    │
    ├─ 2. User Context: загрузка субъекта пользователя Joomla (new User($token->user_id)),
    │                   проверка глобальных прав Joomla ACL.
    │
    ├─ 3. MCP Router: валидация JSON-RPC 2.0, диспетчеризация метода:
    │     ├── `initialize` -> версия протокола, возможности сервера
    │     ├── `ping`       -> pong
    │     ├── `tools/list` -> фильтрация инструментов по скоупам токена и ACL пользователя
    │     └── `tools/call` -> проверка скоупа, проверка двухэтапного подтверждения (если destructive),
    │                         вызов Handler через сервисы DharmaShop.
    │
    ├─ 4. DharmaShop Services: обращение к ShopServices, OrderStatusService, моделям каталога.
    │                          Никакого прямого SQL!
    │
    ├─ 5. Response Sanitize: оборачивание пользовательских данных в `user_content`, маскирование PII.
    │
    └─ 6. Logger: асинхронная запись вызова в #__dharmashop_mcp_logs (параметры, время, результат).
```
