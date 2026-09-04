-- Stonefellow v73 — Authenticated Player + Favorites
-- Run after the v72 upgrade, or use /upgrade.php.

CREATE TABLE IF NOT EXISTS track_favorites (
  user_id INT UNSIGNED NOT NULL,
  track_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, track_id),
  INDEX idx_track_favorites_track_created (track_id, created_at),
  INDEX idx_track_favorites_user_created (user_id, created_at),
  CONSTRAINT fk_track_favorites_user
    FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_track_favorites_track
    FOREIGN KEY (track_id) REFERENCES tracks(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
