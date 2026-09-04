-- Stonefellow v76 — fan workspace expansion
-- Run after v75, or use /upgrade.php.

INSERT INTO permissions
(permission_key,label,description,category,sort_order)
VALUES
(
  'posts.manage',
  'Manage Artist Posts',
  'Create, edit, publish and delete Stonefellow artist updates and posts.',
  'Content',
  56
)
ON DUPLICATE KEY UPDATE
  label=VALUES(label),
  description=VALUES(description),
  category=VALUES(category),
  sort_order=VALUES(sort_order);

INSERT IGNORE INTO role_permissions (role,permission_key)
VALUES
  ('manager','posts.manage'),
  ('supervisor','posts.manage');

SET @track_credits_exists = (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE()
    AND TABLE_NAME='tracks'
    AND COLUMN_NAME='credits'
);
SET @track_credits_sql = IF(
  @track_credits_exists=0,
  'ALTER TABLE tracks ADD COLUMN credits TEXT NULL AFTER lyrics',
  'SELECT 1'
);
PREPARE track_credits_stmt FROM @track_credits_sql;
EXECUTE track_credits_stmt;
DEALLOCATE PREPARE track_credits_stmt;

CREATE TABLE IF NOT EXISTS album_favorites (
  user_id INT UNSIGNED NOT NULL,
  album_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, album_id),
  INDEX idx_album_favorites_album_created (album_id, created_at),
  CONSTRAINT fk_album_favorites_user
    FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_album_favorites_album
    FOREIGN KEY (album_id) REFERENCES albums(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS playlist_favorites (
  user_id INT UNSIGNED NOT NULL,
  playlist_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, playlist_id),
  INDEX idx_playlist_favorites_playlist_created (playlist_id, created_at),
  CONSTRAINT fk_playlist_favorites_user
    FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_playlist_favorites_playlist
    FOREIGN KEY (playlist_id) REFERENCES playlists(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS show_reminders (
  user_id INT UNSIGNED NOT NULL,
  show_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reminded_at DATETIME NULL,
  PRIMARY KEY (user_id, show_id),
  INDEX idx_show_reminders_show (show_id, created_at),
  CONSTRAINT fk_show_reminders_user
    FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_show_reminders_show
    FOREIGN KEY (show_id) REFERENCES shows(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS artist_posts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(190) NOT NULL,
  body TEXT NOT NULL,
  post_type VARCHAR(30) NOT NULL DEFAULT 'update',
  image_path VARCHAR(500) NOT NULL DEFAULT '',
  media_url VARCHAR(500) NOT NULL DEFAULT '',
  visibility VARCHAR(30) NOT NULL DEFAULT 'members',
  is_published TINYINT(1) NOT NULL DEFAULT 1,
  published_at DATETIME NULL,
  created_by_user_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_artist_posts_published (is_published, published_at, id),
  INDEX idx_artist_posts_visibility (visibility),
  CONSTRAINT fk_artist_posts_creator
    FOREIGN KEY (created_by_user_id) REFERENCES users(id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @merch_album_exists = (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE()
    AND TABLE_NAME='merch_items'
    AND COLUMN_NAME='album_id'
);
SET @merch_album_sql = IF(
  @merch_album_exists=0,
  'ALTER TABLE merch_items ADD COLUMN album_id INT UNSIGNED NULL AFTER image_path, ADD INDEX idx_merch_album (album_id)',
  'SELECT 1'
);
PREPARE merch_album_stmt FROM @merch_album_sql;
EXECUTE merch_album_stmt;
DEALLOCATE PREPARE merch_album_stmt;

SET @merch_track_exists = (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE()
    AND TABLE_NAME='merch_items'
    AND COLUMN_NAME='track_id'
);
SET @merch_track_sql = IF(
  @merch_track_exists=0,
  'ALTER TABLE merch_items ADD COLUMN track_id INT UNSIGNED NULL AFTER album_id, ADD INDEX idx_merch_track (track_id)',
  'SELECT 1'
);
PREPARE merch_track_stmt FROM @merch_track_sql;
EXECUTE merch_track_stmt;
DEALLOCATE PREPARE merch_track_stmt;

SET @merch_album_fk_exists = (
  SELECT COUNT(*)
  FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA=DATABASE()
    AND TABLE_NAME='merch_items'
    AND CONSTRAINT_NAME='fk_merch_album'
    AND CONSTRAINT_TYPE='FOREIGN KEY'
);
SET @merch_album_fk_sql = IF(
  @merch_album_fk_exists=0,
  'ALTER TABLE merch_items ADD CONSTRAINT fk_merch_album FOREIGN KEY (album_id) REFERENCES albums(id) ON DELETE SET NULL',
  'SELECT 1'
);
PREPARE merch_album_fk_stmt FROM @merch_album_fk_sql;
EXECUTE merch_album_fk_stmt;
DEALLOCATE PREPARE merch_album_fk_stmt;

SET @merch_track_fk_exists = (
  SELECT COUNT(*)
  FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA=DATABASE()
    AND TABLE_NAME='merch_items'
    AND CONSTRAINT_NAME='fk_merch_track'
    AND CONSTRAINT_TYPE='FOREIGN KEY'
);
SET @merch_track_fk_sql = IF(
  @merch_track_fk_exists=0,
  'ALTER TABLE merch_items ADD CONSTRAINT fk_merch_track FOREIGN KEY (track_id) REFERENCES tracks(id) ON DELETE SET NULL',
  'SELECT 1'
);
PREPARE merch_track_fk_stmt FROM @merch_track_fk_sql;
EXECUTE merch_track_fk_stmt;
DEALLOCATE PREPARE merch_track_fk_stmt;
