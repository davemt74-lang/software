-- Stonefellow v104 — Artist workspaces + multiple user account types
-- Safe to run more than once.

CREATE TABLE IF NOT EXISTS user_account_types (
  user_id INT UNSIGNED NOT NULL,
  role VARCHAR(30) NOT NULL,
  assigned_explicitly_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, role),
  INDEX idx_user_account_types_role (role, user_id),
  CONSTRAINT fk_user_account_types_user
    FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE user_account_types
  ADD COLUMN IF NOT EXISTS assigned_explicitly_at DATETIME NULL AFTER role;

-- Backfill every existing account's legacy primary role. users.role remains the
-- primary account type for backward compatibility; additional types live here.
INSERT IGNORE INTO user_account_types (user_id, role)
SELECT id, role
FROM users
WHERE role <> '';

UPDATE user_account_types uat
INNER JOIN users u ON u.id=uat.user_id AND u.role=uat.role
SET uat.assigned_explicitly_at=COALESCE(uat.assigned_explicitly_at,NOW());

CREATE TABLE IF NOT EXISTS artist_team_members (
  artist_user_id INT UNSIGNED NOT NULL,
  member_user_id INT UNSIGNED NOT NULL,
  team_role VARCHAR(30) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (artist_user_id, member_user_id),
  UNIQUE KEY uq_artist_team_member (member_user_id),
  INDEX idx_artist_team_role (artist_user_id, team_role, member_user_id),
  CONSTRAINT fk_artist_team_artist
    FOREIGN KEY (artist_user_id) REFERENCES users(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_artist_team_member
    FOREIGN KEY (member_user_id) REFERENCES users(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permissions
  (permission_key, label, description, category, sort_order)
VALUES
  (
    'team.manage',
    'Manage Artist Team',
    'Create and manage the Artist workspace Manager and Producer accounts.',
    'Administration',
    34
  )
ON DUPLICATE KEY UPDATE
  label=VALUES(label),
  description=VALUES(description),
  category=VALUES(category),
  sort_order=VALUES(sort_order);

-- Artist is intentionally a creative/operator role. Platform user management,
-- AI/API credentials and permission administration remain Admin-only unless an
-- Admin explicitly gives that user an additional account type that grants them.
INSERT IGNORE INTO role_permissions (role, permission_key) VALUES
  ('artist', 'account.access'),
  ('artist', 'chat.access'),
  ('artist', 'admin.access'),
  ('artist', 'team.manage'),
  ('artist', 'listening.view'),
  ('artist', 'track_notes.manage'),
  ('artist', 'tracks.manage'),
  ('artist', 'albums.manage'),
  ('artist', 'shows.manage'),
  ('artist', 'photos.manage'),
  ('artist', 'merch.manage'),
  ('artist', 'posts.manage'),
  ('artist', 'messages.manage'),
  ('artist', 'profile.manage'),
  ('artist', 'knowledge.access'),
  ('artist', 'knowledge.manage');
