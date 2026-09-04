-- Stonefellow v82 — Agent Brain + conversation archive
CREATE TABLE IF NOT EXISTS agent_chat_archive (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  conversation_id BIGINT UNSIGNED NULL,
  source_message_id BIGINT UNSIGNED NOT NULL,
  role VARCHAR(20) NOT NULL,
  input_mode VARCHAR(20) NOT NULL DEFAULT 'text',
  message_text LONGTEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  archived_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_agent_archive_source (user_id, source_message_id),
  INDEX idx_agent_archive_user_created (user_id, created_at, id),
  INDEX idx_agent_archive_conversation (user_id, conversation_id, id),
  CONSTRAINT fk_agent_archive_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS agent_memory_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  memory_type VARCHAR(40) NOT NULL,
  subject VARCHAR(190) NOT NULL,
  memory_text TEXT NOT NULL,
  memory_hash CHAR(40) NOT NULL,
  source_archive_id BIGINT UNSIGNED NULL,
  confidence DECIMAL(4,3) NOT NULL DEFAULT 0.750,
  occurrence_count INT UNSIGNED NOT NULL DEFAULT 1,
  first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  metadata_json LONGTEXT NULL,
  UNIQUE KEY uq_agent_memory_hash (user_id, memory_hash),
  INDEX idx_agent_memory_type (user_id, memory_type, is_active, last_seen_at),
  INDEX idx_agent_memory_occurrence (user_id, occurrence_count, last_seen_at),
  CONSTRAINT fk_agent_memory_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_agent_memory_archive FOREIGN KEY (source_archive_id) REFERENCES agent_chat_archive(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
