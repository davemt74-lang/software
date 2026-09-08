-- VP3 v0.18 HomeServer Agent execution continuity
-- Safe to run more than once.

CREATE TABLE IF NOT EXISTS homeserver_chat_sessions (
  user_id INT UNSIGNED NOT NULL,
  vp3_conversation_id BIGINT UNSIGNED NOT NULL,
  homeserver_conversation_id VARCHAR(160) NOT NULL,
  last_provider VARCHAR(80) NOT NULL DEFAULT '',
  last_model VARCHAR(160) NOT NULL DEFAULT '',
  last_compute_source VARCHAR(80) NOT NULL DEFAULT '',
  last_run_id BIGINT UNSIGNED NULL,
  last_used_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, vp3_conversation_id),
  INDEX idx_homeserver_chat_session_remote (homeserver_conversation_id),
  CONSTRAINT fk_homeserver_chat_session_user
    FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
