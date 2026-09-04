-- Stonefellow v47
-- User-owned Stem Studio projects

ALTER TABLE tracks
  ADD COLUMN owner_user_id INT UNSIGNED NULL AFTER id;

ALTER TABLE tracks
  ADD INDEX idx_tracks_owner_updated (owner_user_id, updated_at);

ALTER TABLE tracks
  ADD CONSTRAINT fk_tracks_owner
  FOREIGN KEY (owner_user_id) REFERENCES users(id)
  ON DELETE SET NULL;
