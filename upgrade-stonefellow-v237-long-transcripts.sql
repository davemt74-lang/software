CREATE TABLE IF NOT EXISTS artist_transcript_page_analysis_v237 (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  session_id BIGINT UNSIGNED NOT NULL,
  page_number INT UNSIGNED NOT NULL,
  source_hash CHAR(64) NOT NULL,
  source_word_count INT UNSIGNED NOT NULL DEFAULT 0,
  start_segment_index INT UNSIGNED NOT NULL DEFAULT 0,
  end_segment_index INT UNSIGNED NOT NULL DEFAULT 0,
  analysis_json LONGTEXT NOT NULL,
  provider VARCHAR(32) NOT NULL DEFAULT '',
  model VARCHAR(160) NOT NULL DEFAULT '',
  generated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_artist_transcript_page_analysis_v237 (session_id,page_number),
  INDEX idx_artist_transcript_page_analysis_hash_v237 (session_id,source_hash),
  CONSTRAINT fk_artist_transcript_page_analysis_v237
    FOREIGN KEY (session_id) REFERENCES artist_transcript_sessions_v172(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS artist_transcript_master_analysis_v237 (
  session_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  source_hash CHAR(64) NOT NULL,
  source_word_count INT UNSIGNED NOT NULL DEFAULT 0,
  page_count INT UNSIGNED NOT NULL DEFAULT 0,
  analyzed_page_count INT UNSIGNED NOT NULL DEFAULT 0,
  analysis_json LONGTEXT NOT NULL,
  research_json LONGTEXT NULL,
  provider VARCHAR(32) NOT NULL DEFAULT '',
  model VARCHAR(160) NOT NULL DEFAULT '',
  generated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_artist_transcript_master_analysis_v237
    FOREIGN KEY (session_id) REFERENCES artist_transcript_sessions_v172(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
