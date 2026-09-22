import assert from 'node:assert/strict';
import fs from 'node:fs';

const read=p=>fs.readFileSync(p,'utf8');
const model=read('includes/client-release-command-center-v180.php');
const page=read('admin/release-command-center.php');
const header=read('admin/_header.php');
const detail=read('admin/homeserver.php');
const bootstrap=read('includes/bootstrap.php');
const workflow=read('.github/workflows/client-release-intelligence-v100.yml');
const recovery=read('tools/run_recovery_baseline.py');

for(const fn of [
  'client_release_command_center_product_label_v180',
  'client_release_command_center_release_rows_v180',
  'client_release_command_center_attention_v180',
  'client_release_command_center_operational_status_v180',
  'client_release_command_center_metrics_v180',
  'client_release_command_center_build_v180'
]){
  assert.ok(model.includes('function '+fn+'('),'missing v1.80 function '+fn);
}

for(const dependency of [
  'client_release_intelligence_admin_summary_v100',
  'client_release_health_admin_summary_v120',
  'client_release_incident_list_v130',
  'client_release_risk_admin_summary_v140',
  'client_release_readiness_admin_summary_v150',
  'client_fleet_summary_v160',
  'client_release_automation_admin_summary_v170',
  'client_release_audit_recent_v110'
]){
  assert.ok(model.includes(dependency),'v1.80 must compose existing governed subsystem '+dependency);
}

for(const queueType of ['incident','health','readiness','risk','automation','automation_hold','fleet']){
  assert.ok(model.includes("'"+queueType+"'"),'attention queue missing '+queueType);
}
for(const severity of ['critical','high','action','watch']){
  assert.ok(model.includes("'"+severity+"'"),'attention severity missing '+severity);
}

assert.ok(page.includes("require_permission('users.manage')"),'command center must remain admin/operator only');
assert.ok(page.includes('Release Command Center'));
assert.ok(page.includes('Operator Attention Queue'));
assert.ok(page.includes('Managed Release Matrix'));
assert.ok(page.includes('Fleet Health'));
assert.ok(page.includes('Governed Automation'));
assert.ok(page.includes('Release Intelligence'));
assert.ok(page.includes('Active Incidents'));
assert.ok(page.includes('Recent Release Audit'));
assert.ok(page.includes('Release Operations Workspaces'));

assert.ok(page.includes("client_release_automation_run_v170($pdo,'manual'"),'command center automation action must use v1.70 runtime');
assert.ok(page.includes('client_release_automation_control_update_v170'),'kill switch must use v1.70 controls');
assert.doesNotMatch(page,/client_release_rollout_update_v110\s*\(/,'v1.80 command center must not duplicate rollout mutation');
assert.doesNotMatch(page,/client_release_incident_resolve_v130\s*\(/,'v1.80 command center must not duplicate incident resolution');
assert.doesNotMatch(page,/client_release_health_approve_promotion_v120\s*\(/,'v1.80 command center must not bypass health approval workflow');
assert.doesNotMatch(page,/client_fleet_campaign_set_v160\s*\(/,'v1.80 command center must not bypass fleet campaign workflow');

assert.ok(page.includes('command_kill_on'));
assert.ok(page.includes('command_kill_off'));
assert.ok(page.includes('Activate kill switch'));
assert.ok(page.includes('Run governed automation'));
assert.ok(page.includes('Open detailed Client Releases'));

assert.ok(header.includes('/admin/release-command-center.php'));
assert.ok(header.includes("adminActive === 'release-command-center'"));
assert.ok(detail.includes('Release Command Center'));
assert.ok(detail.includes('/admin/release-command-center.php'));
assert.ok(bootstrap.includes('client-release-command-center-v180.php'));

assert.match(workflow,/Client Release Operations v1\.80/);
assert.ok(workflow.includes('includes/client-release-command-center-v180.php'));
assert.ok(workflow.includes('admin/release-command-center.php'));
assert.ok(workflow.includes('tests/client-release-command-center-v180.mjs'));
assert.ok(recovery.includes('tests/client-release-command-center-v180.mjs'));

console.log('Client Release Operations v1.80 admin command center contract: PASS');
