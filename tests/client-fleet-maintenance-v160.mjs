import assert from 'node:assert/strict';
import fs from 'node:fs';

const read=p=>fs.readFileSync(p,'utf8');
const fleet=read('includes/client-fleet-maintenance-v160.php');
const rollouts=read('includes/client-release-rollouts-v110.php');
const risk=read('includes/client-release-risk-v140.php');
const incidents=read('includes/client-release-incidents-v130.php');
const bootstrap=read('includes/bootstrap.php');
const upgrade=read('upgrade.php');
const admin=read('admin/homeserver.php');
const workflow=read('.github/workflows/client-release-intelligence-v100.yml');
const recovery=read('tools/run_recovery_baseline.py');

for(const table of [
  'client_fleet_support_policy_v160',
  'client_fleet_version_policy_v160',
  'client_fleet_compatibility_rules_v160',
  'client_fleet_pins_v160',
  'client_fleet_upgrade_paths_v160',
  'client_fleet_maintenance_campaigns_v160',
  'client_fleet_maintenance_members_v160',
]){
  assert.ok(fleet.includes('CREATE TABLE IF NOT EXISTS '+table),'missing fleet table '+table);
}

for(const fn of [
  'client_fleet_policy_update_v160',
  'client_fleet_version_policy_update_v160',
  'client_fleet_compatibility_rule_save_v160',
  'client_fleet_compatibility_v160',
  'client_fleet_pin_set_v160',
  'client_fleet_upgrade_path_save_v160',
  'client_fleet_upgrade_plan_v160',
  'client_fleet_inventory_v160',
  'client_fleet_summary_v160',
  'client_fleet_window_open_v160',
  'client_fleet_campaign_create_v160',
  'client_fleet_campaign_set_v160',
  'client_fleet_campaign_refresh_v160',
  'client_fleet_maintenance_applicable_release_v160',
  'client_fleet_client_state_v160'
]){
  assert.ok(fleet.includes('function '+fn+'('),'missing fleet function '+fn);
}

for(const status of ['current','supported','maintenance','deprecated','unsupported']){
  assert.ok(fleet.includes("'"+status+"'"),'missing support status '+status);
}
for(const state of ['draft','active','paused','completed','cancelled']){
  assert.ok(fleet.includes("'"+state+"'"),'missing campaign state '+state);
}
for(const mode of ['outdated','unsupported','deprecated','maintenance','stale','all_supported_old']){
  assert.ok(fleet.includes("'"+mode+"'"),'missing eligibility mode '+mode);
}

assert.ok(rollouts.includes('function client_release_channel_v110('),'v1.10 must expose the canonical channel normalizer used by v1.40/v1.60');
assert.ok(risk.includes('client_release_channel_v110('),'v1.40 risk policy must use the canonical channel normalizer');

const applicableStart=rollouts.indexOf('function client_release_applicable_release_v110');
const applicableEnd=rollouts.indexOf('function client_release_public_release_v110');
const applicable=rollouts.slice(applicableStart,applicableEnd);
const recoveryPos=applicable.indexOf('client_release_recovery_applicable_release_v130');
const pinPos=applicable.indexOf('client_fleet_pin_applicable_release_v160');
const campaignPos=applicable.indexOf('client_fleet_maintenance_applicable_release_v160');
assert.ok(recoveryPos>=0&&pinPos>recoveryPos&&campaignPos>pinPos,'release priority must remain incident recovery → pin → maintenance → normal rollout');

assert.ok(rollouts.includes("$isFleetOverride=!empty($release['_fleet_override'])"));
assert.ok(rollouts.includes('client_fleet_maintenance_governs_scope_v160'),'maintenance campaign must block normal GA fallback while governing a member');
assert.ok(rollouts.includes("'support_status'"));
assert.ok(rollouts.includes("'maintenance_campaign_id'"));

assert.match(fleet,/client_release_risk_assess_v140/,'maintenance cohort sizing must incorporate v1.40 risk');
assert.match(fleet,/client_release_incident_active_for_release_v130/,'maintenance targets must respect active v1.30 incidents');
assert.match(fleet,/client_fleet_scope_has_active_incident_v160/,'incident-affected clients must be excluded from normal maintenance');
assert.match(fleet,/function_exists\('client_release_readiness_current_v150'\)/,'v1.50 readiness integration must remain optional');
assert.ok(fleet.includes("v1.50 readiness is not installed"));

const applicableFleetStart=fleet.indexOf('function client_fleet_maintenance_applicable_release_v160');
const applicableFleetEnd=fleet.indexOf('function client_fleet_client_state_v160');
const applicableFleet=fleet.slice(applicableFleetStart,applicableFleetEnd);
assert.doesNotMatch(applicableFleet,/shell_exec\s*\(|proc_open\s*\(|passthru\s*\(/);
assert.doesNotMatch(applicableFleet,/client_release_rollout_update_v110\s*\(/,'maintenance release selection must never mutate rollout lifecycle');
assert.ok(applicableFleet.includes("campaign_state='active'"));
assert.ok(applicableFleet.includes('client_fleet_window_open_v160'));
assert.ok(applicableFleet.includes('client_fleet_active_pin_v160'));

const campaignSetStart=fleet.indexOf('function client_fleet_campaign_set_v160');
const campaignSetEnd=fleet.indexOf('function client_fleet_maintenance_applicable_release_v160');
const campaignSet=fleet.slice(campaignSetStart,campaignSetEnd);
assert.doesNotMatch(campaignSet,/shell_exec\s*\(|proc_open\s*\(|passthru\s*\(/);
assert.ok(campaignSet.includes('client_fleet_max_cohort_for_target_v160'));
assert.ok(campaignSet.includes('Risk policy limits this maintenance target'));

assert.match(fleet,/client_fleet_upgrade_plan_v160/);
assert.match(fleet,/client_fleet_target_compatibility_v160/,'maintenance targeting must re-check Browser Companion ↔ HomeServer compatibility');
assert.match(fleet,/client_fleet_recommendations_v160/);
assert.ok(fleet.includes('Maintenance campaign cannot be completed until every member is installed or explicitly excluded.'));
assert.ok(fleet.includes('intermediate_release_id'));
assert.ok(fleet.includes('General Availability final target'));
assert.ok(fleet.includes('maintenance_window_start'));
assert.ok(fleet.includes('maintenance_window_end'));
assert.ok(fleet.includes('minimum_supported_version'));
assert.ok(fleet.includes('stale_after_hours'));
assert.ok(fleet.includes('deprecation_warning_days'));
assert.ok(fleet.includes('Browser Companion ↔ HomeServer'));

assert.doesNotMatch(fleet,/shell_exec\s*\(|proc_open\s*\(|passthru\s*\(/,'fleet maintenance must never execute client software');

assert.ok(bootstrap.includes('client-fleet-maintenance-v160.php'));
assert.ok(upgrade.includes('client_fleet_schema_ready_v160'));
assert.ok(upgrade.includes('client_fleet_ensure_schema_v160'));

assert.ok(admin.includes('Fleet Maintenance &amp; Compatibility'));
assert.ok(admin.includes('fleet_policy_update'));
assert.ok(admin.includes('fleet_version_policy'));
assert.ok(admin.includes('fleet_compatibility_save'));
assert.ok(admin.includes('fleet_pin_set'));
assert.ok(admin.includes('fleet_upgrade_path_save'));
assert.ok(admin.includes('fleet_campaign_create'));
assert.ok(admin.includes('fleet_campaign_set'));
assert.ok(admin.includes('fleet_campaign_refresh'));
assert.ok(admin.includes('No forced installs'));
assert.ok(admin.includes('Maintenance Recommendations'));
assert.ok(admin.includes('Browser ↔ HomeServer Compatibility'));

assert.match(workflow,/Client Release Operations v1\.60/);
assert.ok(workflow.includes('client-fleet-maintenance-v160.php'));
assert.ok(workflow.includes('client-fleet-maintenance-v160.mjs'));
assert.ok(recovery.includes('client-fleet-maintenance-v160.mjs'));

console.log('Client Release Operations v1.60 fleet maintenance contract: PASS');
