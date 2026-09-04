-- Stonefellow v172 — Artist Listening and Passive Transcription
-- One-time standalone migration. /upgrade.php remains the idempotent upgrade path.

CREATE TABLE IF NOT EXISTS artist_transcript_sessions_v172 (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  owner_user_id INT UNSIGNED NOT NULL,
  created_by_user_id INT UNSIGNED NOT NULL,
  conversation_id BIGINT UNSIGNED NULL,
  client_session_key CHAR(64) NOT NULL,
  title VARCHAR(190) NOT NULL DEFAULT 'Untitled transcript',
  status VARCHAR(20) NOT NULL DEFAULT 'active',
  language VARCHAR(20) NOT NULL DEFAULT 'en-US',
  duration_ms BIGINT UNSIGNED NOT NULL DEFAULT 0,
  knowledge_id INT UNSIGNED NULL,
  agent_memory_id BIGINT UNSIGNED NULL,
  project_note_id BIGINT UNSIGNED NULL,
  project_track_id INT UNSIGNED NULL,
  metadata_json LONGTEXT NULL,
  started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  stopped_at DATETIME NULL,
  discarded_at DATETIME NULL,
  last_activity_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_artist_transcript_client (created_by_user_id, client_session_key),
  INDEX idx_artist_transcript_creator_status (created_by_user_id, status, last_activity_at, id),
  INDEX idx_artist_transcript_owner_updated (owner_user_id, updated_at, id),
  INDEX idx_artist_transcript_conversation (conversation_id, id),
  CONSTRAINT fk_artist_transcript_owner_v172
    FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_artist_transcript_creator_v172
    FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_artist_transcript_conversation_v172
    FOREIGN KEY (conversation_id) REFERENCES chat_conversations(id) ON DELETE SET NULL,
  CONSTRAINT fk_artist_transcript_knowledge_v172
    FOREIGN KEY (knowledge_id) REFERENCES knowledge_items(id) ON DELETE SET NULL,
  CONSTRAINT fk_artist_transcript_track_v172
    FOREIGN KEY (project_track_id) REFERENCES tracks(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS artist_transcript_segments_v172 (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  session_id BIGINT UNSIGNED NOT NULL,
  client_segment_key CHAR(64) NOT NULL,
  segment_index INT UNSIGNED NOT NULL,
  segment_type VARCHAR(20) NOT NULL DEFAULT 'transcript',
  speaker_label VARCHAR(80) NOT NULL DEFAULT 'Speaker 1',
  transcript_text TEXT NOT NULL,
  started_ms BIGINT UNSIGNED NOT NULL DEFAULT 0,
  ended_ms BIGINT UNSIGNED NOT NULL DEFAULT 0,
  confidence DECIMAL(5,4) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_artist_transcript_segment_client (session_id, client_segment_key),
  UNIQUE KEY uq_artist_transcript_segment_order (session_id, segment_index),
  INDEX idx_artist_transcript_segment_time (session_id, started_ms, id),
  CONSTRAINT fk_artist_transcript_segment_session_v172
    FOREIGN KEY (session_id) REFERENCES artist_transcript_sessions_v172(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS artist_transcript_folders_v177 (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  created_by_user_id INT UNSIGNED NOT NULL,
  folder_name VARCHAR(80) NOT NULL,
  sort_order INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_artist_transcript_folder_name_v177 (created_by_user_id,folder_name),
  INDEX idx_artist_transcript_folder_user_v177 (created_by_user_id,sort_order,id),
  CONSTRAINT fk_artist_transcript_folder_user_v177
    FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
