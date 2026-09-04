-- Stonefellow v90 — AI-native editing + complete edit ledger
CREATE TABLE IF NOT EXISTS agent_edit_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  conversation_id BIGINT UNSIGNED NULL,
  editor_kind VARCHAR(30) NOT NULL,
  project_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  session_key VARCHAR(100) NOT NULL DEFAULT '',
  source_kind VARCHAR(20) NOT NULL DEFAULT 'manual',
  action_key VARCHAR(100) NOT NULL DEFAULT 'edit',
  request_text TEXT NULL,
  model_provider VARCHAR(30) NOT NULL DEFAULT '',
  model_name VARCHAR(100) NOT NULL DEFAULT '',
  playhead_seconds DECIMAL(12,4) NULL,
  before_json LONGTEXT NULL,
  after_json LONGTEXT NULL,
  changes_json LONGTEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_agent_edit_user_created (user_id, created_at, id),
  INDEX idx_agent_edit_project (editor_kind, project_id, created_at, id),
  INDEX idx_agent_edit_session (session_key, created_at, id),
  INDEX idx_agent_edit_source (source_kind, created_at),
  CONSTRAINT fk_agent_edit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
