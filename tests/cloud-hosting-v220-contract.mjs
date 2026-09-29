import fs from 'node:fs';
import assert from 'node:assert/strict';

const read=p=>fs.readFileSync(p,'utf8');
const releases=read('includes/cloud-hosting-releases-v220.php');
const v120=read('includes/cloud-hosting-v120.php');
const ui=read('includes/cloud-hosting-ui-v140.php');
const agent=read('includes/cloud-hosting-agent-v130.php');
const page=read('hosting.php');
const css=read('cloud-hosting-v140.css');
const bootstrap=read('includes/bootstrap.php');
const workflow=read('.github/workflows/cloud-hosting-v100.yml');

assert.match(releases,/VP3_CLOUD_HOSTING_RELEASES_V220/);
assert.match(releases,/hosting\.deployment\.releases/);
assert.match(releases,/hosting\.deployment\.promote/);
assert.match(releases,/hosting\.deployment\.prune/);
assert.match(releases,/\^release_\[0-9a-f\]\{24\}\$/);
assert.match(releases,/vp3_cloud_hosting_v120_claim_deployment/);
assert.match(releases,/operation'!=='promote'/);
assert.match(releases,/operation'!=='prune'/);
assert.match(releases,/hash\('sha256',\$releaseId\)/);
assert.match(releases,/hash\('sha256','keep:'\.\$keep\)/);
assert.match(releases,/existing_deployment_ledger_reused'=>true/);
assert.match(releases,/homeserver_authoritative_execution'=>true/);
assert.match(releases,/cloud_filesystem_access'=>false/);
assert.match(releases,/cloud_raw_sql'=>false/);
assert.match(releases,/sqlite_schema_rewind_claimed'=>false/);

assert.match(v120,/\['deployed','rolled_back','promoted','pruned','failed'\]/);
assert.match(v120,/'release_history'=>true/);
assert.match(v120,/'historical_release_promotion'=>true/);
assert.match(v120,/'release_retention'=>true/);

assert.match(ui,/deployment\.promote/);
assert.match(ui,/deployment\.prune/);
assert.match(ui,/'release_history'=>true/);
assert.match(ui,/'historical_release_promotion'=>true/);
assert.match(ui,/'release_retention'=>true/);

assert.match(agent,/'deployment\.promote'/);
assert.match(agent,/'deployment\.prune'/);
assert.match(agent,/vp3_cloud_hosting_releases_v220_catalog/);
assert.match(agent,/vp3_cloud_hosting_releases_v220_promote/);
assert.match(agent,/vp3_cloud_hosting_releases_v220_prune/);
assert.match(agent,/inspect release history/);

assert.match(page,/Release History/);
assert.match(page,/deployment\.promote/);
assert.match(page,/deployment\.prune/);
assert.match(page,/Apply Retention/);
assert.match(page,/Promote/);
assert.match(page,/active and previous releases are always protected/i);
assert.match(css,/hosting-release-list/);
assert.match(css,/hosting-release-row/);
assert.match(css,/hosting-retention/);

assert.match(bootstrap,/cloud-hosting-releases-v220\.php/);
assert.match(workflow,/cloud-hosting-releases-v220\.php/);
assert.match(workflow,/cloud-hosting-v220-contract\.mjs/);
assert.match(workflow,/cloud-hosting-v220-mysql\.php/);

console.log('Cloud Hosting V2 Section 2 release history contract: PASS');
