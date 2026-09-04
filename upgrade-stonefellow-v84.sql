-- Stonefellow v84: Agent Brain executable tools, Studio Agent history, Booking Agent and listener geography
SET @db := DATABASE();

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='shows' AND COLUMN_NAME='owner_user_id')=0,
 'ALTER TABLE shows ADD COLUMN owner_user_id INT UNSIGNED NULL AFTER id, ADD INDEX idx_shows_owner_date (owner_user_id,show_date)', 'SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='track_play_sessions' AND COLUMN_NAME='listener_city')=0,
 "ALTER TABLE track_play_sessions ADD COLUMN listener_city VARCHAR(120) NOT NULL DEFAULT '', ADD COLUMN listener_region VARCHAR(120) NOT NULL DEFAULT '', ADD COLUMN listener_country VARCHAR(80) NOT NULL DEFAULT '', ADD COLUMN listener_latitude DECIMAL(9,6) NULL, ADD COLUMN listener_longitude DECIMAL(9,6) NULL, ADD INDEX idx_play_location (listener_country,listener_region,listener_city,started_at)", 'SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

CREATE TABLE IF NOT EXISTS agent_tool_history (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id INT UNSIGNED NOT NULL,conversation_id BIGINT UNSIGNED NULL,tool_key VARCHAR(80) NOT NULL,request_text TEXT NOT NULL,status VARCHAR(30) NOT NULL,result_json LONGTEXT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_agent_tool_user_created(user_id,created_at,id),INDEX idx_agent_tool_key(tool_key,created_at),CONSTRAINT fk_agent_tool_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS agent_studio_sessions (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id INT UNSIGNED NOT NULL,track_id INT UNSIGNED NOT NULL,status VARCHAR(30) NOT NULL DEFAULT 'active',started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,last_activity_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_agent_studio_user(user_id,last_activity_at),INDEX idx_agent_studio_track(track_id,last_activity_at),CONSTRAINT fk_agent_studio_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,CONSTRAINT fk_agent_studio_track FOREIGN KEY(track_id) REFERENCES tracks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS agent_studio_history (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,session_id BIGINT UNSIGNED NOT NULL,user_id INT UNSIGNED NOT NULL,role VARCHAR(20) NOT NULL,message_text TEXT NOT NULL,command_json LONGTEXT NULL,status VARCHAR(30) NOT NULL DEFAULT 'complete',result_text TEXT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_agent_studio_history(session_id,id),CONSTRAINT fk_agent_studio_history_session FOREIGN KEY(session_id) REFERENCES agent_studio_sessions(id) ON DELETE CASCADE,CONSTRAINT fk_agent_studio_history_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS booking_agent_research (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id INT UNSIGNED NOT NULL,query_text VARCHAR(500) NOT NULL,market_label VARCHAR(190) NOT NULL DEFAULT '',result_json LONGTEXT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_booking_research_user(user_id,created_at,id),CONSTRAINT fk_booking_research_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS booking_agent_opportunities (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id INT UNSIGNED NOT NULL,title VARCHAR(190) NOT NULL,venue VARCHAR(190) NOT NULL DEFAULT '',city VARCHAR(120) NOT NULL DEFAULT '',region VARCHAR(120) NOT NULL DEFAULT '',source_url VARCHAR(700) NOT NULL DEFAULT '',status VARCHAR(30) NOT NULL DEFAULT 'lead',notes TEXT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 INDEX idx_booking_opportunity_user_status(user_id,status,updated_at),CONSTRAINT fk_booking_opportunity_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
