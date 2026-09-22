import assert from 'node:assert/strict';
import fs from 'node:fs';

const read=p=>fs.readFileSync(p,'utf8');
const automation=read('includes/client-release-automation-v170.php');
const rollouts=read('includes/client-release-rollouts-v110.php');
const health=read('includes/client-release-health-v120.php');
const incidents=read('includes/client-release-incidents-v130.php');
const risk=read('includes/client-release-risk-v140.php');
const readiness=read('includes/client-release-readiness-v150.php');
const fleet=read('includes/client-fleet-maintenance-v160.php');
const bootstrap=read('includes/bootstrap.php');
const upgrade=read('upgrade.php');
const admin=read('admin/homeserver.php');
const workflow=read('.github/workflows/client-release-intelligence-v100.yml');
const recovery=read('tools/run_recovery_baseline.py');
const cli=read('tools/client-release-automation-v170.php');

for(const table of [
  'client_release_automation_control_v170',
  'client_release_automation_policy_v170',
  'client_release_automation_holds_v170',
  'client_release_automation_proposals_v170',
  'client_release_automation_runs_v170',
  'client_release_automation_events_v170'
]){
  assert.ok(automation.includes('CREATE TABLE IF NOT EXISTS '+table),'missing automation table '+table);
}

for(const fn of [
  'client_release_automation_control_update_v170',
  'client_release_automation_policy_update_v170',
  'client_release_automation_hold_v170',
  'client_release_automation_release_hold_v170',
  'client_release_automation_rollout_proposal_v170',
  'client_release_automation_fleet_proposal_v170',
  'client_release_automation_validate_proposal_v170',
  'client_release_automation_execute_proposal_v170',
  'client_release_automation_execute_modified_proposal_v170',
  'client_release_automation_decide_proposal_v170',
  'client_release_automation_run_v170',
  'client_release_automation_admin_summary_v170'
]){
  assert.ok(automation.includes('function '+fn+'('),'missing automation function '+fn);
}

for(const type of ['rollout_promote','rollout_hold','rollback_review','fleet_advance','fleet_hold','incident_controlled','readiness_block']){
  assert.ok(automation.includes("'"+type+"'"),'missing proposal type '+type);
}
for(const decision of ['approve','reject','defer','modify']){
  assert.ok(automation.includes("'"+decision+"'"),'missing proposal decision '+decision);
}

assert.ok(automation.includes("'recommend_only','auto_low_risk'"));
assert.ok(automation.includes('automation_enabled'));
assert.ok(automation.includes('kill_switch'));
assert.ok(automation.includes('dry_run'));
assert.ok(automation.includes('proposal_expiry_hours'));
assert.ok(automation.includes('cooldown_minutes'));
assert.ok(automation.includes('fleet_observation_minutes'));
assert.ok(automation.includes('created_at>=?'),'proposal cooldown must suppress immediate churn after recent decisions');
assert.ok(automation.includes('fleet_completion_gate_bps'));
assert.ok(automation.includes('fleet_failure_hold_bps'));

assert.match(automation,/client_release_health_calculate_v120/,'automation must consume v1.20 health');
assert.match(automation,/client_release_health_snapshot_v120/,'rollout proposals must bind to a health snapshot');
assert.match(automation,/client_release_health_approve_promotion_v120/,'approved rollout automation must execute through v1.20 promotion ledger');
assert.match(automation,/client_release_incident_active_for_release_v130/,'active v1.30 incidents must suspend normal automation');
assert.match(automation,/client_release_incident_recovery_candidate_v130/,'rollback review should include the known-good recovery candidate');
assert.match(automation,/client_release_risk_assess_v140/,'automation must consume v1.40 risk');
assert.match(automation,/client_release_readiness_current_v150/,'automation must enforce v1.50 readiness');
assert.match(automation,/client_fleet_campaign_set_v160/,'fleet progression must execute through v1.60 campaign controls');

assert.ok(automation.includes('General Availability promotion always requires an operator.'));
assert.ok(automation.includes('General Availability promotion cannot be modified'));
assert.ok(automation.includes('Policy-authorized low-risk automation.'));
assert.ok(automation.includes("['low','moderate']"));
assert.ok(automation.includes('client_release_automation_live_actions_allowed_v170'));
assert.ok(automation.includes('client_release_automation_hold_blocks_release_v170'));
assert.ok(automation.includes('client_release_automation_hold_blocks_campaign_v170'));
assert.ok(automation.includes('idempotencyKey'));

const runStart=automation.indexOf('function client_release_automation_run_v170');
const runEnd=automation.indexOf('function client_release_automation_run_by_id_v170');
const run=automation.slice(runStart,runEnd);
assert.ok(run.includes("hash('sha256',$triggerType.'|'"));
assert.ok(run.includes('automation_enabled'));
assert.ok(run.includes('kill_switch'));
assert.ok(run.includes('dry_run'));

const liveStart=automation.indexOf('function client_release_automation_live_actions_allowed_v170');
const liveEnd=automation.indexOf('function client_release_automation_control_update_v170');
const live=automation.slice(liveStart,liveEnd);
assert.ok(live.includes("empty($control['kill_switch'])"));
assert.ok(live.includes("empty($control['dry_run'])"));

const executeStart=automation.indexOf('function client_release_automation_execute_proposal_v170');
const executeEnd=automation.indexOf('function client_release_automation_execute_modified_proposal_v170');
const execute=automation.slice(executeStart,executeEnd);
assert.ok(execute.includes('client_release_health_approve_promotion_v120'));
assert.ok(execute.includes('client_fleet_campaign_set_v160'));
assert.ok(execute.includes("if($systemExecution&&(empty($control['automation_enabled'])||!empty($control['kill_switch'])||!empty($control['dry_run'])))"));
assert.ok(execute.includes('Current automation policy no longer permits automatic execution.'));
assert.ok(execute.includes('Current v1.40 risk no longer permits automatic execution.'));

assert.doesNotMatch(automation,/shell_exec\s*\(|proc_open\s*\(|passthru\s*\(/,'automation runtime must not execute client software');
assert.ok(rollouts.includes('client_release_rollout_update_v110'));
assert.ok(health.includes('client_release_health_approve_promotion_v120'));
assert.ok(incidents.includes('client_release_incident_active_for_release_v130'));
assert.ok(risk.includes('client_release_risk_assess_v140'));
assert.ok(readiness.includes('client_release_readiness_current_v150'));
assert.ok(fleet.includes('client_fleet_campaign_set_v160'));

assert.ok(bootstrap.includes('client-release-automation-v170.php'));
assert.ok(upgrade.includes('client_release_automation_schema_ready_v170'));
assert.ok(upgrade.includes('client_release_automation_ensure_schema_v170'));

assert.ok(cli.includes("PHP_SAPI!=='cli'"));
assert.ok(cli.includes("client_release_automation_run_v170($pdo,'cli'"));
assert.ok(cli.includes("gmdate('Y-m-d-H')"));
assert.ok(automation.includes("INSERT IGNORE"),'scheduled runner must be concurrency-safe and idempotent');

assert.ok(admin.includes('Governed Release Automation'));
assert.ok(admin.includes('automation_control_update'));
assert.ok(admin.includes('automation_policy_update'));
assert.ok(admin.includes('automation_run'));
assert.ok(admin.includes('automation_decide'));
assert.ok(admin.includes('automation_hold_release'));
assert.ok(admin.includes('Global kill switch'));
assert.ok(admin.includes('Approval Queue'));
assert.ok(admin.includes('Dry run'));
assert.ok(admin.includes('Fleet observation minutes'));
assert.ok(admin.includes('GA promotion remains manual'));

assert.match(workflow,/Client Release Operations v1\.(?:70|80)/);
assert.ok(workflow.includes('client-release-automation-v170.php'));
assert.ok(workflow.includes('client-release-automation-v170.mjs'));
assert.ok(workflow.includes('tools/client-release-automation-v170.php'));
assert.ok(recovery.includes('client-release-automation-v170.mjs'));

console.log('Client Release Operations v1.70 governed automation contract: PASS');
