ALTER TABLE `#__dharma_mcp_tokens` ADD COLUMN `kind` varchar(16) NOT NULL DEFAULT 'manual' AFTER `title`;

ALTER TABLE `#__dharma_mcp_tokens` ADD COLUMN `client_id` varchar(64) NULL AFTER `kind`;

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
  `client_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `user_id` int unsigned NOT NULL,
  `scopes` text NOT NULL,
  `expires_at` datetime NOT NULL,
  `created` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_refresh` (`refresh_hash`),
  KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
