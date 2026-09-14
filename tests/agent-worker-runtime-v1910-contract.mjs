import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = (p) => fs.readFileSync(p,'utf8');
const runtime = read('includes/agent-worker-runtime-v1910.php');
const cloud = read('includes/agent-worker-cloud-v1910.php');
const worker = read('includes/agent-job-worker-v1900.php');
const runner = read('agent-worker-v1910.php');
const api = read('api/agent-workflow-runs-v1400.php');
const engine = read('includes/agent-job-engine-v1900.php');
const page = read('agent-workflows.php');
const deploy = read('.github/workflows/production-deploy-package.yml');

assert.match(runtime,/VP3_AGENT_WORKER_RUNTIME_V1910/);
for (const include of [
  "require_once __DIR__.'/agent-job-engine-v1900.php'",
  "require_once __DIR__.'/homeserver-vp3.php'",
  "require_once __DIR__.'/homeserver-capability-registry-v033.php'",
  "require_once __DIR__.'/agent-tool-authorization-v400.php'",
]) assert.ok(runtime.includes(include), `missing canonical dependency ${include}`);

for (const fn of [
  'agent_worker_runtime_worker_v1910','agent_worker_runtime_summary_v1910',
  'agent_worker_runtime_authorize_claim_v1910','agent_worker_runtime_poll_v1910',
  'agent_worker_runtime_heartbeat_v1910','agent_worker_runtime_result_v1910',
  'agent_worker_runtime_execute_homeserver_once_v1910',
]) assert.ok(runtime.includes(fn), `missing ${fn}`);

// Phase 19.0 remains the sole durable queue/lease/retry/receipt authority.
for (const fn of ['agent_job_recover_expired_v1900','agent_job_claim_next_v1900','agent_job_heartbeat_v1900','agent_job_record_result_v1900']) {
  assert.ok(runtime.includes(fn), `runtime must delegate to ${fn}`);
}
assert.ok(!runtime.includes('CREATE TABLE'));
assert.ok(!runtime.includes('ALTER TABLE'));
assert.ok(!cloud.includes('CREATE TABLE'));
assert.ok(!cloud.includes('ALTER TABLE'));
assert.ok(engine.includes("status='failed'"));
assert.ok(runtime.includes("'dead_lettered'"));

// Owner isolation + lease identity are checked again at HomeServer handoff.
assert.ok(runtime.includes('WHERE r.id=? AND r.owner_user_id=? AND a.id=?'));
assert.ok(runtime.includes("hash_equals((string)$row['lease_owner'],$workerId)"));
assert.ok(runtime.includes("hash_equals((string)$row['lease_token'],(string)($claim['lease_token']??''))"));
assert.ok(runtime.includes('lease_expires_at'));
assert.ok(runtime.includes('approval_required'));
const authCall = runtime.indexOf('$authorization=agent_worker_runtime_authorize_claim_v1910');
const release = runtime.indexOf("$claim['authorization']=$authorization");
assert.ok(authCall >= 0 && release > authCall);

// HomeServer readiness is a live authenticated v0.33 registry decision.
assert.ok(runtime.includes('homeserver_capability_v033_registry($ownerUserId,true)'));
assert.ok(!runtime.includes("$connection['capabilities_json']"));
assert.ok(runtime.includes("'homeserver_agent_chat_unavailable'"));
assert.ok(runtime.includes("'agent.next_action'=>VP3_AGENT_WORKER_HOMESERVER_OPERATION_V1910"));
assert.ok(runtime.includes('if($capabilityKey===\'\'||!isset($allowed[$capabilityKey]))return null;'));
assert.ok(runtime.includes("hash('sha256',$deviceId)"));
assert.ok(runtime.includes("'homeserver_stale'"));
assert.ok(runtime.includes("'homeserver_offline'"));
assert.ok(runtime.includes('lease_owner LIKE ?'));

// Remote timeout must fit safely inside the durable execution lease.
const constantInt = (name) => {
  const m = runtime.match(new RegExp(`const ${name}=(\\d+);`));
  assert.ok(m, `missing numeric constant ${name}`);
  return Number(m[1]);
};
const relayTimeout = constantInt('VP3_AGENT_WORKER_HOMESERVER_RELAY_TIMEOUT_V1910');
const leaseSeconds = constantInt('VP3_AGENT_WORKER_HOMESERVER_LEASE_SECONDS_V1910');
assert.ok(leaseSeconds >= relayTimeout + 20);
assert.ok(runtime.includes('CURLOPT_TIMEOUT=>VP3_AGENT_WORKER_HOMESERVER_RELAY_TIMEOUT_V1910'));

// Local HomeServer approval remains local policy; VP3 never bypasses it.
assert.ok(runtime.includes('If local HomeServer policy requires approval'));
assert.ok(runtime.includes("'homeserver_approval_pending'"));
assert.ok(runtime.includes("'local_action_request_count'"));
assert.ok(runtime.includes("'v1910-hs-'.$runId.'-'.$actionId.'-'.$attempt"));
assert.ok(runtime.includes('vp3_agent_tool_authorize_result_v400'));
assert.ok(runtime.includes("unset($result['actions'])"));

// Cloud worker is Brain-integrated orchestration, not a shadow mutation system.
assert.ok(cloud.includes('agent_cognitive_loop_v310_state'));
assert.ok(cloud.includes('agent_cognitive_loop_v310_run'));
assert.ok(cloud.includes('agent_workflow_find_brain_priority_v1400'));
assert.ok(cloud.includes("'domain_mutation_performed'=>false"));
assert.ok(cloud.includes("'cloud_domain_executor_unavailable'"));
assert.ok(cloud.includes("'calendar.review_conflict'=>true"));
assert.ok(cloud.includes("'calendar.prepare_commitment'=>true"));
assert.ok(cloud.includes("'scheduling.prepare_followup'=>true"));
assert.ok(cloud.includes("'commerce.review_next_action'=>true"));
assert.ok(!cloud.includes("'agent.next_action'=>true"));
assert.ok(cloud.includes('a.requires_approval action_requires_approval'));
assert.ok(cloud.includes("hash_equals((string)$row['lease_token']"));
assert.ok(cloud.includes("'v1910-cloud-'.$runId.'-'.$actionId.'-'.$attempt"));
assert.ok(cloud.includes('agent_worker_cloud_poll_v1910'));

// Canonical distributed poll routes each executor to its own authority model.
assert.ok(worker.includes("if($executor==='cloud')return agent_worker_cloud_poll_v1910"));
assert.ok(worker.includes("if($executor==='homeserver')return agent_worker_runtime_poll_v1910"));
assert.ok(worker.includes("'invalid_executor'"));

// Production invocation is CLI-only, single-instance and outside web requests.
assert.ok(runner.includes("if(PHP_SAPI!=='cli')"));
assert.ok(runner.includes('LOCK_EX|LOCK_NB'));
assert.ok(runner.includes('distributed-v1910.lock'));
assert.ok(runner.includes('agent_worker_cloud_execute_once_v1910'));
assert.ok(runner.includes('agent_job_worker_execute_homeserver_v1910'));
assert.ok(runner.includes("--loop"));
assert.ok(runner.includes("--executor"));
assert.ok(runner.includes('agent_cognitive_loop_v310_user'));
assert.ok(deploy.includes('rsync -a ./ _deploy/'));
assert.ok(!deploy.includes("--exclude='agent-worker-v1910.php'"));

// Browser surfaces remain observability-only.
assert.ok(api.includes('agent_worker_runtime_summary_v1910'));
assert.ok(api.includes("'workers'=>$workers"));
for (const fn of ['agent_worker_runtime_poll_v1910','agent_worker_runtime_heartbeat_v1910','agent_worker_runtime_result_v1910','agent_job_claim_next_v1900','agent_worker_runtime_execute_homeserver_once_v1910','agent_worker_cloud_execute_once_v1910']) {
  assert.ok(!api.includes(fn), `browser API must not expose ${fn}`);
}
assert.ok(page.includes("require_once __DIR__ . '/includes/agent-worker-runtime-v1910.php'"));
assert.ok(page.includes('Worker Runtime'));
assert.ok(page.includes('Dead-lettered'));
for (const fn of ['agent_worker_runtime_poll_v1910','agent_worker_runtime_execute_homeserver_once_v1910','agent_worker_cloud_execute_once_v1910']) {
  assert.ok(!page.includes(fn), `owner page must not expose ${fn}`);
}

for (const forbidden of ['native_path','filesystem_path'])assert.ok(!runtime.includes(forbidden));

console.log('Distributed Worker Runtime v19.1 contract: OK');
