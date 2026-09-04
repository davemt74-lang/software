-- Stonefellow user-owned agents, data-sharing policies, shared knowledge index,
-- and AI data retrieval transparency.
-- Normal deployments should run /upgrade.php because it also adds the nullable
-- chat_conversations.user_agent_id column safely when needed.

CREATE TABLE IF NOT EXISTS user_agents (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  owner_user_id INT UNSIGNED NOT NULL,
  agent_key VARCHAR(80) NOT NULL,
  display_name VARCHAR(190) NOT NULL,
  agent_role VARCHAR(40) NOT NULL DEFAULT 'personal',
  engine_key VARCHAR(40) NOT NULL DEFAULT 'stonefellow',
  instructions TEXT NULL,
  is_default TINYINT(1) NOT NULL DEFAULT 0,
  is_profile_agent TINYINT(1) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  voice_enabled TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_user_agent_key (owner_user_id,agent_key),
  INDEX idx_user_agents_owner_active (owner_user_id,is_active,is_default,id),
  CONSTRAINT fk_user_agents_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_agent_preferences (
  user_id INT UNSIGNED NOT NULL PRIMARY KEY,
  onboarding_dismissed TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_user_agent_preferences_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_data_policies (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  owner_user_id INT UNSIGNED NOT NULL,
  resource_type VARCHAR(50) NOT NULL,
  resource_id VARCHAR(100) NOT NULL DEFAULT '*',
  audience_scope VARCHAR(30) NOT NULL DEFAULT 'inherit',
  owner_agents_allowed TINYINT(1) NOT NULL DEFAULT 1,
  profile_agent_allowed TINYINT(1) NOT NULL DEFAULT 0,
  stonefellow_shared TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_user_data_policy (owner_user_id,resource_type,resource_id),
  INDEX idx_user_data_stonefellow (stonefellow_shared,resource_type,audience_scope,owner_user_id),
  CONSTRAINT fk_user_data_policy_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_agent_data_rules (
  agent_id BIGINT UNSIGNED NOT NULL,
  resource_type VARCHAR(50) NOT NULL,
  resource_id VARCHAR(100) NOT NULL DEFAULT '*',
  access_mode VARCHAR(20) NOT NULL DEFAULT 'inherit',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (agent_id,resource_type,resource_id),
  CONSTRAINT fk_user_agent_rule_agent FOREIGN KEY (agent_id) REFERENCES user_agents(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_relationships (
  owner_user_id INT UNSIGNED NOT NULL,
  related_user_id INT UNSIGNED NOT NULL,
  relationship_scope VARCHAR(30) NOT NULL DEFAULT 'connection',
  status VARCHAR(20) NOT NULL DEFAULT 'pending',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (owner_user_id,related_user_id),
  INDEX idx_user_relationship_related (related_user_id,status,relationship_scope),
  CONSTRAINT fk_user_relationship_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_user_relationship_related FOREIGN KEY (related_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_data_policy_audit (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  owner_user_id INT UNSIGNED NOT NULL,
  actor_user_id INT UNSIGNED NULL,
  action_key VARCHAR(60) NOT NULL,
  resource_type VARCHAR(50) NOT NULL DEFAULT '',
  resource_id VARCHAR(100) NOT NULL DEFAULT '',
  before_json LONGTEXT NULL,
  after_json LONGTEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_user_data_audit_owner (owner_user_id,created_at,id),
  CONSTRAINT fk_user_data_audit_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_user_data_audit_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_data_retrieval_log (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  owner_user_id INT UNSIGNED NOT NULL,
  requester_user_id INT UNSIGNED NULL,
  agent_id BIGINT UNSIGNED NULL,
  agent_kind VARCHAR(30) NOT NULL DEFAULT 'system',
  agent_name_snapshot VARCHAR(190) NOT NULL DEFAULT '',
  resource_type VARCHAR(50) NOT NULL,
  resource_id VARCHAR(100) NOT NULL DEFAULT '',
  resource_title_snapshot VARCHAR(255) NOT NULL DEFAULT '',
  source_key VARCHAR(190) NOT NULL DEFAULT '',
  access_class VARCHAR(40) NOT NULL DEFAULT 'shared_network',
  conversation_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_user_data_usage_owner_time (owner_user_id,created_at,id),
  INDEX idx_user_data_usage_requester_time (requester_user_id,created_at,id),
  INDEX idx_user_data_usage_resource (resource_type,resource_id,created_at,id),
  INDEX idx_user_data_usage_class_time (access_class,created_at,id),
  INDEX idx_user_data_usage_agent_time (agent_id,created_at,id),
  CONSTRAINT fk_user_data_usage_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_user_data_usage_requester FOREIGN KEY (requester_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_user_data_usage_agent FOREIGN KEY (agent_id) REFERENCES user_agents(id) ON DELETE SET NULL,
  CONSTRAINT fk_user_data_usage_conversation FOREIGN KEY (conversation_id) REFERENCES chat_conversations(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS shared_knowledge_index (
  knowledge_id INT UNSIGNED NOT NULL PRIMARY KEY,
  owner_user_id INT UNSIGNED NOT NULL,
  source_version_hash CHAR(64) NOT NULL,
  title VARCHAR(190) NOT NULL DEFAULT '',
  topic_tags VARCHAR(1000) NOT NULL DEFAULT '',
  embedding_ref VARCHAR(190) NOT NULL DEFAULT '',
  share_scope VARCHAR(30) NOT NULL DEFAULT 'inherit',
  is_indexed TINYINT(1) NOT NULL DEFAULT 1,
  last_indexed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  revoked_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_shared_knowledge_owner (owner_user_id,is_indexed,updated_at),
  INDEX idx_shared_knowledge_scope (is_indexed,share_scope,updated_at),
  CONSTRAINT fk_shared_knowledge_item_v236 FOREIGN KEY (knowledge_id) REFERENCES knowledge_items(id) ON DELETE CASCADE,
  CONSTRAINT fk_shared_knowledge_owner_v236 FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
