-- VP3 HomeServer cloud integration
-- Safe to run more than once.

CREATE TABLE IF NOT EXISTS homeserver_connections (
  user_id INT UNSIGNED NOT NULL PRIMARY KEY,
  device_id VARCHAR(100) NOT NULL DEFAULT '',
  relay_token_enc LONGTEXT NULL,
  homeserver_token_enc LONGTEXT NULL,
  pending_request_id VARCHAR(128) NOT NULL DEFAULT '',
  pending_claim_token_enc LONGTEXT NULL,
  pending_code VARCHAR(40) NOT NULL DEFAULT '',
  status VARCHAR(30) NOT NULL DEFAULT 'unpaired',
  installed_version VARCHAR(64) NOT NULL DEFAULT '',
  last_seen_at DATETIME NULL,
  last_checked_at DATETIME NULL,
  last_error VARCHAR(500) NOT NULL DEFAULT '',
  capabilities_json LONGTEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_homeserver_connection_status (status, last_seen_at),
  CONSTRAINT fk_homeserver_connection_user
    FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS homeserver_releases (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  version VARCHAR(64) NOT NULL,
  channel VARCHAR(20) NOT NULL DEFAULT 'stable',
  release_notes TEXT NOT NULL,
  portable_name VARCHAR(255) NOT NULL DEFAULT '',
  portable_path VARCHAR(500) NOT NULL DEFAULT '',
  portable_sha256 CHAR(64) NOT NULL DEFAULT '',
  portable_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
  installer_name VARCHAR(255) NOT NULL DEFAULT '',
  installer_path VARCHAR(500) NOT NULL DEFAULT '',
  installer_sha256 CHAR(64) NOT NULL DEFAULT '',
  installer_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
  is_published TINYINT(1) NOT NULL DEFAULT 0,
  is_latest TINYINT(1) NOT NULL DEFAULT 0,
  created_by_user_id INT UNSIGNED NULL,
  published_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_homeserver_release_version_channel (version, channel),
  INDEX idx_homeserver_release_current (channel, is_published, is_latest, id),
  CONSTRAINT fk_homeserver_release_creator
    FOREIGN KEY (created_by_user_id) REFERENCES users(id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
