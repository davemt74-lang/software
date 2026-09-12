-- VP3 User Calendar v13.00
-- Canonical personal/Agent/automation events. Scheduling bookings remain in agent_scheduling_bookings.

CREATE TABLE IF NOT EXISTS user_calendar_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  owner_user_id INT UNSIGNED NOT NULL,
  created_by_user_id INT UNSIGNED NULL,
  created_by_agent_id BIGINT UNSIGNED NULL,
  title VARCHAR(190) NOT NULL,
  description TEXT NULL,
  location VARCHAR(500) NOT NULL DEFAULT '',
  start_at_utc DATETIME NOT NULL,
  end_at_utc DATETIME NOT NULL,
  timezone VARCHAR(80) NOT NULL DEFAULT 'UTC',
  all_day TINYINT(1) NOT NULL DEFAULT 0,
  source VARCHAR(32) NOT NULL DEFAULT 'user',
  source_reference VARCHAR(190) NOT NULL DEFAULT '',
  status VARCHAR(24) NOT NULL DEFAULT 'active',
  cancelled_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_user_calendar_owner_range (owner_user_id,status,start_at_utc,end_at_utc,id),
  INDEX idx_user_calendar_source_ref (owner_user_id,source,source_reference),
  INDEX idx_user_calendar_agent (created_by_agent_id,status,start_at_utc,id),
  CONSTRAINT fk_user_calendar_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_user_calendar_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_user_calendar_agent FOREIGN KEY (created_by_agent_id) REFERENCES user_agents(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
