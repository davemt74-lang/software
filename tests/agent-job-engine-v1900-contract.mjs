import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = (p) => fs.readFileSync(p,'utf8');
const engine = read('includes/agent-job-engine-v1900.php');
const api = read('api/agent-workflow-runs-v1400.php');
const migration = read('upgrade-agent-job-engine-v1900.sql');
const upgrade = read('agent-job-engine-upgrade-v1900.php');

assert.match(engine,/agent-workflow-runs-v1400\.php/,'Phase 19 must extend the canonical Phase 14 workflow ledger');
for (const field of ['next_attempt_at','lease_owner','lease_expires_at','heartbeat_at','progress_percent','max_attempts','retry_backoff_seconds','timeout_seconds']) assert.ok(engine.includes(field),`missing ${field}`);
assert.ok(engine.includes('agent_workflow_action_dependencies'));
assert.ok(engine.includes('agent_workflow_receipts'));
assert.ok(engine.includes('uq_agent_job_owner_receipt'),'Receipts need a durable idempotency constraint');
for (const fn of ['agent_job_claim_next_v1900','agent_job_claim_run_v1900','agent_job_heartbeat_v1900','agent_job_record_result_v1900','agent_job_recover_expired_v1900','agent_job_retry_delay_v1900','agent_job_dependencies_satisfied_v1900']) assert.ok(engine.includes(fn),`missing ${fn}`);
assert.ok(engine.includes('lease_expires_at>=UTC_TIMESTAMP()'),'Heartbeat must not revive an expired lease');
assert.ok(engine.includes('Durable job lease is invalid.'),'Result commits must validate active lease ownership');
assert.ok(engine.includes('A result idempotency key is required.'));
assert.ok(engine.includes('SELECT * FROM agent_workflow_receipts WHERE owner_user_id=? AND receipt_key=? LIMIT 1 FOR UPDATE'),'Duplicate result commits must resolve the receipt before reapplying a mutation');
assert.ok(engine.includes('attempt<$maxAttempts'),'Retries must be bounded');
assert.ok(engine.includes('min(3600'),'Backoff must be capped');
assert.ok(engine.includes('agent_action_v124_record_outcome'),'Terminal jobs must feed existing Agent Brain outcome learning');
assert.ok(engine.includes('agent_brain_v122_upsert_system_memory'),'Execution state must feed existing Agent Brain memory');
assert.ok(engine.includes('source_hash'),'Brain suggestion identity must survive into execution feedback');
assert.ok(api.includes('agent_job_enqueue_from_brain_v1900'),'Create from Brain must use durable jobs when installed');
assert.ok(api.includes('agent_job_cancel_v1900'));
assert.ok(api.includes('agent_job_retry_v1900'));
for (const fn of ['agent_job_claim_next_v1900','agent_job_heartbeat_v1900','agent_job_record_result_v1900']) assert.ok(!api.includes(fn),'Browser API must not expose executor-only primitives');
assert.ok(migration.includes('ALTER TABLE agent_workflow_runs'));
assert.ok(migration.includes('CREATE TABLE IF NOT EXISTS agent_workflow_receipts'));
assert.ok(migration.includes('CREATE TABLE IF NOT EXISTS agent_workflow_action_dependencies'));
assert.ok(upgrade.includes("require_permission('users.manage')"));
assert.ok(upgrade.includes('agent_job_engine_ensure_schema_v1900'));

console.log('Durable Agent Job Engine v19.0 contract: OK');
