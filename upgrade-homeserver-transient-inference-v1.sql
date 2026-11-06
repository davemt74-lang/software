-- Explicit transient agent.infer.local relay retention. Existing requests remain ordinary.
SET @hs_transient_column_sql = IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='homeserver_https_requests' AND COLUMN_NAME='transient_context'),
  'SELECT 1',
  'ALTER TABLE homeserver_https_requests ADD COLUMN transient_context TINYINT(1) NOT NULL DEFAULT 0 AFTER payload_json'
);
PREPARE hs_transient_column_stmt FROM @hs_transient_column_sql;
EXECUTE hs_transient_column_stmt;
DEALLOCATE PREPARE hs_transient_column_stmt;

SET @hs_transient_index_sql = IF(
  EXISTS(SELECT 1 FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='homeserver_https_requests' AND INDEX_NAME='idx_homeserver_https_transient_expiry'),
  'SELECT 1',
  'ALTER TABLE homeserver_https_requests ADD INDEX idx_homeserver_https_transient_expiry (transient_context,expires_at,id)'
);
PREPARE hs_transient_index_stmt FROM @hs_transient_index_sql;
EXECUTE hs_transient_index_stmt;
DEALLOCATE PREPARE hs_transient_index_stmt;
