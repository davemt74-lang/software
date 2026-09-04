-- Stonefellow Voice Profile private sample storage
CREATE TABLE IF NOT EXISTS studio_voice_samples (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  owner_user_id INT UNSIGNED NOT NULL,
  participant_id BIGINT UNSIGNED NOT NULL,
  sample_key CHAR(32) NOT NULL,
  file_name VARCHAR(96) NOT NULL,
  mime_type VARCHAR(80) NOT NULL,
  file_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
  duration_ms BIGINT UNSIGNED NOT NULL DEFAULT 0,
  source_type VARCHAR(20) NOT NULL DEFAULT 'upload',
  sample_status VARCHAR(20) NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_studio_voice_sample_key (owner_user_id, sample_key),
  INDEX idx_studio_voice_sample_owner (owner_user_id, sample_status, created_at, id),
  INDEX idx_studio_voice_sample_participant (participant_id, sample_status, created_at, id),
  CONSTRAINT fk_studio_voice_sample_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_studio_voice_sample_participant FOREIGN KEY (participant_id) REFERENCES studio_participants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
