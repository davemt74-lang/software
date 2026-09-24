-- Campaigns & Rewards V1.23 — Journey Publishing & Release Management
-- Idempotent MySQL 8 migration. Runtime backfill is performed by
-- campaigns_rewards_journey_release_ensure_schema_v123() after these tables exist.

CREATE TABLE IF NOT EXISTS campaign_journeys (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  public_id CHAR(36) NOT NULL,
  campaign_id BIGINT UNSIGNED NOT NULL,
  journey_key VARCHAR(80) NOT NULL,
  name VARCHAR(190) NOT NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'draft',
  enrollment_status VARCHAR(30) NOT NULL DEFAULT 'open',
  current_draft_version_id BIGINT UNSIGNED NULL,
  current_published_version_id BIGINT UNSIGNED NULL,
  created_by_user_id INT UNSIGNED NULL,
  archived_by_user_id INT UNSIGNED NULL,
  archived_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_campaign_journey_public (public_id),
  UNIQUE KEY uq_campaign_journey_key (campaign_id,journey_key),
  INDEX idx_campaign_journey (campaign_id,status,enrollment_status,id),
  INDEX idx_campaign_journey_publish (current_published_version_id),
  INDEX idx_campaign_journey_draft (current_draft_version_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS campaign_journey_versions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  public_id CHAR(36) NOT NULL,
  journey_id BIGINT UNSIGNED NOT NULL,
  version_no INT UNSIGNED NOT NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'draft',
  graph_json LONGTEXT NOT NULL,
  validation_json LONGTEXT NULL,
  release_notes TEXT NULL,
  based_on_version_id BIGINT UNSIGNED NULL,
  created_by_user_id INT UNSIGNED NULL,
  published_by_user_id INT UNSIGNED NULL,
  scheduled_publish_at DATETIME NULL,
  published_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_campaign_journey_version_public (public_id),
  UNIQUE KEY uq_campaign_journey_version (journey_id,version_no),
  INDEX idx_campaign_journey_version_status (journey_id,status,version_no),
  INDEX idx_campaign_journey_scheduled (status,scheduled_publish_at,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS campaign_journey_publications (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  public_id CHAR(36) NOT NULL,
  journey_id BIGINT UNSIGNED NOT NULL,
  journey_version_id BIGINT UNSIGNED NOT NULL,
  previous_journey_version_id BIGINT UNSIGNED NULL,
  action VARCHAR(40) NOT NULL,
  inflight_policy VARCHAR(30) NOT NULL DEFAULT 'continue',
  release_notes TEXT NULL,
  metadata_json LONGTEXT NULL,
  actor_user_id INT UNSIGNED NULL,
  published_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_campaign_journey_publication_public (public_id),
  INDEX idx_campaign_journey_publication (journey_id,published_at,id),
  INDEX idx_campaign_journey_publication_version (journey_version_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
