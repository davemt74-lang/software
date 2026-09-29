import fs from 'node:fs';
import assert from 'node:assert/strict';

const read=p=>fs.readFileSync(p,'utf8');
const release=read('includes/cloud-hosting-release-v150.php');
const acceptance=read('tests/cloud-hosting-v150-acceptance.php');
const bootstrap=read('includes/bootstrap.php');
const upgrade=read('upgrade.php');
const workflow=read('.github/workflows/cloud-hosting-v100.yml');

assert.match(release,/VP3_CLOUD_HOSTING_RELEASE_V150/);
assert.match(release,/vp3_cloud_hosting_v150_versions/);
assert.match(release,/vp3_cloud_hosting_v150_schema_ready/);
assert.match(release,/vp3_cloud_hosting_v150_readiness/);
assert.match(release,/vp3_cloud_hosting_v150_public_capability/);
for(const version of ['VP3_CLOUD_HOSTING_V100','VP3_CLOUD_HOSTING_V110','VP3_CLOUD_HOSTING_V120','VP3_CLOUD_HOSTING_AGENT_V130','VP3_CLOUD_HOSTING_UI_V140']){
  assert.ok(release.includes(version),`release version map missing ${version}`);
}
for(const capability of [
  'control_plane','package_entitlements','cpanel_dns','cloud_edge_tls','homeserver_reconciliation',
  'chunked_deployment','deployment_resume','rollback','agent_chat_controls','member_hosting_ui',
  'server_confirmed_consequential_ui_actions','agent_confirmed_consequential_actions',
  'secret_redaction','non_destructive_package_downgrade','cloud_is_desired_state_authority',
  'homeserver_is_execution_authority'
]){
  assert.ok(release.includes("'"+capability+"'=>true"),`missing release capability ${capability}`);
}
assert.match(release,/parallel_hosting_engine'=>false/);
assert.match(release,/raw_cpanel_secret_exposed'=>false/);
assert.match(release,/route_token_exposed'=>false/);
assert.match(release,/cloud_edge_private_key_exposed'=>false/);
assert.doesNotMatch(release,/CREATE TABLE|INSERT INTO|UPDATE cloud_hosting|DELETE FROM/);
assert.doesNotMatch(release,/hosting_cpanel_api_token|route_token_enc|BEGIN PRIVATE KEY|private_key\s+(?:LONGTEXT|TEXT|VARCHAR)|certificate_key\s+(?:LONGTEXT|TEXT|VARCHAR)/);

assert.match(acceptance,/cloud-hosting-v140-mysql\.php/);
assert.match(acceptance,/cloud-hosting-agent-v130\.php/);
assert.match(acceptance,/site\.activate/);
assert.match(acceptance,/suspend hosted site/);
assert.match(acceptance,/deployment\.deploy/);
assert.match(acceptance,/Package downgrade did not block new hosted-site creation/);
assert.match(acceptance,/Package downgrade deleted hosted-site data/);
assert.match(acceptance,/Release readiness leaked another user/);
assert.match(acceptance,/Cloud Hosting V1 Section 6 end-to-end acceptance: PASS/);

assert.match(bootstrap,/cloud-hosting-release-v150\.php/);
assert.match(upgrade,/vp3_cloud_hosting_v150_schema_ready\(\)/);
assert.match(workflow,/cloud-hosting-v150-acceptance\.php/);
assert.match(workflow,/cloud-hosting-v150-contract\.mjs/);
assert.match(workflow,/cloud-hosting-release-v150\.php/);

console.log('Cloud Hosting V1 Section 6 release contract: PASS');
