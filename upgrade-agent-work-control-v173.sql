-- VP3 Phase 17.3 — Agent Work Control
-- Run once after the Phase 19 durable job schema is installed.

ALTER TABLE agent_workflow_runs
  ADD COLUMN work_priority TINYINT UNSIGNED NOT NULL DEFAULT 50 AFTER timeout_seconds,
  ADD COLUMN pause_requested_at DATETIME NULL AFTER work_priority,
  ADD COLUMN paused_at DATETIME NULL AFTER pause_requested_at,
  ADD COLUMN paused_from_status VARCHAR(32) NOT NULL DEFAULT '' AFTER paused_at;

CREATE INDEX idx_agent_work_control_due
  ON agent_workflow_runs (owner_user_id, status, work_priority, next_attempt_at, id);
