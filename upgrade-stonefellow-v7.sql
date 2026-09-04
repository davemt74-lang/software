-- Stonefellow v7 upgrade
-- Adds profile image delivery support (files only), listening analytics,
-- lyrics, supervisor song notes, and song-specific knowledge linking.

SET @lyrics_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tracks' AND COLUMN_NAME='lyrics'
);
SET @sql := IF(
  @lyrics_exists=0,
  "ALTER TABLE tracks ADD COLUMN lyrics LONGTEXT NOT NULL AFTER duration",
  "SELECT 'tracks.lyrics already exists'"
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @track_id_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='knowledge_items' AND COLUMN_NAME='track_id'
);
SET @sql := IF(
  @track_id_exists=0,
  "ALTER TABLE knowledge_items ADD COLUMN track_id INT UNSIGNED NULL AFTER id",
  "SELECT 'knowledge_items.track_id already exists'"
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @track_idx_exists := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='knowledge_items' AND INDEX_NAME='idx_knowledge_track'
);
SET @sql := IF(
  @track_idx_exists=0,
  "ALTER TABLE knowledge_items ADD INDEX idx_knowledge_track (track_id)",
  "SELECT 'idx_knowledge_track already exists'"
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS track_notes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  track_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  note TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_track_notes_track (track_id, created_at),
  INDEX idx_track_notes_user (user_id),
  CONSTRAINT fk_track_notes_track FOREIGN KEY (track_id) REFERENCES tracks(id) ON DELETE CASCADE,
  CONSTRAINT fk_track_notes_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS track_play_sessions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  session_token CHAR(64) NOT NULL UNIQUE,
  track_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NULL,
  listener_hash CHAR(64) NOT NULL,
  device_type VARCHAR(30) NOT NULL DEFAULT 'unknown',
  referrer_host VARCHAR(190) NOT NULL DEFAULT '',
  started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_event_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ended_at DATETIME NULL,
  listened_seconds DECIMAL(12,2) NOT NULL DEFAULT 0,
  last_position_seconds DECIMAL(12,2) NOT NULL DEFAULT 0,
  max_position_seconds DECIMAL(12,2) NOT NULL DEFAULT 0,
  duration_seconds DECIMAL(12,2) NOT NULL DEFAULT 0,
  completion_percent DECIMAL(7,2) NOT NULL DEFAULT 0,
  qualified_play TINYINT(1) NOT NULL DEFAULT 0,
  completed TINYINT(1) NOT NULL DEFAULT 0,
  INDEX idx_play_track_started (track_id, started_at),
  INDEX idx_play_user_started (user_id, started_at),
  INDEX idx_play_listener_started (listener_hash, started_at),
  CONSTRAINT fk_play_session_track FOREIGN KEY (track_id) REFERENCES tracks(id) ON DELETE CASCADE,
  CONSTRAINT fk_play_session_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS track_play_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  session_id BIGINT UNSIGNED NOT NULL,
  event_type VARCHAR(30) NOT NULL,
  position_seconds DECIMAL(12,2) NOT NULL DEFAULT 0,
  listened_delta_seconds DECIMAL(8,2) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_play_events_session (session_id, id),
  INDEX idx_play_events_type_time (event_type, created_at),
  CONSTRAINT fk_play_events_session FOREIGN KEY (session_id) REFERENCES track_play_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permissions
(permission_key,label,description,category,sort_order)
VALUES
('listening.view','Listening Analytics','View detailed media play and listening statistics.','Analytics',35),
('track_notes.manage','Supervisor Song Notes','Add and manage private supervisor notes on songs.','Content',38)
ON DUPLICATE KEY UPDATE
  label=VALUES(label),
  description=VALUES(description),
  category=VALUES(category),
  sort_order=VALUES(sort_order);

INSERT IGNORE INTO role_permissions (role,permission_key) VALUES
('manager','listening.view'),
('supervisor','listening.view'),
('supervisor','track_notes.manage'),
('admin','listening.view'),
('admin','track_notes.manage');
