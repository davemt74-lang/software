import assert from 'node:assert/strict';
import fs from 'node:fs';

const read=p=>fs.readFileSync(p,'utf8');
const risk=read('includes/client-release-risk-v140.php');
const rollouts=read('includes/client-release-rollouts-v110.php');
const health=read('includes/client-release-health-v120.php');
const incidents=read('includes/client-release-incidents-v130.php');
const bootstrap=read('includes/bootstrap.php');
const upgrade=read('upgrade.php');
const admin=read('admin/homeserver.php');
const workflow=read('.github/workflows/client-release-intelligence-v100.yml');
const recovery=read('tools/run_recovery_baseline.py');

for(const table of [
  'client_release_risk_profiles_v140',
  'client_release_risk_policy_v140',
  'client_release_risk_snapshots_v140',
  'client_release_risk_reviews_v140'
]){
  assert.ok(risk.includes('CREATE TABLE IF NOT EXISTS '+table),'missing risk table '+table);
}

for(const fn of [
  'client_release_risk_profile_update_v140',
  'client_release_risk_policy_update_v140',
  'client_release_risk_learning_v140',
  'client_release_risk_assess_v140',
  'client_release_risk_snapshot_v140',
  'client_release_risk_review_v140',
  'client_release_risk_learning_summary_v140',
  'client_release_risk_admin_summary_v140'
]){
  assert.ok(risk.includes('function '+fn+'('),'missing risk function '+fn);
}

for(const domain of ['auth','storage','update_path','permissions','compatibility','schema','network']){
  assert.ok(risk.includes("'"+domain+"'"),'missing risk domain '+domain);
}
for(const level of ['low','moderate','high','critical']){
  assert.ok(risk.includes("'"+level+"'"),'missing risk level '+level);
}
for(const recommendation of [
  'incident_controlled','stop_and_review','conservative_canary',
  'extended_observation','standard_canary','standard_rollout'
]){
  assert.ok(risk.includes("'"+recommendation+"'"),'missing risk recommendation '+recommendation);
}

assert.ok(risk.includes('historical_incident_rate_bps'));
assert.ok(risk.includes('historical_avg_failure_rate_bps'));
assert.ok(risk.includes('current_failure_rate_bps'));
assert.ok(risk.includes('current_compat_failure_rate_bps'));
assert.ok(risk.includes('matching_incident_domains'));
assert.ok(risk.includes('comparable_releases'));
assert.ok(risk.includes('confidence'));

assert.match(risk,/client_release_risk_text_domains_v140/,'incident postmortem text must produce reusable risk domains');
assert.match(risk,/root_cause/);
assert.match(risk,/lessons_learned/);
assert.match(risk,/client_release_health_calculate_v120/,'risk must incorporate current v1.20 health');
assert.match(risk,/client_release_incident_active_for_release_v130/,'risk must incorporate current v1.30 incident state');
assert.match(risk,/client_release_admin_rollouts_v110/,'risk learning must use governed release history');

const assessStart=risk.indexOf('function client_release_risk_assess_v140');
const assessEnd=risk.indexOf('function client_release_risk_snapshot_v140');
const assess=risk.slice(assessStart,assessEnd);
assert.doesNotMatch(assess,/client_release_rollout_update_v110\s*\(/,'risk assessment must never mutate rollout state');
assert.doesNotMatch(assess,/client_release_incident_contain_v130\s*\(/,'risk assessment must never contain a release');
assert.doesNotMatch(assess,/client_release_health_approve_promotion_v120\s*\(/,'risk assessment must never promote a release');

const reviewStart=risk.indexOf('function client_release_risk_review_v140');
const reviewEnd=risk.indexOf('function client_release_risk_recent_reviews_v140');
const review=risk.slice(reviewStart,reviewEnd);
assert.doesNotMatch(review,/client_release_rollout_update_v110\s*\(/,'risk review is an audit decision, not a rollout mutation');
assert.ok(review.includes("'reviewed','accepted_risk','defer'"));

assert.doesNotMatch(risk,/shell_exec\s*\(|proc_open\s*\(|passthru\s*\(/,'risk prediction must not execute client software');
assert.ok(rollouts.includes('client_release_rollout_update_v110'));
assert.ok(health.includes('client_release_health_calculate_v120'));
assert.ok(incidents.includes('client_release_incident_resolve_v130'));

assert.ok(bootstrap.includes('client-release-risk-v140.php'));
assert.ok(upgrade.includes('client_release_risk_schema_ready_v140'));
assert.ok(upgrade.includes('client_release_risk_ensure_schema_v140'));

assert.ok(admin.includes('Release Learning &amp; Risk Prediction'));
assert.ok(admin.includes('risk_profile_update'));
assert.ok(admin.includes('risk_policy_update'));
assert.ok(admin.includes('risk_assess'));
assert.ok(admin.includes('risk_review'));
assert.ok(admin.includes('Advisory only'));
assert.ok(admin.includes('Historical learning'));

assert.match(workflow,/Client Release Operations v1\.(?:40|60|70)/);
assert.ok(workflow.includes('client-release-risk-v140.php'));
assert.ok(workflow.includes('client-release-risk-v140.mjs'));
assert.ok(recovery.includes('client-release-risk-v140.mjs'));

console.log('Client Release Operations v1.40 release learning and risk contract: PASS');
