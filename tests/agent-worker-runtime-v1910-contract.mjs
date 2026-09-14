import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = (p) => fs.readFileSync(p,'utf8');
const runtime = read('includes/agent-worker-runtime-v1910.php');
const worker = read('includes/agent-job-worker-v1900.php');
const api = read('api/agent-workflow-runs-v1400.php');
const engine = read('includes/agent-job-engine-v1900.php');

assert.match(runtime,/VP3_AGENT_WORKER_RUNTIME_V1910/);
assert.match(runtime,/require_once __DIR__\.\/['"]agent-job-engine-v1900\.php['"]/);
assert.match(runtime,/require_once __DIR__\.\/['"]homeserver-vp3\.php['"]/);
assert.match(runtime,/require_once __DIR__\.\/['"]homeserver-capability-registry-v033\.php['"]/);
assert.match(runtime,/require_once __DIR__\.\/['"]agent-tool-authorization-v400\.php['"]/);

for (const fn of [
  'agent_worker_runtime_worker_v1910',
  'agent_worker_runtime_summary_v1910',
  'agent_worker_runtime_authorize_claim_v1910',
  'agent_worker_runtime_poll_v1910',
  'agent_worker_runtime_heartbeat_v1910',
  'agent_worker_runtime_result_v1910',
]) assert.ok(runtime.includes(fn), `missing ${fn}`);

// Phase 19.0 remains the sole durable queue/lease/retry/receipt authority.
for (const fn of ['agent_job_recover_expired_v1900','agent_job_claim_next_v1900','agent_job_heartbeat_v1900','agent_job_record_result_v1900']) {
  assert.ok(runtime.includes(fn), `runtime must delegate to ${fn}`);
}
assert.ok(!runtime.includes('CREATE TABLE'), '19.1 must not create a second worker/job store');
assert.ok(!runtime.includes('ALTER TABLE'), '19.1 must not add a parallel execution schema');
assert.ok(!runtime.includes('UPDATE agent_workflow_actions SET status=\'queued\''), '19.1 must not implement its own retry machine');
assert.ok(engine.includes("status='failed'"), 'Phase 19.0 terminal failed state remains canonical');
assert.ok(runtime.includes("'dead_lettered'"), 'owner summary should translate terminal failed receipts to dead-letter semantics');

// Owner isolation + lease identity are checked again at handoff time.
assert.ok(runtime.includes('WHERE r.id=? AND r.owner_user_id=? AND a.id=?'));
assert.ok(runtime.includes("hash_equals((string)$row['lease_owner'],$workerId)"));
assert.ok(runtime.includes("hash_equals((string)$row['lease_token'],(string)($claim['lease_token']??''))"));
assert.ok(runtime.includes('lease_expires_at'));
assert.ok(runtime.includes('approval_required'));
assert.ok(runtime.includes('capability_unavailable'));
assert.ok(runtime.indexOf('agent_worker_runtime_authorize_claim_v1910') < runtime.indexOf("$claim['authorization']=$authorization"), 'executable claim must be authorized before release');

// HomeServer identity and capability facts come from existing canonical stores.
assert.ok(runtime.includes('homeserver_vp3_connection($uid)'));
assert.ok(runtime.includes('homeserver_capability_v033_normalize'));
assert.ok(runtime.includes("hash('sha256',$deviceId)"), 'raw device id should not become the public worker id');
assert.ok(runtime.includes('VP3_AGENT_WORKER_STALE_SECONDS_V1910'));
assert.ok(runtime.includes("'homeserver_stale'"));
assert.ok(runtime.includes("'homeserver_offline'"));
assert.ok(runtime.includes('max_concurrency'));
assert.ok(runtime.includes("lease_owner LIKE ?"), 'capacity must be derived from canonical live leases');

// Worker capability may narrow routing, but Cloud capability is not itself permission authority.
assert.ok(runtime.includes('Capability narrows routing; it never grants authority.'));
assert.ok(runtime.includes('agent_worker_runtime_supports_capability_v1910'));

// v4.00 is used according to its real contract: returned browser actions are sanitized.
assert.ok(runtime.includes('vp3_agent_tool_authorize_result_v400'));
assert.ok(runtime.includes("unset($result['actions'])"), 'raw worker actions must not be persisted as executable output');

// Browser workflow API is observability only; executor primitives remain server-only.
assert.ok(api.includes('agent_worker_runtime_summary_v1910'));
assert.ok(api.includes("'workers'=>$workers"));
for (const fn of ['agent_worker_runtime_poll_v1910','agent_worker_runtime_heartbeat_v1910','agent_worker_runtime_result_v1910','agent_job_claim_next_v1900']) {
  assert.ok(!api.includes(fn), `browser API must not expose ${fn}`);
}
assert.ok(worker.includes('agent_job_worker_poll_distributed_v1910'));
assert.ok(worker.includes('agent_worker_runtime_poll_v1910'));

// No native HomeServer paths or credentials are copied into the runtime registry.
for (const forbidden of ['native_path','filesystem_path','relay_token_enc=','homeserver_token_enc=']) {
  assert.ok(!runtime.includes(forbidden), `runtime must not store/expose ${forbidden}`);
}

console.log('Distributed Worker Runtime v19.1 contract: OK');
