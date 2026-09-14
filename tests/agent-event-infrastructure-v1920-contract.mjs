import fs from 'node:fs';
import assert from 'node:assert/strict';

const read=(p)=>fs.readFileSync(p,'utf8');
const core=read('includes/agent-event-infrastructure-v1920.php');
const routes=read('includes/agent-event-routes-v1920.php');
const webhook=read('api/agent-webhook-v1920.php');
const api=read('api/agent-events-v1920.php');
const page=read('agent-events.php');
const upgrade=read('agent-event-infrastructure-upgrade-v1920.php');
const migration=read('upgrade-agent-event-infrastructure-v1920.sql');
const deploy=read('.github/workflows/production-deploy-package.yml');

assert.ok(core.includes('VP3_AGENT_EVENT_INFRA_V1920'));
assert.ok(core.includes("require_once __DIR__.'/agent-job-engine-v1900.php'"));
assert.ok(core.includes('CREATE TABLE IF NOT EXISTS agent_event_inbox'));
assert.ok(migration.includes('CREATE TABLE IF NOT EXISTS agent_event_inbox'));
for(const field of ['event_uuid','owner_user_id','source','event_type','dedupe_hash','verification_status','processing_status','payload_json','linked_run_id','replay_count','processing_started_at']){
  assert.ok(core.includes(field),`missing event field ${field}`);assert.ok(migration.includes(field),`migration missing ${field}`);
}
assert.ok(migration.includes('UNIQUE KEY uq_agent_event_owner_dedupe'));
assert.ok(migration.includes('FOREIGN KEY (linked_run_id) REFERENCES agent_workflow_runs(id)'));
assert.ok(core.includes('agent_job_engine_schema_ready_v1900'));

// Durable ingress is bounded, sanitized and owner-scoped.
assert.ok(core.includes('VP3_AGENT_EVENT_MAX_PAYLOAD_BYTES_V1920=65536'));
assert.ok(core.includes('VP3_AGENT_EVENT_MAX_DEPTH_V1920=8'));
assert.ok(core.includes('agent_event_sanitize_payload_v1920'));
for(const denied of ['authorization','cookie','password','secret','token','credential','native[_-]?path','filesystem[_-]?path','local[_-]?path'])assert.ok(core.includes(denied),`redaction rule missing ${denied}`);
assert.ok(core.includes("$options['verification_status']??'trusted'"));
assert.ok(core.includes("['trusted','verified']"));
assert.ok(core.includes('agent_event_name_v1920'));

// Verification is fail-closed and replay resistant.
assert.ok(core.includes('agent_event_verify_hmac_v1920'));
assert.ok(core.includes('hash_hmac'));
assert.ok(core.includes('hash_equals'));
assert.ok(core.includes('VP3_AGENT_EVENT_SIGNATURE_TOLERANCE_V1920=300'));
assert.ok(core.includes('agent_event_register_webhook_source_v1920'));
assert.ok(core.includes('Webhook source is not registered.'));
assert.ok(core.includes('Webhook verification failed.'));
assert.ok(core.includes('Webhook event type is not allowed.'));
assert.ok(routes.includes('vp3_register_agent_event_routes_v1920'));

// Stale dispatches recover; live concurrent duplicates remain idempotent.
assert.ok(core.includes('VP3_AGENT_EVENT_DISPATCH_STALE_SECONDS_V1920=300'));
assert.ok(core.includes("'stale_dispatch_recovered'"));
assert.ok(core.includes('agent_event_dispatch_ingest_v1920'));
assert.ok(core.includes("in_array($status,['accepted','failed'],true)"));
assert.ok(core.includes("!in_array((string)$row['verification_status'],['trusted','verified'],true)"));
assert.ok(webhook.includes("$e->getMessage()==='This event is already being dispatched.'"));
assert.ok(webhook.includes("'duplicate'=>true,'processing_status'=>'processing'"));

// Public webhook route cannot register sources, leak payload/errors, or execute tools.
assert.ok(webhook.includes("$_SERVER['REQUEST_METHOD']!=='POST'"));
assert.ok(webhook.includes('CONTENT_LENGTH'));
assert.ok(webhook.includes('agent_event_accept_webhook_v1920'));
assert.ok(webhook.includes("'error'=>'Webhook request rejected.'"));
for(const forbidden of ['agent_event_register_webhook_source_v1920','agent_event_register_handler_v1920','agent_job_claim_next_v1900','agent_worker_runtime_poll_v1910','agent_worker_cloud_execute_once_v1910','tool.execute'])assert.ok(!webhook.includes(forbidden),`webhook surface must not expose ${forbidden}`);
assert.ok(!webhook.includes("'payload'=>"));
assert.ok(!webhook.includes("'headers'=>"));
assert.ok(!webhook.includes("'error'=>$e->getMessage()"));

// Brain stays authoritative and Phase 19 remains the execution queue.
assert.ok(core.includes('agent_brain_v122_upsert_system_memory'));
assert.ok(core.includes("'external_event'"));
assert.ok(core.includes('agent_job_enqueue_from_brain_v1900'));
assert.ok(core.includes("'key'=>'event-'.$uuid"));
assert.ok(core.includes("sha1('event|'.$uuid)"));
for(const directExec of ['agent_job_claim_next_v1900','agent_job_record_result_v1900','agent_worker_runtime_execute_homeserver_once_v1910','agent_worker_cloud_execute_once_v1910'])assert.ok(!core.includes(directExec),`event layer must not directly execute via ${directExec}`);
assert.ok(!core.includes('CREATE TABLE IF NOT EXISTS agent_workflow_runs'));
assert.ok(!core.includes('CREATE TABLE IF NOT EXISTS agent_workflow_receipts'));

// Replay is owner-only and uses immutable sanitized event identity.
assert.ok(core.includes("processing_status='processing'"));
assert.ok(core.includes('replay_count=replay_count+?'));
assert.ok(core.includes("processing_status='processed'"));
assert.ok(core.includes("processing_status='failed'"));
assert.ok(api.includes("(string)($input['action']??'')!=='replay'"));
assert.ok(api.includes('agent_event_dispatch_v1920'));
for(const forbidden of ['agent_event_accept_webhook_v1920','agent_event_register_webhook_source_v1920','agent_event_emit_internal_v1920'])assert.ok(!api.includes(forbidden),`owner API must not expose ${forbidden}`);

// Owner UI and migration are safe and deployable.
assert.ok(page.includes('Agent Events'));
assert.ok(page.includes('Verified external events and trusted internal signals'));
assert.ok(page.includes('linked_run_id'));
assert.ok(page.includes('replay_count'));
assert.ok(page.includes('agent_event_dispatch_v1920'));
assert.ok(!page.includes('agent_event_accept_webhook_v1920'));
assert.ok(upgrade.includes("require_permission('users.manage')"));
assert.ok(upgrade.includes('agent_event_ensure_schema_v1920'));
assert.ok(deploy.includes('rsync -a ./ _deploy/'));

console.log('Webhook + Event Infrastructure v19.2 contract: OK');
