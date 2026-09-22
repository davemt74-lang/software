import assert from 'node:assert/strict';
import fs from 'node:fs';

const read=p=>fs.readFileSync(p,'utf8');
const runtime=read('includes/client-release-health-v120.php');
const rollouts=read('includes/client-release-rollouts-v110.php');
const bootstrap=read('includes/bootstrap.php');
const upgrade=read('upgrade.php');
const admin=read('admin/homeserver.php');
const workflow=read('.github/workflows/client-release-intelligence-v100.yml');
const recovery=read('tools/run_recovery_baseline.py');

for(const table of [
  'client_release_health_policy_v120',
  'client_release_health_snapshots_v120',
  'client_release_promotion_decisions_v120'
]){
  assert.ok(runtime.includes('CREATE TABLE IF NOT EXISTS '+table),'missing table '+table);
}

for(const fn of [
  'client_release_health_schema_ready_v120',
  'client_release_health_ensure_schema_v120',
  'client_release_health_policy_update_v120',
  'client_release_health_calculate_v120',
  'client_release_health_snapshot_v120',
  'client_release_health_approve_promotion_v120',
  'client_release_health_record_rejection_v120',
  'client_release_health_admin_summary_v120'
]){
  assert.ok(runtime.includes('function '+fn+'('),'missing function '+fn);
}

for(const signal of [
  'observed_clients','downloaded_clients','installed_clients','failed_clients',
  'compatibility_failed_clients','failure_rate_bps','compatibility_failure_rate_bps',
  'install_rate_bps','adoption_velocity_per_day','observation_hours'
]) assert.ok(runtime.includes(signal),'missing health signal '+signal);

for(const recommendation of ['observe','hold','rollback_review','promote','continue']){
  assert.ok(runtime.includes("'"+recommendation+"'"),'missing recommendation '+recommendation);
}

for(const gate of [
  'max_failure_rate_bps','max_compat_failure_rate_bps','min_install_rate_bps',
  'rollback_failure_rate_bps','min_observation_hours','min_observed_clients'
]) assert.ok(runtime.includes(gate),'missing gate '+gate);

const calculateStart=runtime.indexOf('function client_release_health_calculate_v120');
const calculateEnd=runtime.indexOf('function client_release_health_snapshot_v120');
const calculate=runtime.slice(calculateStart,calculateEnd);
assert.doesNotMatch(calculate,/client_release_rollout_update_v110\s*\(/,'health calculation must never mutate rollout state');

const snapshotStart=runtime.indexOf('function client_release_health_snapshot_v120');
const snapshotEnd=runtime.indexOf('function client_release_health_snapshot_by_id_v120');
const snapshot=runtime.slice(snapshotStart,snapshotEnd);
assert.doesNotMatch(snapshot,/client_release_rollout_update_v110\s*\(/,'health sampling must never mutate rollout state');

const approveStart=runtime.indexOf('function client_release_health_approve_promotion_v120');
const approveEnd=runtime.indexOf('function client_release_health_record_rejection_v120');
const approve=runtime.slice(approveStart,approveEnd);
assert.match(approve,/client_release_health_snapshot_v120\s*\(/,'approval must re-evaluate health');
assert.ok(approve.includes("['recommendation']!=='promote'"),'approval must reject unhealthy snapshots');
assert.match(approve,/client_release_rollout_update_v110\s*\(/,'only explicit approval may apply the governed rollout transition');
assert.ok(approve.includes('health_promotion_approved'));

const rejectStart=runtime.indexOf('function client_release_health_record_rejection_v120');
const rejectEnd=runtime.indexOf('function client_release_health_admin_summary_v120');
const reject=runtime.slice(rejectStart,rejectEnd);
assert.doesNotMatch(reject,/client_release_rollout_update_v110\s*\(/,'rejecting a recommendation must not change rollout state');
assert.ok(reject.includes('health_promotion_rejected'));

assert.ok(runtime.includes('Rollback review recommended'));
assert.doesNotMatch(runtime,/shell_exec\s*\(|proc_open\s*\(|passthru\s*\(/,'release health must not execute client software');
assert.ok(rollouts.includes('client_release_set_update_state_v110'));

assert.ok(bootstrap.includes("client-release-health-v120.php"));
assert.ok(upgrade.includes('client_release_health_schema_ready_v120'));
assert.ok(upgrade.includes('client_release_health_ensure_schema_v120'));

assert.ok(admin.includes('Release Health &amp; Promotion'));
assert.ok(admin.includes('health_evaluate'));
assert.ok(admin.includes('health_policy_update'));
assert.ok(admin.includes('health_promote'));
assert.ok(admin.includes('health_reject'));
assert.ok(admin.includes('Approve promotion'));
assert.ok(admin.includes('does not auto-rollback'));
assert.ok(admin.includes('explicitly approves'));

assert.match(workflow,/Client Release Operations v1\.(?:20|30|40|60|70|80)/);
assert.ok(workflow.includes('client-release-health-v120.php'));
assert.ok(workflow.includes('client-release-health-v120.mjs'));
assert.ok(recovery.includes('client-release-health-v120.mjs'));

console.log('Client Release Operations v1.20 release health contract: PASS');
