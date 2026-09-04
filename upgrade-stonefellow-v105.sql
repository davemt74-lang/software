-- Stonefellow v105 — Agent Operations release workspace + credits graph
-- Safe to run more than once.

CREATE TABLE IF NOT EXISTS release_plans (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  owner_user_id INT UNSIGNED NOT NULL,
  created_by_user_id INT UNSIGNED NULL,
  title VARCHAR(190) NOT NULL,
  release_type VARCHAR(40) NOT NULL DEFAULT 'single',
  status VARCHAR(30) NOT NULL DEFAULT 'planning',
  priority VARCHAR(20) NOT NULL DEFAULT 'normal',
  target_date DATETIME NULL,
  agent_goal TEXT NULL,
  notes TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_release_owner_target (owner_user_id,target_date,status),
  INDEX idx_release_status_updated (status,updated_at,id),
  CONSTRAINT fk_release_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_release_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS release_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  release_id BIGINT UNSIGNED NOT NULL,
  item_type VARCHAR(40) NOT NULL DEFAULT 'task',
  title VARCHAR(190) NOT NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'todo',
  due_at DATETIME NULL,
  completed_at DATETIME NULL,
  assigned_user_id INT UNSIGNED NULL,
  track_id INT UNSIGNED NULL,
  show_id INT UNSIGNED NULL,
  instructions TEXT NULL,
  agent_notes TEXT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_release_item_release_due (release_id,due_at,status),
  INDEX idx_release_item_assignee (assigned_user_id,status,due_at),
  INDEX idx_release_item_track (track_id),
  INDEX idx_release_item_show (show_id),
  CONSTRAINT fk_release_item_release FOREIGN KEY (release_id) REFERENCES release_plans(id) ON DELETE CASCADE,
  CONSTRAINT fk_release_item_assignee FOREIGN KEY (assigned_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_release_item_track FOREIGN KEY (track_id) REFERENCES tracks(id) ON DELETE SET NULL,
  CONSTRAINT fk_release_item_show FOREIGN KEY (show_id) REFERENCES shows(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Generic user-controlled resources the Agent can reason over and attach to work.
-- resource_uri may be an internal URL, external URL, connector object reference,
-- document reference or other opaque locator. Secrets/tokens never belong here.
CREATE TABLE IF NOT EXISTS agent_resources (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  owner_user_id INT UNSIGNED NOT NULL,
  resource_type VARCHAR(40) NOT NULL DEFAULT 'document',
  title VARCHAR(190) NOT NULL,
  resource_uri VARCHAR(1000) NOT NULL DEFAULT '',
  provider_key VARCHAR(80) NOT NULL DEFAULT '',
  external_id VARCHAR(255) NOT NULL DEFAULT '',
  metadata_json LONGTEXT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_agent_resource_owner_type (owner_user_id,resource_type,is_active,updated_at),
  INDEX idx_agent_resource_provider (owner_user_id,provider_key,external_id),
  CONSTRAINT fk_agent_resource_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS release_item_resources (
  release_item_id BIGINT UNSIGNED NOT NULL,
  resource_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (release_item_id,resource_id),
  CONSTRAINT fk_release_item_resource_item FOREIGN KEY (release_item_id) REFERENCES release_items(id) ON DELETE CASCADE,
  CONSTRAINT fk_release_item_resource_resource FOREIGN KEY (resource_id) REFERENCES agent_resources(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Connection metadata only. Provider adapters own credentials in their secure store.
CREATE TABLE IF NOT EXISTS agent_integrations (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  owner_user_id INT UNSIGNED NOT NULL,
  provider_key VARCHAR(80) NOT NULL,
  connection_key VARCHAR(190) NOT NULL DEFAULT '',
  label VARCHAR(190) NOT NULL DEFAULT '',
  status VARCHAR(30) NOT NULL DEFAULT 'disconnected',
  capabilities_json LONGTEXT NULL,
  metadata_json LONGTEXT NULL,
  last_sync_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_agent_integration_owner_provider (owner_user_id,provider_key,connection_key),
  INDEX idx_agent_integration_status (owner_user_id,status,provider_key),
  CONSTRAINT fk_agent_integration_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Audited queue for Agent busy-work. Future Gmail/SMS/social/document adapters execute
-- these records; approval can be required before any external side effect.
CREATE TABLE IF NOT EXISTS agent_work_actions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  owner_user_id INT UNSIGNED NOT NULL,
  release_id BIGINT UNSIGNED NULL,
  release_item_id BIGINT UNSIGNED NULL,
  provider_key VARCHAR(80) NOT NULL DEFAULT '',
  action_type VARCHAR(80) NOT NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'draft',
  source_kind VARCHAR(30) NOT NULL DEFAULT 'manual',
  requires_approval TINYINT(1) NOT NULL DEFAULT 1,
  scheduled_for DATETIME NULL,
  approved_at DATETIME NULL,
  completed_at DATETIME NULL,
  input_json LONGTEXT NULL,
  result_json LONGTEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_agent_work_owner_status (owner_user_id,status,scheduled_for,id),
  INDEX idx_agent_work_release (release_id,release_item_id,status),
  INDEX idx_agent_work_provider (owner_user_id,provider_key,action_type,status),
  CONSTRAINT fk_agent_work_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_agent_work_release FOREIGN KEY (release_id) REFERENCES release_plans(id) ON DELETE CASCADE,
  CONSTRAINT fk_agent_work_item FOREIGN KEY (release_item_id) REFERENCES release_items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS track_credits (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  track_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NULL,
  display_name VARCHAR(190) NOT NULL DEFAULT '',
  contribution_role VARCHAR(120) NOT NULL,
  contribution_detail VARCHAR(500) NOT NULL DEFAULT '',
  source_kind VARCHAR(40) NOT NULL DEFAULT 'manual',
  source_id BIGINT UNSIGNED NULL,
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_track_credit_track_sort (track_id,sort_order,id),
  INDEX idx_track_credit_user (user_id,track_id),
  CONSTRAINT fk_track_credit_track FOREIGN KEY (track_id) REFERENCES tracks(id) ON DELETE CASCADE,
  CONSTRAINT fk_track_credit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permissions (permission_key,label,description,category,sort_order) VALUES
  ('release.manage','Release Operations','Plan releases, deadlines, resources and Agent work actions.','Content',48),
  ('credits.manage','Track Credits','Manage structured track credits and contribution details.','Content',49)
ON DUPLICATE KEY UPDATE
  label=VALUES(label),description=VALUES(description),category=VALUES(category),sort_order=VALUES(sort_order);

INSERT IGNORE INTO role_permissions (role,permission_key) VALUES
  ('artist','release.manage'),('artist','credits.manage'),
  ('manager','release.manage'),('manager','credits.manage'),
  ('supervisor','release.manage'),('supervisor','credits.manage'),
  ('admin','release.manage'),('admin','credits.manage');
