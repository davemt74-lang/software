-- Stonefellow v79 — native plugin chain import
-- Adds structured RPP -> Stonefellow native plugin mappings.

SET @master_chain_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE()
    AND TABLE_NAME='track_projects'
    AND COLUMN_NAME='master_plugin_chain_json'
);
SET @master_chain_sql = IF(
  @master_chain_exists=0,
  'ALTER TABLE track_projects ADD COLUMN master_plugin_chain_json LONGTEXT NULL AFTER project_start_seconds',
  'SELECT 1'
);
PREPARE master_chain_stmt FROM @master_chain_sql;
EXECUTE master_chain_stmt;
DEALLOCATE PREPARE master_chain_stmt;

SET @stem_chain_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE()
    AND TABLE_NAME='track_stems'
    AND COLUMN_NAME='plugin_chain_json'
);
SET @stem_chain_sql = IF(
  @stem_chain_exists=0,
  'ALTER TABLE track_stems ADD COLUMN plugin_chain_json LONGTEXT NULL AFTER rpp_fx_summary',
  'SELECT 1'
);
PREPARE stem_chain_stmt FROM @stem_chain_sql;
EXECUTE stem_chain_stmt;
DEALLOCATE PREPARE stem_chain_stmt;
