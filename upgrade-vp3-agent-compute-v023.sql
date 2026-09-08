CREATE TABLE IF NOT EXISTS agent_compute_overrides (
  user_id INT UNSIGNED NOT NULL,
  agent_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  preference VARCHAR(24) NOT NULL DEFAULT 'inherit',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id,agent_id),
  INDEX idx_agent_compute_override_agent (agent_id),
  CONSTRAINT fk_agent_compute_override_user
    FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
