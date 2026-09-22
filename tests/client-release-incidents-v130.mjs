import assert from 'node:assert/strict';
import fs from 'node:fs';

const read=p=>fs.readFileSync(p,'utf8');
const incidents=read('includes/client-release-incidents-v130.php');
const rollouts=read('includes/client-release-rollouts-v110.php');
const health=read('includes/client-release-health-v120.php');
const bootstrap=read('includes/bootstrap.php');
const upgrade=read('upgrade.php');
const admin=read('admin/homeserver.php');
const workflow=read('.github/workflows/client-release-intelligence-v100.yml');
const recovery=read('tools/run_recovery_baseline.py');

for(const table of [
  'client_release_incidents_v130',
  'client_release_incident_clients_v130',
  'client_release_incident_events_v130'
]){
  assert.ok(incidents.includes('CREATE TABLE IF NOT EXISTS '+table),'missing incident table '+table);
}

for(const fn of [
  'client_release_incident_open_v130',
  'client_release_incident_contain_v130',
  'client_release_incident_start_recovery_v130',
  'client_release_incident_set_cohort_v130',
  'client_release_incident_collect_affected_v130',
  'client_release_incident_refresh_v130',
  'client_release_incident_policy_update_v130',
  'client_release_incident_closure_readiness_v130',
  'client_release_incident_resolve_v130',
  'client_release_recovery_applicable_release_v130',
  'client_release_incident_acknowledge_client_v130'
]){
  assert.ok(incidents.includes('function '+fn+'('),'missing incident function '+fn);
}

for(const status of ['open','contained','recovering','monitoring','resolved']){
  assert.ok(incidents.includes("'"+status+"'"),'missing incident status '+status);
}
for(const severity of ['low','medium','high','critical']){
  assert.ok(incidents.includes("'"+severity+"'"),'missing severity '+severity);
}
for(const cohort of ['10','25','50','100']){
  assert.ok(incidents.includes(cohort),'missing recovery cohort '+cohort);
}

assert.ok(incidents.includes('recovery_release_id'));
assert.ok(incidents.includes('recovery_bucket'));
assert.ok(incidents.includes('recovery_rate_bps'));
assert.ok(incidents.includes('recovery_failure_rate_bps'));
assert.ok(incidents.includes('verification_hours'));
assert.ok(incidents.includes('monitoring_started_at'));
assert.ok(incidents.includes('root_cause'));
assert.ok(incidents.includes('resolution_summary'));
assert.ok(incidents.includes('lessons_learned'));
assert.ok(incidents.includes('client_exception_acknowledged'));
assert.ok(incidents.includes('incident_resolved'));
assert.ok(incidents.includes('closure_policy_updated'));

const openStart=incidents.indexOf('function client_release_incident_open_v130');
const openEnd=incidents.indexOf('function client_release_incident_contain_v130');
assert.doesNotMatch(incidents.slice(openStart,openEnd),/client_release_rollout_update_v110\s*\(/,'opening an incident must not silently mutate rollout state');

const containStart=incidents.indexOf('function client_release_incident_contain_v130');
const containEnd=incidents.indexOf('function client_release_incident_recovery_release_valid_v130');
assert.match(incidents.slice(containStart,containEnd),/client_release_rollout_update_v110\s*\(/,'explicit containment must use the governed v1.10 rollout transition');

const startStart=incidents.indexOf('function client_release_incident_start_recovery_v130');
const startEnd=incidents.indexOf('function client_release_incident_set_cohort_v130');
assert.doesNotMatch(incidents.slice(startStart,startEnd),/client_release_rollout_update_v110\s*\(/,'recovery assignment must stay incident-scoped rather than republishing the old release');

const recoveryStart=incidents.indexOf('function client_release_recovery_applicable_release_v130');
const recoveryEnd=incidents.indexOf('function client_release_incident_closure_readiness_v130');
const recoveryFn=incidents.slice(recoveryStart,recoveryEnd);
assert.ok(recoveryFn.includes("status IN ('recovering','monitoring')"));
assert.ok(recoveryFn.includes('recovery_percent'));
assert.ok(recoveryFn.includes('recovery_bucket'));
assert.ok(recoveryFn.includes('acknowledged_at'));
assert.doesNotMatch(recoveryFn,/client_release_rollout_update_v110\s*\(/);

assert.match(rollouts,/client_release_recovery_applicable_release_v130/,'v1.10 assignment path must honor v1.30 recovery overrides');
assert.match(rollouts,/client_release_incident_active_for_release_v130/,'active incidents must lock affected release promotions');
assert.ok(rollouts.includes("Use the incident recovery controls until it is resolved"));
assert.match(rollouts,/function_exists\('client_release_recovery_applicable_release_v130'\)/);
assert.ok(health.includes('rollback_review'));

const closureStart=incidents.indexOf('function client_release_incident_closure_readiness_v130');
const closureEnd=incidents.indexOf('function client_release_incident_resolve_v130');
const closure=incidents.slice(closureStart,closureEnd);
assert.ok(closure.includes('recovery_percent'));
assert.ok(closure.includes('min_recovery_rate_bps'));
assert.ok(closure.includes('max_recovery_failure_rate_bps'));
assert.ok(closure.includes('verification_hours'));
assert.ok(closure.includes('monitoring_started_at'));
assert.ok(closure.includes('Every remaining recovery exception'));

const resolveStart=incidents.indexOf('function client_release_incident_resolve_v130');
const resolveEnd=incidents.indexOf('function client_release_incident_list_v130');
const resolve=incidents.slice(resolveStart,resolveEnd);
assert.ok(resolve.includes('client_release_incident_closure_readiness_v130'));
assert.ok(resolve.includes("root===''||$summary===''"));
assert.ok(resolve.includes("status='resolved'"));

assert.doesNotMatch(incidents,/shell_exec\s*\(|proc_open\s*\(|passthru\s*\(/,'incident recovery must never execute client software');

assert.ok(bootstrap.includes('client-release-incidents-v130.php'));
assert.ok(upgrade.includes('client_release_incident_schema_ready_v130'));
assert.ok(upgrade.includes('client_release_incident_ensure_schema_v130'));

assert.ok(admin.includes('Release Incidents &amp; Fleet Recovery'));
assert.ok(admin.includes('incident_open'));
assert.ok(admin.includes('incident_contain'));
assert.ok(admin.includes('incident_start_recovery'));
assert.ok(admin.includes('incident_cohort'));
assert.ok(admin.includes('incident_refresh'));
assert.ok(admin.includes('incident_acknowledge'));
assert.ok(admin.includes('incident_policy'));
assert.ok(admin.includes('incident_resolve'));
assert.ok(admin.includes('operator-approved'));
assert.ok(admin.includes('Save closure gates'));
assert.ok(admin.includes('does not auto-rollback'));

assert.match(workflow,/Client Release Operations v1\.(?:30|40|60)/);
assert.ok(workflow.includes('client-release-incidents-v130.php'));
assert.ok(workflow.includes('client-release-incidents-v130.mjs'));
assert.ok(recovery.includes('client-release-incidents-v130.mjs'));

console.log('Client Release Operations v1.30 incident response contract: PASS');
