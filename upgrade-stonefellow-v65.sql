-- Stonefellow v65 — Producer account + per-track Producer sharing
-- Safe to run once. The web /upgrade.php path performs the same upgrade
-- idempotently through ensure_access_schema().

INSERT INTO permissions
(permission_key,label,description,category,sort_order)
VALUES
(
  'producer.access',
  'Producer Workspace',
  'Open tracks specifically shared with this producer and work in Stem Studio.',
  'Administration',
  32
)
ON DUPLICATE KEY UPDATE
  label=VALUES(label),
  description=VALUES(description),
  category=VALUES(category),
  sort_order=VALUES(sort_order);

INSERT IGNORE INTO role_permissions (role,permission_key)
VALUES
  ('producer','account.access'),
  ('producer','admin.access'),
  ('producer','producer.access');

SET @producer_column_exists = (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE()
    AND TABLE_NAME='tracks'
    AND COLUMN_NAME='producer_user_id'
);

SET @producer_column_sql = IF(
  @producer_column_exists=0,
  'ALTER TABLE tracks ADD COLUMN producer_user_id INT UNSIGNED NULL AFTER owner_user_id',
  'SELECT 1'
);

PREPARE producer_column_stmt FROM @producer_column_sql;
EXECUTE producer_column_stmt;
DEALLOCATE PREPARE producer_column_stmt;

SET @producer_index_exists = (
  SELECT COUNT(*)
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=DATABASE()
    AND TABLE_NAME='tracks'
    AND INDEX_NAME='idx_tracks_producer_updated'
);

SET @producer_index_sql = IF(
  @producer_index_exists=0,
  'ALTER TABLE tracks ADD INDEX idx_tracks_producer_updated (producer_user_id,updated_at)',
  'SELECT 1'
);

PREPARE producer_index_stmt FROM @producer_index_sql;
EXECUTE producer_index_stmt;
DEALLOCATE PREPARE producer_index_stmt;

SET @producer_fk_exists = (
  SELECT COUNT(*)
  FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA=DATABASE()
    AND TABLE_NAME='tracks'
    AND CONSTRAINT_NAME='fk_tracks_producer'
    AND CONSTRAINT_TYPE='FOREIGN KEY'
);

SET @producer_fk_sql = IF(
  @producer_fk_exists=0,
  'ALTER TABLE tracks ADD CONSTRAINT fk_tracks_producer FOREIGN KEY (producer_user_id) REFERENCES users(id) ON DELETE SET NULL',
  'SELECT 1'
);

PREPARE producer_fk_stmt FROM @producer_fk_sql;
EXECUTE producer_fk_stmt;
DEALLOCATE PREPARE producer_fk_stmt;
