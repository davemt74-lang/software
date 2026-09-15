-- VP3 Phase 17.4 — Agent Work Delegation & Dependencies
-- Additive cross-workflow prerequisite graph. Phase 19 remains the durable scheduler/claimant.

CREATE TABLE IF NOT EXISTS agent_workflow_run_dependencies (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  owner_user_id INT UNSIGNED NOT NULL,
  run_id BIGINT UNSIGNED NOT NULL,
  depends_on_run_id BIGINT UNSIGNED NOT NULL,
  created_by VARCHAR(32) NOT NULL DEFAULT 'user',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_agent_workflow_run_dependency (run_id,depends_on_run_id),
  INDEX idx_agent_workflow_run_dependency_owner (owner_user_id,run_id,id),
  INDEX idx_agent_workflow_run_dependency_reverse (owner_user_id,depends_on_run_id,id),
  CONSTRAINT fk_agent_workflow_run_dependency_run FOREIGN KEY (run_id) REFERENCES agent_workflow_runs(id) ON DELETE CASCADE,
  CONSTRAINT fk_agent_workflow_run_dependency_on_run FOREIGN KEY (depends_on_run_id) REFERENCES agent_workflow_runs(id) ON DELETE CASCADE,
  CONSTRAINT fk_agent_workflow_run_dependency_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
