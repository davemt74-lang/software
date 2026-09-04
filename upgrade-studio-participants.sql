-- Stonefellow Studio participant / voice identity foundation
-- Recognition identity and voice cloning are intentionally separate capabilities.

CREATE TABLE IF NOT EXISTS studio_participants (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  owner_user_id INT UNSIGNED NOT NULL,
  linked_user_id INT UNSIGNED NULL,
  profile_key CHAR(32) NOT NULL,
  display_name VARCHAR(120) NOT NULL,
  relationship_scope VARCHAR(30) NOT NULL DEFAULT 'guest',
  recognition_scope VARCHAR(30) NOT NULL DEFAULT 'private',
  recognition_consent TINYINT(1) NOT NULL DEFAULT 0,
  cloning_consent TINYINT(1) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  consent_updated_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_studio_participant_profile (owner_user_id, profile_key),
  UNIQUE KEY uq_studio_participant_link (owner_user_id, linked_user_id),
  INDEX idx_studio_participant_active (owner_user_id, is_active, updated_at),
  CONSTRAINT fk_studio_participant_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_studio_participant_linked FOREIGN KEY (linked_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS studio_participant_voices (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  owner_user_id INT UNSIGNED NOT NULL,
  participant_id BIGINT UNSIGNED NOT NULL,
  provider VARCHAR(30) NOT NULL DEFAULT 'elevenlabs',
  recognition_provider_speaker_id VARCHAR(190) NOT NULL DEFAULT '',
  clone_provider_voice_id VARCHAR(190) NOT NULL DEFAULT '',
  source_session_id BIGINT UNSIGNED NULL,
  source_recording_key VARCHAR(64) NOT NULL DEFAULT '',
  recognition_enabled TINYINT(1) NOT NULL DEFAULT 0,
  clone_enabled TINYINT(1) NOT NULL DEFAULT 0,
  recognition_verified TINYINT(1) NOT NULL DEFAULT 0,
  clone_verified TINYINT(1) NOT NULL DEFAULT 0,
  consent_snapshot_at DATETIME NULL,
  revoked_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_studio_participant_voice (participant_id, provider),
  INDEX idx_studio_voice_recognition (owner_user_id, provider, recognition_provider_speaker_id),
  INDEX idx_studio_voice_clone (owner_user_id, provider, clone_provider_voice_id),
  CONSTRAINT fk_studio_voice_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_studio_voice_participant FOREIGN KEY (participant_id) REFERENCES studio_participants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS studio_session_participants (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  owner_user_id INT UNSIGNED NOT NULL,
  conversation_id BIGINT UNSIGNED NULL,
  transcript_session_id BIGINT UNSIGNED NULL,
  participant_id BIGINT UNSIGNED NULL,
  speaker_label VARCHAR(80) NOT NULL DEFAULT '',
  recognition_method VARCHAR(40) NOT NULL DEFAULT 'unknown',
  recognition_confidence DECIMAL(5,4) NULL,
  provider VARCHAR(30) NOT NULL DEFAULT '',
  provider_speaker_id VARCHAR(190) NOT NULL DEFAULT '',
  presence_state VARCHAR(20) NOT NULL DEFAULT 'present',
  first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_studio_session_conversation (owner_user_id, conversation_id, last_seen_at),
  INDEX idx_studio_session_transcript (owner_user_id, transcript_session_id, last_seen_at),
  INDEX idx_studio_session_participant (owner_user_id, participant_id, last_seen_at),
  CONSTRAINT fk_studio_session_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_studio_session_participant FOREIGN KEY (participant_id) REFERENCES studio_participants(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
