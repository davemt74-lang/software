-- Stonefellow v72 — Albums + user playlists
-- Safe to run after v70. /upgrade.php performs the same upgrade idempotently.

INSERT INTO permissions
(permission_key,label,description,category,sort_order)
VALUES
(
  'albums.manage',
  'Manage Albums',
  'Create albums and assign tracks to album collections.',
  'Content',
  45
)
ON DUPLICATE KEY UPDATE
  label=VALUES(label),
  description=VALUES(description),
  category=VALUES(category),
  sort_order=VALUES(sort_order);

INSERT IGNORE INTO role_permissions (role,permission_key)
VALUES
  ('manager','albums.manage'),
  ('supervisor','albums.manage');

CREATE TABLE IF NOT EXISTS albums (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(190) NOT NULL,
  release_date DATE NULL,
  description TEXT NOT NULL,
  cover_path VARCHAR(500) NOT NULL DEFAULT '',
  visibility VARCHAR(30) NOT NULL DEFAULT 'members',
  sort_order INT NOT NULL DEFAULT 0,
  is_published TINYINT(1) NOT NULL DEFAULT 1,
  created_by_user_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_albums_published_sort (is_published, sort_order, id),
  INDEX idx_albums_visibility (visibility),
  INDEX idx_albums_creator (created_by_user_id),
  CONSTRAINT fk_albums_creator
    FOREIGN KEY (created_by_user_id) REFERENCES users(id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @album_column_exists = (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE()
    AND TABLE_NAME='tracks'
    AND COLUMN_NAME='album_id'
);

SET @album_column_sql = IF(
  @album_column_exists=0,
  'ALTER TABLE tracks ADD COLUMN album_id INT UNSIGNED NULL AFTER producer_user_id',
  'SELECT 1'
);

PREPARE album_column_stmt FROM @album_column_sql;
EXECUTE album_column_stmt;
DEALLOCATE PREPARE album_column_stmt;

SET @album_index_exists = (
  SELECT COUNT(*)
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=DATABASE()
    AND TABLE_NAME='tracks'
    AND INDEX_NAME='idx_tracks_album_sort'
);

SET @album_index_sql = IF(
  @album_index_exists=0,
  'ALTER TABLE tracks ADD INDEX idx_tracks_album_sort (album_id,sort_order,id)',
  'SELECT 1'
);

PREPARE album_index_stmt FROM @album_index_sql;
EXECUTE album_index_stmt;
DEALLOCATE PREPARE album_index_stmt;

SET @album_fk_exists = (
  SELECT COUNT(*)
  FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA=DATABASE()
    AND TABLE_NAME='tracks'
    AND CONSTRAINT_NAME='fk_tracks_album'
    AND CONSTRAINT_TYPE='FOREIGN KEY'
);

SET @album_fk_sql = IF(
  @album_fk_exists=0,
  'ALTER TABLE tracks ADD CONSTRAINT fk_tracks_album FOREIGN KEY (album_id) REFERENCES albums(id) ON DELETE SET NULL',
  'SELECT 1'
);

PREPARE album_fk_stmt FROM @album_fk_sql;
EXECUTE album_fk_stmt;
DEALLOCATE PREPARE album_fk_stmt;

CREATE TABLE IF NOT EXISTS playlists (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  owner_user_id INT UNSIGNED NOT NULL,
  title VARCHAR(190) NOT NULL,
  description TEXT NOT NULL,
  visibility VARCHAR(30) NOT NULL DEFAULT 'members',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_playlists_owner_updated (owner_user_id, updated_at),
  INDEX idx_playlists_visibility (visibility),
  CONSTRAINT fk_playlists_owner
    FOREIGN KEY (owner_user_id) REFERENCES users(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS playlist_tracks (
  playlist_id INT UNSIGNED NOT NULL,
  track_id INT UNSIGNED NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  added_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (playlist_id, track_id),
  INDEX idx_playlist_tracks_sort (playlist_id, sort_order, track_id),
  CONSTRAINT fk_playlist_tracks_playlist
    FOREIGN KEY (playlist_id) REFERENCES playlists(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_playlist_tracks_track
    FOREIGN KEY (track_id) REFERENCES tracks(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
