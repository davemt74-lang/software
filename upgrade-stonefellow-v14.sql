-- Stonefellow v14 upgrade
-- Adds REAPER project/stem storage for the Track Edit upload and online Stem Studio.

CREATE TABLE IF NOT EXISTS track_projects (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  track_id INT UNSIGNED NOT NULL UNIQUE,
  project_name VARCHAR(190) NOT NULL DEFAULT '',
  source_zip_name VARCHAR(255) NOT NULL DEFAULT '',
  rpp_file_name VARCHAR(255) NOT NULL DEFAULT '',
  rpp_file_path VARCHAR(500) NOT NULL DEFAULT '',
  tempo_bpm DECIMAL(7,2) NULL,
  time_signature VARCHAR(20) NOT NULL DEFAULT '',
  project_sample_rate INT UNSIGNED NULL,
  media_sample_rate INT UNSIGNED NULL,
  project_start_seconds DECIMAL(12,4) NOT NULL DEFAULT 0,
  imported_by_user_id INT UNSIGNED NULL,
  imported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_track_projects_imported (imported_at),
  CONSTRAINT fk_track_projects_track
    FOREIGN KEY (track_id) REFERENCES tracks(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_track_projects_user
    FOREIGN KEY (imported_by_user_id) REFERENCES users(id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS track_stems (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  track_id INT UNSIGNED NOT NULL,
  project_id BIGINT UNSIGNED NOT NULL,
  stem_name VARCHAR(190) NOT NULL,
  stem_role VARCHAR(80) NOT NULL DEFAULT 'Other',
  source_track_name VARCHAR(190) NOT NULL DEFAULT '',
  file_name VARCHAR(255) NOT NULL,
  file_path VARCHAR(500) NOT NULL,
  channels TINYINT UNSIGNED NOT NULL DEFAULT 0,
  sample_rate INT UNSIGNED NOT NULL DEFAULT 0,
  bit_depth SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  duration_seconds DECIMAL(12,4) NOT NULL DEFAULT 0,
  start_offset_seconds DECIMAL(12,4) NOT NULL DEFAULT 0,
  rpp_track_guid VARCHAR(80) NOT NULL DEFAULT '',
  rpp_volume DECIMAL(12,6) NOT NULL DEFAULT 1,
  rpp_pan DECIMAL(8,6) NOT NULL DEFAULT 0,
  rpp_fx_summary VARCHAR(1000) NOT NULL DEFAULT '',
  sort_order INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_track_stems_track_order (track_id,is_active,sort_order,id),
  INDEX idx_track_stems_role (track_id,stem_role),
  CONSTRAINT fk_track_stems_track
    FOREIGN KEY (track_id) REFERENCES tracks(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_track_stems_project
    FOREIGN KEY (project_id) REFERENCES track_projects(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
