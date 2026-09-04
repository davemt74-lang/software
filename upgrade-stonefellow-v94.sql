-- Stonefellow v94 — Agent activity awareness
CREATE TABLE IF NOT EXISTS agent_activity_state (
  user_id INT UNSIGNED NOT NULL PRIMARY KEY,
  surface VARCHAR(30) NOT NULL DEFAULT 'chat',
  context_key VARCHAR(120) NOT NULL DEFAULT '',
  task_kind VARCHAR(40) NOT NULL DEFAULT '',
  task_title VARCHAR(190) NOT NULL DEFAULT '',
  activity_state VARCHAR(20) NOT NULL DEFAULT 'idle',
  last_input_at DATETIME NULL,
  last_task_at DATETIME NULL,
  last_heartbeat_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  idle_since_at DATETIME NULL,
  details_json LONGTEXT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_agent_activity_state (activity_state,last_heartbeat_at),
  CONSTRAINT fk_agent_activity_state_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS agent_activity_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  surface VARCHAR(30) NOT NULL DEFAULT 'chat',
  context_key VARCHAR(120) NOT NULL DEFAULT '',
  task_kind VARCHAR(40) NOT NULL DEFAULT '',
  task_title VARCHAR(190) NOT NULL DEFAULT '',
  previous_state VARCHAR(20) NOT NULL DEFAULT '',
  activity_state VARCHAR(20) NOT NULL,
  reason VARCHAR(120) NOT NULL DEFAULT '',
  details_json LONGTEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_agent_activity_user_created (user_id,created_at,id),
  INDEX idx_agent_activity_context (user_id,context_key,created_at,id),
  INDEX idx_agent_activity_transition (user_id,activity_state,created_at),
  CONSTRAINT fk_agent_activity_event_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
