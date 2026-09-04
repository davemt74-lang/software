-- Stonefellow v93 — proactive Agent Brain opportunity feedback
CREATE TABLE IF NOT EXISTS agent_proactive_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  suggestion_hash CHAR(40) NOT NULL,
  surface VARCHAR(30) NOT NULL DEFAULT 'chat',
  event_type VARCHAR(20) NOT NULL,
  title VARCHAR(190) NOT NULL DEFAULT '',
  prompt VARCHAR(1000) NOT NULL DEFAULT '',
  source_kind VARCHAR(60) NOT NULL DEFAULT '',
  context_json LONGTEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_agent_proactive_user_created (user_id,created_at,id),
  INDEX idx_agent_proactive_hash_event (user_id,suggestion_hash,event_type,created_at),
  INDEX idx_agent_proactive_surface (user_id,surface,created_at),
  CONSTRAINT fk_agent_proactive_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
