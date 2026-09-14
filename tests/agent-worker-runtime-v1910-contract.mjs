import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = (p) => fs.readFileSync(p,'utf8');
const runtime = read('includes/agent-worker-runtime-v1910.php');
const worker = read('includes/agent-job-worker-v1900.php');
const api = read('api/agent-workflow-runs-v1400.php');
const engine = read('includes/agent-job-engine-v1900.php');
const page = read('agent-workflows.php');

assert.match(runtime,/VP3_AGENT_WORKER_RUNTIME_V1910/);
for (const include of [
  "require_once __DIR__.'/agent-job-engine-v1900.php'",
  "require_once __DIR__.'/homeserver-vp3.php'",
  "require_once __DIR__.'/homeserver-capability-registry-v033.php'",
  "require_once __DIR__.'/agent-tool-authorization-v400.php'",
]) assert.ok(runtime.includes(include), `missing canonical dependency ${include}`);

for (const fn of [
  'agent_worker_runtime_worker_v1910',
  'agent_worker_runtime_summary_v1910',
  'agent_worker_runtime_authorize_claim_v1910',
  'agent_worker_runtime_poll_v1910',
  'agent_worker_runtime_heartbeat_v1910',
  'agent_worker_runtime_result_v1910',
  'agent_worker_runtime_execute_homeserver_once_v1910',
]) assert.ok(runtime.includes(fn), `missing ${fn}`);

// Phase 19.0 remains the sole durable queue/lease/retry/receipt authority.
for (const fn of ['agent_job_recover_expired_v1900','agent_job_claim_next_v1900','agent_job_heartbeat_v1900','agent_job_record_result_v1900']) {
  assert.ok(runtime.includes(fn), `runtime must delegate to ${fn}`);
}
assert.ok(!runtime.includes('CREATE TABLE'), '19.1 must not create a second worker/job store');
assert.ok(!runtime.includes('ALTER TABLE'), '19.1 must not add a parallel execution schema');
assert.ok(!runtime.includes("UPDATE agent_workflow_actions SET status='queued'"), '19.1 must not implement its own retry machine');
assert.ok(engine.includes("status='failed'"), 'Phase 19.0 terminal failed state remains canonical');
assert.ok(runtime.includes("'dead_lettered'"), 'owner summary should translate terminal failed receipts to dead-letter semantics');

// Owner isolation + lease identity are checked again at handoff time.
assert.ok(runtime.includes('WHERE r.id=? AND r.owner_user_id=? AND a.id=?'));
assert.ok(runtime.includes("hash_equals((string)$row['lease_owner'],$workerId)"));
assert.ok(runtime.includes("hash_equals((string)$row['lease_token'],(string)($claim['lease_token']??''))"));
assert.ok(runtime.includes('lease_expires_at'));
assert.ok(runtime.includes('approval_required'));
assert.ok(runtime.includes('capability_unavailable'));
const authCall = runtime.indexOf('$authorization=agent_worker_runtime_authorize_claim_v1910');
const release = runtime.indexOf("$claim['authorization']=$authorization");
assert.ok(authCall >= 0 && release > authCall, 'executable claim must be authorized before release');

// HomeServer execution readiness must come from the live authenticated v0.33 registry.
assert.ok(runtime.includes('homeserver_capability_v033_registry($ownerUserId,true)'), 'worker readiness must force a live capability registry refresh');
assert.ok(!runtime.includes("$connection['capabilities_json']"), 'cached capabilities_json must never authorize durable execution');
assert.ok(runtime.includes("'homeserver_agent_chat_unavailable'"), 'agent.chat must be explicitly advertised');
assert.ok(runtime.includes('VP3_AGENT_WORKER_HOMESERVER_OPERATION_V1910'), 'transport operation must stay explicit');
assert.ok(runtime.includes("'agent.next_action'=>VP3_AGENT_WORKER_HOMESERVER_OPERATION_V1910"), 'workflow capability transport must be allowlisted');
assert.ok(runtime.includes('if($capabilityKey===\'\'||!isset($allowed[$capabilityKey]))return null;'), 'unknown workflow capabilities must fail closed');
assert.ok(runtime.includes("hash('sha256',$deviceId)"), 'raw device id should not become the public worker id');
assert.ok(runtime.includes('VP3_AGENT_WORKER_STALE_SECONDS_V1910'));
assert.ok(runtime.includes("'homeserver_stale'"));
assert.ok(runtime.includes("'homeserver_offline'"));
assert.ok(runtime.includes('max_concurrency'));
assert.ok(runtime.includes('lease_owner LIKE ?'), 'capacity must be derived from canonical live leases');

// The execution lease must safely outlive the longest relay request.
const constantInt = (name) => {
  const m = runtime.match(new RegExp(`const ${name}=(\\d+);`));
  assert.ok(m, `missing numeric constant ${name}`);
  return Number(m[1]);
};
const relayTimeout = constantInt('VP3_AGENT_WORKER_HOMESERVER_RELAY_TIMEOUT_V1910');
const leaseSeconds = constantInt('VP3_AGENT_WORKER_HOMESERVER_LEASE_SECONDS_V1910');
assert.ok(leaseSeconds >= relayTimeout + 20, 'HomeServer lease must exceed relay timeout by a recovery margin');
assert.ok(runtime.includes('CURLOPT_TIMEOUT=>VP3_AGENT_WORKER_HOMESERVER_RELAY_TIMEOUT_V1910'));
assert.ok(runtime.includes('VP3_AGENT_WORKER_HOMESERVER_LEASE_SECONDS_V1910'));

// Worker capability may narrow routing, but Cloud capability is not itself permission authority.
assert.ok(runtime.includes('Capability narrows routing; it never grants authority.'));
assert.ok(runtime.includes('agent_worker_runtime_supports_capability_v1910'));

// Local HomeServer policy remains authoritative; approval requests are surfaced, not bypassed.
assert.ok(runtime.includes('If local HomeServer policy requires approval'));
assert.ok(runtime.includes("'homeserver_approval_pending'"));
assert.ok(runtime.includes("'local_action_request_count'"));

// Result persistence stays receipt/idempotency based and retries stay in Phase 19.0.
assert.ok(runtime.includes("'v1910-hs-'.$runId.'-'.$actionId.'-'.$attempt"));
assert.ok(runtime.includes("'homeserver_transport_timeout'"));
assert.ok(runtime.includes('$retryable=!empty($remote[\'retryable\'])'));

// v4.00 is used according to its real contract: returned browser actions are sanitized.
assert.ok(runtime.includes('vp3_agent_tool_authorize_result_v400'));
assert.ok(runtime.includes("unset($result['actions'])"), 'raw worker actions must not be persisted as executable output');

// Browser workflow API is observability only; executor primitives remain server-only.
assert.ok(api.includes('agent_worker_runtime_summary_v1910'));
assert.ok(api.includes("'workers'=>$workers"));
for (const fn of ['agent_worker_runtime_poll_v1910','agent_worker_runtime_heartbeat_v1910','agent_worker_runtime_result_v1910','agent_job_claim_next_v1900','agent_worker_runtime_execute_homeserver_once_v1910']) {
  assert.ok(!api.includes(fn), `browser API must not expose ${fn}`);
}
assert.ok(worker.includes('agent_job_worker_poll_distributed_v1910'));
assert.ok(worker.includes('agent_worker_runtime_poll_v1910'));

// Owner UI must expose worker state without exposing executor primitives.
assert.ok(page.includes("require_once __DIR__ . '/includes/agent-worker-runtime-v1910.php'"));
assert.ok(page.includes('agent_worker_runtime_summary_v1910'));
assert.ok(page.includes('Worker Runtime'));
assert.ok(page.includes('Dead-lettered'));
for (const fn of ['agent_worker_runtime_poll_v1910','agent_worker_runtime_heartbeat_v1910','agent_worker_runtime_result_v1910','agent_worker_runtime_execute_homeserver_once_v1910']) {
  assert.ok(!page.includes(fn), `owner page must not expose ${fn}`);
}

// Private/local filesystem data must never enter worker observability.
for (const forbidden of ['native_path','filesystem_path']) {
  assert.ok(!runtime.includes(forbidden), `runtime must not expose ${forbidden}`);
}

console.log('Distributed Worker Runtime v19.1 contract: OK');
