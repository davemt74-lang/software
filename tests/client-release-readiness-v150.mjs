import assert from 'node:assert/strict';
import fs from 'node:fs';

const read=p=>fs.readFileSync(p,'utf8');
const readiness=read('includes/client-release-readiness-v150.php');
const rollouts=read('includes/client-release-rollouts-v110.php');
const risk=read('includes/client-release-risk-v140.php');
const fleet=read('includes/client-fleet-maintenance-v160.php');
const bootstrap=read('includes/bootstrap.php');
const upgrade=read('upgrade.php');
const admin=read('admin/homeserver.php');
const workflow=read('.github/workflows/client-release-intelligence-v100.yml');
const recovery=read('tools/run_recovery_baseline.py');

for(const table of [
  'client_release_readiness_manifest_v150',
  'client_release_readiness_ci_v150',
  'client_release_readiness_snapshots_v150',
  'client_release_readiness_signoffs_v150'
]){
  assert.ok(readiness.includes('CREATE TABLE IF NOT EXISTS '+table),'missing readiness table '+table);
}

for(const fn of [
  'client_release_readiness_manifest_update_v150',
  'client_release_readiness_ci_save_v150',
  'client_release_readiness_artifacts_v150',
  'client_release_readiness_evaluate_v150',
  'client_release_readiness_snapshot_v150',
  'client_release_readiness_signoff_v150',
  'client_release_readiness_current_v150',
  'client_release_readiness_admin_summary_v150'
]){
  assert.ok(readiness.includes('function '+fn+'('),'missing readiness function '+fn);
}

for(const status of ['incomplete','blocked','warnings','ready','stale','unsigned']){
  assert.ok(readiness.includes("'"+status+"'"),'missing readiness status '+status);
}
for(const decision of ['approved','approved_with_warnings','rejected']){
  assert.ok(readiness.includes("'"+decision+"'"),'missing readiness decision '+decision);
}

assert.ok(readiness.includes('source_commit_sha'));
assert.ok(readiness.includes('required_ci_checks_json'));
assert.ok(readiness.includes('requires_migration'));
assert.ok(readiness.includes('migration_reversible'));
assert.ok(readiness.includes('rollback_release_id'));
assert.ok(readiness.includes('rollback_plan'));
assert.ok(readiness.includes('compatibility_prerequisites'));
assert.ok(readiness.includes('canary_initial_percent'));
assert.ok(readiness.includes('canary_observation_hours'));
assert.ok(readiness.includes('escalation_owner_user_id'));

assert.match(readiness,/hash_file\('sha256'/,'preflight must re-hash stored client artifacts');
assert.match(readiness,/chrome_extension_release_validate_zip/,'Browser Companion preflight must validate ZIP structure and manifest');
assert.match(readiness,/homeserver_vp3_validate_pe_file/,'HomeServer preflight must validate PE artifacts');
assert.match(readiness,/client_release_risk_assess_v140/,'preflight must use v1.40 risk');
assert.match(readiness,/client_release_health_policy_v120/,'preflight must surface v1.20 health gates');
assert.match(readiness,/client_release_incident_active_for_release_v130/,'active v1.30 incidents must block readiness');
assert.match(readiness,/client_release_readiness_risk_accepted_v150/,'critical risk needs explicit accepted-risk review');
assert.ok(readiness.includes("Required CI check"));
assert.ok(readiness.includes("different source commit"));

const currentStart=readiness.indexOf('function client_release_readiness_current_v150');
const currentEnd=readiness.indexOf('function client_release_readiness_admin_summary_v150');
const current=readiness.slice(currentStart,currentEnd);
assert.match(current,/client_release_readiness_evaluate_v150\s*\(/,'current readiness must re-run live artifact and gate validation');
assert.match(current,/hash_equals/,'signed readiness must be fingerprint-bound');
assert.ok(current.includes('Readiness inputs changed after the last snapshot'));

const signoffStart=readiness.indexOf('function client_release_readiness_signoff_v150');
const signoffEnd=readiness.indexOf('function client_release_readiness_latest_signoff_v150');
const signoff=readiness.slice(signoffStart,signoffEnd);
assert.ok(signoff.includes("status==='blocked'"));
assert.ok(signoff.includes("approved_with_warnings"));
assert.ok(signoff.includes('Evaluate the release again before sign-off'));

const rolloutStart=rollouts.indexOf('function client_release_rollout_update_v110');
const rolloutEnd=rollouts.indexOf('function client_release_rollout_sync_legacy_action_v110');
const rollout=rollouts.slice(rolloutStart,rolloutEnd);
assert.match(rollout,/client_release_readiness_current_v150/,'Draft/Testing exposure must require v1.50 readiness');
assert.ok(rollout.includes("in_array($from,['draft','testing'],true)"));
assert.ok(rollout.includes("in_array($state,['canary','limited','general_availability'],true)"));
assert.ok(rollout.includes('Save rollout documentation changes before preflight evaluation and sign-off.'));

assert.match(fleet,/client_release_readiness_current_v150/,'v1.60 maintenance must consume v1.50 readiness when installed');
assert.ok(risk.includes('client_release_risk_assess_v140'));

assert.doesNotMatch(readiness,/shell_exec\s*\(|proc_open\s*\(|passthru\s*\(/,'readiness preflight must not execute client software');

assert.ok(bootstrap.includes('client-release-readiness-v150.php'));
assert.ok(upgrade.includes('client_release_readiness_schema_ready_v150'));
assert.ok(upgrade.includes('client_release_readiness_ensure_schema_v150'));

assert.ok(admin.includes('Release Readiness &amp; Preflight Gates'));
assert.ok(admin.includes('readiness_manifest_update'));
assert.ok(admin.includes('readiness_ci_save'));
assert.ok(admin.includes('readiness_ci_delete'));
assert.ok(admin.includes('readiness_evaluate'));
assert.ok(admin.includes('readiness_signoff'));
assert.ok(admin.includes('Artifact integrity'));
assert.ok(admin.includes('Preflight approval'));

assert.match(workflow,/Client Release Operations v1\.60/,'v1.50 is a backfill beneath the current v1.60 release-ops surface');
assert.ok(workflow.includes('client-release-readiness-v150.php'));
assert.ok(workflow.includes('client-release-readiness-v150.mjs'));
assert.ok(recovery.includes('client-release-readiness-v150.mjs'));

console.log('Client Release Operations v1.50 readiness and preflight contract: PASS');
