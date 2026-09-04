-- Stonefellow upgrade: profile photos + Chat + Knowledge Base
-- Intended for an existing Stonefellow installation.
-- The script is safe to run more than once.

CREATE TABLE IF NOT EXISTS permissions (
  permission_key VARCHAR(100) PRIMARY KEY,
  label VARCHAR(190) NOT NULL,
  description VARCHAR(500) NOT NULL DEFAULT '',
  category VARCHAR(100) NOT NULL DEFAULT 'General',
  sort_order INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS role_permissions (
  role VARCHAR(30) NOT NULL,
  permission_key VARCHAR(100) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (role, permission_key),
  CONSTRAINT fk_role_permissions_permission
    FOREIGN KEY (permission_key) REFERENCES permissions(permission_key)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @avatar_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND COLUMN_NAME = 'avatar_path'
);

SET @avatar_sql := IF(
  @avatar_exists = 0,
  "ALTER TABLE users ADD COLUMN avatar_path VARCHAR(500) NOT NULL DEFAULT '' AFTER role",
  "SELECT 'users.avatar_path already exists'"
);

PREPARE sf_avatar_stmt FROM @avatar_sql;
EXECUTE sf_avatar_stmt;
DEALLOCATE PREPARE sf_avatar_stmt;

CREATE TABLE IF NOT EXISTS knowledge_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(190) NOT NULL,
  description TEXT NOT NULL,
  file_name VARCHAR(255) NOT NULL DEFAULT '',
  file_path VARCHAR(500) NOT NULL DEFAULT '',
  file_type VARCHAR(50) NOT NULL DEFAULT 'text',
  mime_type VARCHAR(120) NOT NULL DEFAULT '',
  file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
  content_text LONGTEXT NOT NULL,
  visibility VARCHAR(30) NOT NULL DEFAULT 'members',
  is_published TINYINT(1) NOT NULL DEFAULT 1,
  created_by_user_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_kb_published_visibility (is_published, visibility),
  INDEX idx_kb_creator (created_by_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS knowledge_chunks (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  knowledge_id INT UNSIGNED NOT NULL,
  chunk_index INT NOT NULL DEFAULT 0,
  chunk_text TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_kb_chunks_item (knowledge_id, chunk_index),
  FULLTEXT KEY ft_kb_chunk (chunk_text),
  CONSTRAINT fk_knowledge_chunks_item
    FOREIGN KEY (knowledge_id) REFERENCES knowledge_items(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chat_conversations (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  title VARCHAR(190) NOT NULL DEFAULT 'New chat',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_chat_conversations_user (user_id, updated_at),
  CONSTRAINT fk_chat_conversations_user
    FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chat_messages (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  conversation_id BIGINT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NULL,
  role VARCHAR(20) NOT NULL,
  message LONGTEXT NOT NULL,
  context_json LONGTEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_chat_messages_conversation (conversation_id, id),
  CONSTRAINT fk_chat_messages_conversation
    FOREIGN KEY (conversation_id) REFERENCES chat_conversations(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permissions
(permission_key,label,description,category,sort_order)
VALUES
('chat.access','Stonefellow Chat','Use the conversational interface and role-scoped database search.','Account',15),
('knowledge.access','Knowledge Base Access','Allow chat to retrieve knowledge-base content available to the user.','Account',18),
('knowledge.manage','Manage Knowledge Base','Upload, edit, publish and delete knowledge-base files and notes.','Content',75)
ON DUPLICATE KEY UPDATE
  label=VALUES(label),
  description=VALUES(description),
  category=VALUES(category),
  sort_order=VALUES(sort_order);

INSERT IGNORE INTO role_permissions (role,permission_key) VALUES
('fan','chat.access'),
('fan','knowledge.access'),
('manager','knowledge.access'),
('manager','knowledge.manage'),
('supervisor','chat.access'),
('supervisor','knowledge.access'),
('supervisor','knowledge.manage'),
('admin','chat.access'),
('admin','knowledge.access'),
('admin','knowledge.manage');
