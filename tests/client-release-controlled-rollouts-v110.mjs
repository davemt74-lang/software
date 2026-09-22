import assert from 'node:assert/strict';
import fs from 'node:fs';
const read=p=>fs.readFileSync(p,'utf8');

const runtime=read('includes/client-release-rollouts-v110.php');
const bootstrap=read('includes/bootstrap.php');
const upgrade=read('upgrade.php');
const admin=read('admin/homeserver.php');
const client=read('client-updates.php');
const extensionMe=read('api/extension-me.php');
const publicChrome=read('chrome-extension-download.php');
const homeApi=read('api/homeserver-release.php');
const homeDownload=read('homeserver-download.php');
const controlledDownload=read('client-release-download.php');
const workflow=read('.github/workflows/client-release-intelligence-v100.yml');
const recovery=read('tools/run_recovery_baseline.py');

for(const table of ['client_release_rollouts_v110','client_release_assignments_v110','client_release_update_state_v110','client_release_audit_v110']){
  assert.match(runtime,new RegExp('CREATE TABLE IF NOT EXISTS '+table));
}
for(const state of ['draft','testing','canary','limited','general_availability','paused','superseded','withdrawn']) assert.ok(runtime.includes("'"+state+"'"),'missing lifecycle '+state);
assert.match(runtime,/client_release_cohort_bucket_v110/);
assert.match(runtime,/hash\('sha256'/,'rollout cohort must be deterministic');
assert.match(runtime,/client_release_rollout_eligible_v110/);
assert.match(runtime,/client_release_applicable_release_v110/);
assert.match(runtime,/client_release_public_release_v110/);
assert.match(runtime,/client_release_set_channel_v110/);
assert.match(runtime,/client_release_defer_v110/);
assert.match(runtime,/client_release_resume_v110/);
assert.match(runtime,/client_release_set_update_state_v110/);
assert.match(runtime,/client_release_audit_v110/);
assert.match(runtime,/client_release_adoption_summary_v110/);
assert.doesNotMatch(runtime,/shell_exec\s*\(|proc_open\s*\(|passthru\s*\(/,'rollouts must not execute client software');

assert.match(bootstrap,/client-release-rollouts-v110\.php/);
assert.match(upgrade,/client_release_rollouts_schema_ready_v110/);
assert.match(upgrade,/client_release_rollouts_ensure_schema_v110/);

assert.match(admin,/Controlled Rollouts/);
assert.match(admin,/rollout_update/);
assert.match(admin,/lifecycle_state/);
assert.match(admin,/rollout_percent/);
assert.match(admin,/general_availability/);
assert.match(admin,/known_issues/);
assert.match(admin,/compatibility_notes/);

assert.match(client,/Controlled Rollouts v1\.10/);
assert.match(client,/set_release_channel/);
assert.match(client,/defer_release/);
assert.match(client,/resume_release/);
assert.match(client,/Canary|Limited rollout/);
assert.match(client,/client-release-download\.php/);

assert.match(extensionMe,/client_release_applicable_release_v110/);
assert.match(extensionMe,/'rollout_state'/);
assert.match(extensionMe,/'update_status'/);

assert.match(publicChrome,/client_release_public_release_v110/,'public Chrome endpoint must expose GA only when rollout schema is ready');
assert.match(homeApi,/client_release_public_release_v110/,'public HomeServer release API must expose GA only');
assert.match(homeDownload,/client_release_public_release_v110/,'legacy public HomeServer download must reject non-GA releases');
assert.match(controlledDownload,/require_permission\('account\.access'\)/);
assert.match(controlledDownload,/client_release_scope_authorized_v110/);
assert.match(controlledDownload,/client_release_set_update_state_v110/);

assert.match(workflow,/Client Release Operations v1\.(?:10|20|30|40|60)/);
assert.match(workflow,/client-release-rollouts-v110\.php/);
assert.match(workflow,/client-release-controlled-rollouts-v110\.mjs/);
assert.match(recovery,/client-release-controlled-rollouts-v110\.mjs/);

console.log('Client Release Operations v1.10 controlled rollouts contract: PASS');
