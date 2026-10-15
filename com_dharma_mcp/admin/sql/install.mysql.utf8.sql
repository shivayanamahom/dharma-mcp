CREATE TABLE IF NOT EXISTS `#__dharma_mcp_tokens` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `kind` varchar(16) NOT NULL DEFAULT 'manual',
  `client_id` varchar(64) NULL,
  `grant_id` varchar(32) NULL,
  `user_id` int unsigned NOT NULL,
  `token_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `token_prefix` varchar(32) NOT NULL,
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
  KEY `idx_expires` (`expires_at`),
  KEY `idx_grant` (`grant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__dharma_mcp_logs` (
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

CREATE TABLE IF NOT EXISTS `#__dharma_mcp_confirmations` (
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

CREATE TABLE IF NOT EXISTS `#__dharma_mcp_oauth_clients` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `client_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `client_name` varchar(255) NOT NULL,
  `redirect_uris` text NOT NULL,
  `created` datetime NOT NULL,
  `last_used` datetime NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_client` (`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__dharma_mcp_oauth_codes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `code_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `client_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `user_id` int unsigned NOT NULL,
  `scopes` text NOT NULL,
  `redirect_uri` varchar(2000) NOT NULL,
  `code_challenge` varchar(128) NOT NULL,
  `expires_at` datetime NOT NULL,
  `created` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_code` (`code_hash`),
  KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__dharma_mcp_oauth_refresh` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `refresh_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `grant_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `client_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `user_id` int unsigned NOT NULL,
  `scopes` text NOT NULL,
  `expires_at` datetime NOT NULL,
  `created` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_refresh` (`refresh_hash`),
  KEY `idx_grant` (`grant_id`),
  KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
